<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEmailParsedAttachmentJob;
use App\Models\Client\EmailParseAuditLog;
use App\Models\Client\EmailParsedApplication;
use App\Models\Client\EmailParsedAttachment;
use App\Services\EmailParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmailParserController extends Controller
{
    /**
     * POST /email-parser/scan — Trigger manual inbox scan for current user.
     */
    public function scan(Request $request)
    {
        $conn = $this->tenantDb($request);
        $userId = (int) $request->auth->id;
        $clientId = $this->tenantId($request);

        try {
            $service = new EmailParserService();
            $count = $service->scanInbox($userId, $clientId, $request->input('query'));

            return $this->successResponse("Scan complete. Found {$count} new PDF attachment(s).", [
                'new_attachments' => $count,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Scan failed.', [], $e);
        }
    }

    /**
     * GET /email-parser/status — Dashboard stats.
     */
    public function status(Request $request)
    {
        $conn = $this->tenantDb($request);

        $byStatus = EmailParsedAttachment::on($conn)
            ->selectRaw('parse_status, COUNT(*) as count')
            ->groupBy('parse_status')
            ->pluck('count', 'parse_status')
            ->toArray();

        $byDocType = EmailParsedAttachment::on($conn)
            ->selectRaw('doc_type, COUNT(*) as count')
            ->groupBy('doc_type')
            ->pluck('count', 'doc_type')
            ->toArray();

        $totalApplications = EmailParsedApplication::on($conn)->count();
        $pendingReview = EmailParsedApplication::on($conn)
            ->whereIn('status', ['parsed', 'review'])
            ->whereNull('lead_id')
            ->count();
        $leadsCreated = EmailParsedApplication::on($conn)
            ->where('status', 'lead_created')
            ->count();

        return $this->successResponse('Status retrieved.', [
            'by_parse_status'    => $byStatus,
            'by_doc_type'        => $byDocType,
            'total_attachments'  => array_sum($byStatus),
            'total_applications' => $totalApplications,
            'pending_review'     => $pendingReview,
            'leads_created'      => $leadsCreated,
        ]);
    }

    /**
     * GET /email-parser/attachments — Paginated list with filters.
     */
    public function attachments(Request $request)
    {
        $conn = $this->tenantDb($request);
        $perPage = min((int) ($request->input('per_page', 20)), 100);

        $query = EmailParsedAttachment::on($conn)->orderByDesc('created_at');

        if ($docType = $request->input('doc_type')) {
            $query->where('doc_type', $docType);
        }
        if ($status = $request->input('parse_status')) {
            $query->where('parse_status', $status);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('filename', 'like', "%{$search}%")
                  ->orWhere('email_from', 'like', "%{$search}%")
                  ->orWhere('email_subject', 'like', "%{$search}%");
            });
        }

        $paginated = $query->paginate($perPage);

        return $this->successResponse('Attachments retrieved.', [
            'attachments' => $paginated->items(),
            'total'       => $paginated->total(),
            'page'        => $paginated->currentPage(),
            'per_page'    => $paginated->perPage(),
            'last_page'   => $paginated->lastPage(),
        ]);
    }

    /**
     * GET /email-parser/attachments/{id} — Single attachment detail.
     */
    public function showAttachment(Request $request, $id)
    {
        $conn = $this->tenantDb($request);
        $attachment = EmailParsedAttachment::on($conn)->with('application')->find((int) $id);

        if (!$attachment) {
            return $this->failResponse('Attachment not found.', [], null, 404);
        }

        return $this->successResponse('Attachment retrieved.', ['attachment' => $attachment->toArray()]);
    }

    /**
     * POST /email-parser/attachments/{id}/reclassify — Manual doc_type change.
     */
    public function reclassifyAttachment(Request $request, $id)
    {
        $this->validate($request, [
            'doc_type' => 'required|in:application,bank_statement,void_cheque,invoice,unknown',
        ]);

        $conn = $this->tenantDb($request);
        $attachment = EmailParsedAttachment::on($conn)->find((int) $id);

        if (!$attachment) {
            return $this->failResponse('Attachment not found.', [], null, 404);
        }

        $oldType = $attachment->doc_type;
        $attachment->doc_type = $request->input('doc_type');
        $attachment->classification_method = 'manual';
        $attachment->classification_confidence = 100;
        $attachment->save();

        EmailParseAuditLog::log(
            $conn, (int) $request->auth->id, 'manual_reclassify',
            'attachment', $attachment->id, $attachment->gmail_message_id,
            ['old_type' => $oldType, 'new_type' => $attachment->doc_type],
            $request->ip()
        );

        return $this->successResponse('Document reclassified.', ['attachment' => $attachment->toArray()]);
    }

    /**
     * POST /email-parser/attachments/{id}/reparse — Re-dispatch parse job.
     */
    public function reparseAttachment(Request $request, $id)
    {
        $conn = $this->tenantDb($request);
        $attachment = EmailParsedAttachment::on($conn)->find((int) $id);

        if (!$attachment) {
            return $this->failResponse('Attachment not found.', [], null, 404);
        }

        $attachment->parse_status = 'pending';
        $attachment->error_message = null;
        $attachment->save();

        dispatch(new ProcessEmailParsedAttachmentJob(
            $attachment->id,
            (int) $request->auth->id,
            $this->tenantId($request)
        ));

        EmailParseAuditLog::log(
            $conn, (int) $request->auth->id, 'reparse_triggered',
            'attachment', $attachment->id, $attachment->gmail_message_id,
            null, $request->ip()
        );

        return $this->successResponse('Re-parse job dispatched.', ['attachment' => $attachment->toArray()]);
    }

    /**
     * GET /email-parser/attachments/{id}/download — Download stored PDF.
     */
    public function downloadAttachment(Request $request, $id)
    {
        $conn = $this->tenantDb($request);
        $attachment = EmailParsedAttachment::on($conn)->find((int) $id);

        if (!$attachment || !$attachment->local_path) {
            return $this->failResponse('File not found.', [], null, 404);
        }

        $fullPath = Storage::disk('local')->path($attachment->local_path);
        if (!file_exists($fullPath)) {
            return $this->failResponse('File not found on disk.', [], null, 404);
        }

        return response()->download($fullPath, $attachment->filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * GET /email-parser/applications — Paginated parsed applications.
     */
    public function applications(Request $request)
    {
        $conn = $this->tenantDb($request);
        $perPage = min((int) ($request->input('per_page', 20)), 100);

        $query = EmailParsedApplication::on($conn)->orderByDesc('created_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                  ->orWhere('owner_first_name', 'like', "%{$search}%")
                  ->orWhere('owner_last_name', 'like', "%{$search}%")
                  ->orWhere('owner_email', 'like', "%{$search}%");
            });
        }

        $paginated = $query->paginate($perPage);

        return $this->successResponse('Applications retrieved.', [
            'applications' => $paginated->items(),
            'total'        => $paginated->total(),
            'page'         => $paginated->currentPage(),
            'per_page'     => $paginated->perPage(),
            'last_page'    => $paginated->lastPage(),
        ]);
    }

    /**
     * GET /email-parser/applications/{id} — Full application detail.
     */
    public function showApplication(Request $request, $id)
    {
        $conn = $this->tenantDb($request);
        $app = EmailParsedApplication::on($conn)->with('attachment')->find((int) $id);

        if (!$app) {
            return $this->failResponse('Application not found.', [], null, 404);
        }

        return $this->successResponse('Application retrieved.', ['application' => $app->toArray()]);
    }

    /**
     * DELETE /email-parser/applications/{id} — Delete parsed application.
     */
    public function deleteApplication(Request $request, $id)
    {
        $conn = $this->tenantDb($request);
        $app = EmailParsedApplication::on($conn)->find((int) $id);

        if (!$app) {
            return $this->failResponse('Application not found.', [], null, 404);
        }

        $app->delete();

        return $this->successResponse('Application deleted.');
    }

    /**
     * GET /email-parser/applications/{id}/pdf — Download source PDF for an application.
     */
    public function applicationPdf(Request $request, $id)
    {
        $conn = $this->tenantDb($request);
        $app = EmailParsedApplication::on($conn)->find((int) $id);

        if (!$app) {
            return $this->failResponse('Application not found.', [], null, 404);
        }

        $attachment = EmailParsedAttachment::on($conn)->find($app->attachment_id);
        if (!$attachment || !$attachment->local_path) {
            return $this->failResponse('Source PDF not found.', [], null, 404);
        }

        $fullPath = Storage::disk('local')->path($attachment->local_path);
        if (!file_exists($fullPath)) {
            return $this->failResponse('File not found on disk.', [], null, 404);
        }

        return response()->download($fullPath, $attachment->filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * GET /email-parser/available-applications — Apps available for lead creation.
     */
    public function availableApplications(Request $request)
    {
        $conn = $this->tenantDb($request);

        $apps = EmailParsedApplication::on($conn)
            ->whereNull('lead_id')
            ->whereIn('status', ['parsed', 'review'])
            ->orderByDesc('created_at')
            ->get();

        return $this->successResponse('Available applications retrieved.', [
            'applications' => $apps->toArray(),
        ]);
    }

    /**
     * POST /email-parser/create-lead — Create CRM lead from parsed application.
     */
    public function createLead(Request $request)
    {
        $this->validate($request, [
            'application_id' => 'required|integer',
        ]);

        $clientId = $this->tenantId($request);
        $userId = (int) $request->auth->id;
        $applicationId = (int) $request->input('application_id');
        $overrides = $request->input('overrides', []);

        try {
            $service = new EmailParserService();
            $leadId = $service->createLeadFromApplication($applicationId, $clientId, $userId, $overrides);

            return $this->successResponse('Lead created successfully.', [
                'lead_id' => $leadId,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to create lead.', [], $e);
        }
    }

    /**
     * GET /email-parser/audit-log — Paginated audit log.
     */
    public function auditLog(Request $request)
    {
        $conn = $this->tenantDb($request);
        $perPage = min((int) ($request->input('per_page', 20)), 100);

        $query = EmailParseAuditLog::on($conn)->orderByDesc('created_at');

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }

        $paginated = $query->paginate($perPage);

        return $this->successResponse('Audit log retrieved.', [
            'entries'  => $paginated->items(),
            'total'    => $paginated->total(),
            'page'     => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'last_page' => $paginated->lastPage(),
        ]);
    }
}

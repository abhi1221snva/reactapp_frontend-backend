<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Model\Client\CrmLeadActivity;
use App\Model\User;

/**
 * @OA\Get(
 *   path="/crm/lead/{id}/documents",
 *   tags={"CRM"},
 *   summary="List all documents for a lead",
 *   security={{"bearerAuth":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, description="Lead ID", @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Documents list"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/crm/lead/{id}/documents",
 *   tags={"CRM"},
 *   summary="Upload documents for a lead",
 *   security={{"bearerAuth":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, description="Lead ID", @OA\Schema(type="integer")),
 *   @OA\RequestBody(required=true,
 *     @OA\MediaType(mediaType="multipart/form-data",
 *       @OA\Schema(
 *         required={"files","document_type"},
 *         @OA\Property(property="files[]", type="array", @OA\Items(type="string", format="binary"), description="1–10 files, max 20MB each (pdf,doc,docx,xls,xlsx,jpg,jpeg,png)"),
 *         @OA\Property(property="document_type", type="string", example="Contract")
 *       )
 *     )
 *   ),
 *   @OA\Response(response=200, description="Documents uploaded"),
 *   @OA\Response(response=422, description="Validation error or all uploads failed"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Delete(
 *   path="/crm/lead/{id}/documents/{did}",
 *   tags={"CRM"},
 *   summary="Soft-delete a document",
 *   security={{"bearerAuth":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, description="Lead ID", @OA\Schema(type="integer")),
 *   @OA\Parameter(name="did", in="path", required=true, description="Document ID", @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Document deleted"),
 *   @OA\Response(response=404, description="Document not found"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 */
class CrmDocumentController extends Controller
{
    /**
     * GET /crm/lead/{id}/documents
     * List all documents for a lead.
     */
    public function index(Request $request, int $id)
    {
        if ($err = $this->assertLeadAccessById($request, $id)) return $err;
        $clientId = $request->auth->parent_id;
        try {
            $docs = DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->where('lead_id', $id)
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->get();

            // Resolve uploader names
            $uploaderIds = $docs->pluck('uploaded_by')->filter()->unique()->toArray();
            $uploaders   = [];
            if (!empty($uploaderIds)) {
                $uploaders = User::whereIn('id', $uploaderIds)
                    ->get(['id', 'first_name', 'last_name'])
                    ->keyBy('id')
                    ->toArray();
            }

            $result = $docs->map(function ($doc) use ($uploaders) {
                $d = (array) $doc;
                $uid = $d['uploaded_by'] ?? null;
                if ($uid && isset($uploaders[$uid])) {
                    $u = $uploaders[$uid];
                    $d['uploaded_by_name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                }
                $d['file_name']  = !empty($d['file_name']) ? $d['file_name'] : (!empty($d['file_path']) ? basename($d['file_path']) : '');
                $d['attachable'] = !empty($d['file_path']);
                return $d;
            })->values();

            return $this->successResponse("Documents fetched", $result->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to fetch documents", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead/{id}/documents
     * Upload one or more documents for a lead.
     *
     * Accepts:
     *   files[]       — 1–10 files, each max 20 MB
     *   document_type — document category label
     *
     * Allowed types: pdf, doc, docx, xls, xlsx, jpg, jpeg, png
     */
    public function store(Request $request, int $id)
    {
        if ($err = $this->assertLeadAccessById($request, $id)) return $err;
        $clientId = $request->auth->parent_id;

        // Support both:
        //  - document_type (string)  — legacy single-type batch
        //  - document_type[] (array) — new per-file types matching files[] order
        //  - sub_type / sub_type[] — optional per-file sub category (e.g. "January" for Bank Statement)
        $rawType    = $request->input('document_type');
        $rawSubType = $request->input('sub_type');
        $rules = [
            'files'   => 'required|array|min:1|max:10',
            'files.*' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png|max:20480',
        ];
        if (is_array($rawType)) {
            $rules['document_type']   = 'required|array';
            $rules['document_type.*'] = 'required|string|max:100';
        } else {
            $rules['document_type'] = 'required|string|max:100';
        }
        if (is_array($rawSubType)) {
            $rules['sub_type']   = 'nullable|array';
            $rules['sub_type.*'] = 'nullable|string|max:100';
        } else {
            $rules['sub_type'] = 'nullable|string|max:100';
        }
        $this->validate($request, $rules);

        $files = $request->file('files');
        $types = is_array($rawType)
            ? array_values($rawType)
            : array_fill(0, count($files), $rawType);

        // Normalize sub_type to a per-file array; tolerate empty/missing.
        if (is_array($rawSubType)) {
            $subTypes = array_values($rawSubType);
        } elseif (!is_null($rawSubType) && $rawSubType !== '') {
            $subTypes = array_fill(0, count($files), $rawSubType);
        } else {
            $subTypes = array_fill(0, count($files), null);
        }
        // Pad sub_types array if shorter than files (defensive)
        while (count($subTypes) < count($files)) {
            $subTypes[] = null;
        }

        if (count($types) !== count($files)) {
            return $this->failResponse("document_type count must match files count", [], null, 422);
        }

        $uploaded  = [];
        $failed    = [];
        $now       = Carbon::now();

        foreach ($files as $i => $file) {
            $docType = $types[$i] ?? 'Other';
            $subType = isset($subTypes[$i]) && $subTypes[$i] !== '' ? $subTypes[$i] : null;
            try {
                $origName  = $file->getClientOriginalName();
                $safeName  = time() . '_' . mt_rand(100, 999) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                $path      = "crm_documents/client_{$clientId}/lead_{$id}/{$safeName}";

                Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
                $publicPath = Storage::disk('public')->url($path);

                $docId = DB::connection("mysql_$clientId")
                    ->table('crm_documents')
                    ->insertGetId([
                        'lead_id'       => $id,
                        'document_type' => $docType,
                        'sub_type'      => $subType,
                        'file_name'     => substr($origName, 0, 50),
                        'file_path'     => $publicPath,
                        'uploaded_by'   => $request->auth->id,
                        'file_size'     => $file->getSize(),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);

                $uploaded[] = [
                    'id'            => $docId,
                    'lead_id'       => $id,
                    'document_type' => $docType,
                    'sub_type'      => $subType,
                    'file_path'     => $publicPath,
                    'file_name'     => $origName,
                    'file_size'     => $file->getSize(),
                    'uploaded_by'   => $request->auth->id,
                    'created_at'    => $now->toDateTimeString(),
                ];
            } catch (\Throwable $e) {
                Log::error('CrmDocument upload failed for file', [
                    'file'  => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);
                $failed[] = $file->getClientOriginalName();
            }
        }

        // Single activity log entry for the batch
        if (!empty($uploaded)) {
            try {
                $uniqueTypes = array_values(array_unique(array_column($uploaded, 'document_type')));
                $typesLabel  = implode(', ', $uniqueTypes);
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = $id;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = 'document_uploaded';
                $activity->subject       = count($uploaded) === 1
                    ? "Document uploaded: {$uploaded[0]['document_type']} ({$uploaded[0]['file_name']})"
                    : count($uploaded) . " documents uploaded: {$typesLabel}";
                $activity->meta          = [
                    'document_types' => $uniqueTypes,
                    'files'          => array_column($uploaded, 'file_name'),
                    'count'          => count($uploaded),
                ];
                $activity->source_type   = 'api';
                $activity->save();
            } catch (\Throwable $e) {}
        }

        if (empty($uploaded)) {
            return $this->failResponse("All uploads failed", $failed, null, 422);
        }

        $message = count($uploaded) . ' document(s) uploaded successfully';
        if (!empty($failed)) {
            $message .= '. Failed: ' . implode(', ', $failed);
        }

        return $this->successResponse($message, [
            'uploaded' => $uploaded,
            'failed'   => $failed,
            'count'    => count($uploaded),
        ]);
    }

    /**
     * GET /crm/lead/{id}/documents/{did}/view
     * Stream a document inline for iframe/browser preview.
     */
    public function view(Request $request, int $id, int $did)
    {
        if ($err = $this->assertLeadAccessById($request, $id)) return $err;
        $clientId = $request->auth->parent_id;
        try {
            $doc = DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->where('id', $did)
                ->where('lead_id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$doc) {
                return $this->failResponse("Document not found", [], null, 404);
            }

            $absPath = $this->resolveStoragePath($doc->file_path ?? '');
            if (!$absPath) {
                return $this->failResponse("File not found on server", [], null, 404);
            }

            $mimeType = mime_content_type($absPath) ?: 'application/octet-stream';
            $fileName = basename($absPath);

            return response()->file($absPath, [
                'Content-Type'        => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $fileName . '"',
                'X-Frame-Options'     => 'SAMEORIGIN',
                'Cache-Control'       => 'private, no-store',
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to stream document", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/lead/{id}/documents/{did}/download
     * Force-download with filename: first_last_doctype.ext
     */
    public function download(Request $request, int $id, int $did)
    {
        if ($err = $this->assertLeadAccessById($request, $id)) return $err;
        $clientId = $request->auth->parent_id;
        try {
            $doc = DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->where('id', $did)
                ->where('lead_id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$doc) {
                return $this->failResponse("Document not found", [], null, 404);
            }

            // Fetch lead name from EAV values table
            $firstName = DB::connection("mysql_$clientId")
                ->table('crm_lead_values')
                ->where('lead_id', $id)
                ->where('field_key', 'first_name')
                ->value('field_value') ?? '';

            $lastName = DB::connection("mysql_$clientId")
                ->table('crm_lead_values')
                ->where('lead_id', $id)
                ->where('field_key', 'last_name')
                ->value('field_value') ?? '';

            // Build clean download filename: john_doe_contract.pdf
            $clean     = fn(string $s): string => preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($s)));
            $nameParts = array_filter([$clean($firstName), $clean($lastName)]);
            $docType   = $clean($doc->document_type ?? 'document');
            $ext       = strtolower(pathinfo($doc->file_path ?? '', PATHINFO_EXTENSION)) ?: 'pdf';
            $baseName  = (empty($nameParts) ? "lead_{$id}" : implode('_', $nameParts))
                         . '_' . $docType . '.' . $ext;

            $absPath = $this->resolveStoragePath($doc->file_path ?? '');
            if (!$absPath) {
                return $this->failResponse("File not found on server", [], null, 404);
            }

            return response()->download($absPath, $baseName, [
                'Content-Type'  => mime_content_type($absPath) ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to download document", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * Resolve a stored file_path (public URL) to an absolute filesystem path.
     * Stored format: APP_URL/storage/relative/path/file.pdf
     */
    private function resolveStoragePath(string $fileUrl): ?string
    {
        if (empty($fileUrl)) return null;

        $appUrl = rtrim(config('app.url'), '/');
        $prefix = $appUrl . '/storage/';

        if (!str_starts_with($fileUrl, $prefix)) {
            return null; // External URL — cannot serve directly
        }

        $relative = substr($fileUrl, strlen($prefix));

        if (!Storage::disk('public')->exists($relative)) {
            return null;
        }

        return Storage::disk('public')->path($relative);
    }

    /**
     * PATCH /crm/lead/{id}/documents/{did}
     * Update document metadata (tag, document_type).
     */
    public function update(Request $request, int $id, int $did)
    {
        if ($err = $this->assertLeadAccessById($request, $id)) return $err;
        $clientId = $request->auth->parent_id;

        $this->validate($request, [
            'tag'           => 'nullable|string|max:50',
            'document_type' => 'nullable|string|max:100',
        ]);

        try {
            $doc = DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->where('id', $did)
                ->where('lead_id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (!$doc) {
                return $this->failResponse("Document not found", [], null, 404);
            }

            $update = ['updated_at' => Carbon::now()];
            if ($request->has('tag'))              $update['tag']           = $request->input('tag');
            if ($request->filled('document_type')) $update['document_type'] = $request->input('document_type');

            DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->where('id', $did)
                ->update($update);

            $fresh = DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->where('id', $did)
                ->first();

            return $this->successResponse("Document updated", (array) $fresh);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to update document", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead/{id}/documents/bulk
     * Bulk actions on multiple documents. Supports:
     *   action=delete       — soft-delete all
     *   action=tag  + tag=x — update tag on all
     */
    public function bulkAction(Request $request, int $id)
    {
        if ($err = $this->assertLeadAccessById($request, $id)) return $err;
        $clientId = $request->auth->parent_id;

        $this->validate($request, [
            'action'    => 'required|in:delete,tag',
            'doc_ids'   => 'required|array|min:1',
            'doc_ids.*' => 'integer',
            'tag'       => 'nullable|string|max:50',
        ]);

        $action  = $request->input('action');
        $docIds  = $request->input('doc_ids');

        try {
            $query = DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->whereIn('id', $docIds)
                ->where('lead_id', $id)
                ->whereNull('deleted_at');

            if ($action === 'delete') {
                $affected = $query->update(['deleted_at' => Carbon::now()]);
                return $this->successResponse("Deleted {$affected} document(s)", ['count' => $affected]);
            }

            if ($action === 'tag') {
                $affected = $query->update([
                    'tag'        => $request->input('tag'),
                    'updated_at' => Carbon::now(),
                ]);
                return $this->successResponse("Tagged {$affected} document(s)", ['count' => $affected]);
            }

            return $this->failResponse("Unknown action", [], null, 422);
        } catch (\Throwable $e) {
            return $this->failResponse("Bulk action failed", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * DELETE /crm/lead/{id}/documents/{did}
     * Soft-delete a document.
     */
    public function destroy(Request $request, int $id, int $did)
    {
        if ($err = $this->assertLeadAccessById($request, $id)) return $err;
        $clientId = $request->auth->parent_id;
        try {
            $doc = DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->where('id', $did)
                ->where('lead_id', $id)
                ->first();

            if (!$doc) {
                return $this->failResponse("Document not found", [], null, 404);
            }

            DB::connection("mysql_$clientId")
                ->table('crm_documents')
                ->where('id', $did)
                ->update(['deleted_at' => Carbon::now()]);

            // Log activity
            try {
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = $id;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = 'system';
                $activity->subject       = 'Document deleted: ' . basename($doc->file_path ?? '');
                $activity->source_type   = 'api';
                $activity->save();
            } catch (\Throwable $e) {}

            return $this->successResponse("Document deleted");
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to delete document", [$e->getMessage()], $e, 500);
        }
    }
}

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
                $d['file_name']  = !empty($d['file_path']) ? basename($d['file_path']) : ($d['file_name'] ?? '');
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
        $clientId = $request->auth->parent_id;

        $this->validate($request, [
            'files'         => 'required|array|min:1|max:10',
            'files.*'       => 'required|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png|max:20480',
            'document_type' => 'required|string|max:100',
        ]);

        $docType   = $request->input('document_type', 'Other');
        $files     = $request->file('files');
        $uploaded  = [];
        $failed    = [];
        $now       = Carbon::now();

        foreach ($files as $file) {
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
                $names = implode(', ', array_column($uploaded, 'file_name'));
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = $id;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = 'document_uploaded';
                $activity->subject       = count($uploaded) === 1
                    ? "Document uploaded: {$docType} ({$uploaded[0]['file_name']})"
                    : count($uploaded) . " documents uploaded: {$docType}";
                $activity->meta          = json_encode([
                    'document_type' => $docType,
                    'files'         => array_column($uploaded, 'file_name'),
                    'count'         => count($uploaded),
                ]);
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
     * DELETE /crm/lead/{id}/documents/{did}
     * Soft-delete a document.
     */
    public function destroy(Request $request, int $id, int $did)
    {
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

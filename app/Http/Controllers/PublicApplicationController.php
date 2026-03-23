<?php

namespace App\Http\Controllers;

use App\Services\PublicApplicationService;
use App\Services\LeadPdfService;
use Illuminate\Http\Request;

/**
 * PublicApplicationController
 *
 * Handles affiliate apply form + merchant portal.
 * ALL routes in this controller are PUBLIC — no JWT auth required.
 */
class PublicApplicationController extends Controller
{
    private PublicApplicationService $svc;
    private LeadPdfService $pdfSvc;

    public function __construct(PublicApplicationService $svc, LeadPdfService $pdfSvc)
    {
        $this->svc    = $svc;
        $this->pdfSvc = $pdfSvc;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AFFILIATE APPLY FORM
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /public/apply/{code}
     * Returns company branding + dynamic form sections.
     */
    public function getApplyForm(Request $request, string $code)
    {
        try {
            [$user, $clientId, $client] = $this->svc->resolveAffiliate($code);

            return response()->json([
                'success' => true,
                'data'    => [
                    'company'        => $this->svc->getCompanyBranding($client, $clientId),
                    'sections'       => $this->svc->getFormSections($clientId),
                    'affiliate_user' => [
                        'name'  => trim($user->first_name . ' ' . $user->last_name),
                        'email' => $user->email,
                    ],
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load application form.'], 500);
        }
    }

    /**
     * POST /public/apply/{code}
     * Creates a new lead from the affiliate application form.
     * Accepts JSON body with optional signature_image (base64 PNG).
     * Returns lead_token, merchant_url, pdf_url.
     */
    public function submitApplication(Request $request, string $code)
    {
        // Normalize phone field — CRM dynamic fields may use phone_number, phone,
        // cell_phone, etc. instead of 'mobile'. Copy the first non-empty match.
        if (!$request->filled('mobile')) {
            foreach (['phone_number', 'phone', 'cell_phone', 'telephone', 'cell'] as $alt) {
                if ($request->filled($alt)) {
                    $request->merge(['mobile' => $request->input($alt)]);
                    break;
                }
            }
        }

        $this->validate($request, [
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'email'           => 'required|email|max:255',
            'mobile'          => 'nullable|string|max:30',
            'signature_image' => 'nullable|string',
        ]);

        try {
            [$user, $clientId] = $this->svc->resolveAffiliate($code);

            $formData = $request->except(['_token', '_method']);
            $result   = $this->svc->createLead($clientId, $user, $formData);

            return response()->json([
                'success'       => true,
                'message'       => 'Application submitted successfully!',
                'lead_token'    => $result['lead_token'],
                'merchant_url'  => $result['merchant_url'],
                'lead_id'       => $result['lead_id'],
                'signature_url' => $result['signature_url'],
                'pdf_url'       => $result['pdf_url'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            \Log::error('Public apply submit: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['success' => false, 'message' => 'Failed to submit application.'], 500);
        }
    }

    /**
     * GET /public/apply/{token}/pdf
     * Render a printable HTML application summary.
     * {token} here is the lead_token (returned after submission).
     */
    public function renderApplicationPdf(Request $request, string $token)
    {
        try {
            [$lead, $clientId, $client] = $this->svc->resolveLeadToken($token);

            $company  = $this->svc->getCompanyBranding($client, $clientId);
            $sections = $this->svc->getFormSections($clientId, true);
            $html     = $this->svc->generateApplicationHtml($clientId, $lead, $sections, $company);

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('X-Frame-Options', 'SAMEORIGIN');
        } catch (\RuntimeException $e) {
            return response('<h1>Not Found</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>', 404)
                ->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            return response('<h1>Error</h1><p>Unable to generate PDF.</p>', 500)
                ->header('Content-Type', 'text/html');
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MERCHANT PORTAL
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /public/merchant/{token}
     * Returns company branding + lead data + form sections.
     */
    public function getMerchantPortal(Request $request, string $token)
    {
        try {
            [$lead, $clientId, $client] = $this->svc->resolveLeadToken($token);

            return response()->json([
                'success' => true,
                'data'    => [
                    'company'  => $this->svc->getCompanyBranding($client, $clientId),
                    'lead'     => $this->svc->getMerchantLeadData($lead, $clientId),
                    'sections' => $this->svc->getFormSections($clientId, true),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load merchant portal.'], 500);
        }
    }

    /**
     * GET /public/merchant/{token}/render-pdf
     *
     * Renders the SAME signature_application PDF template used in the CRM
     * (LeadController::renderPdf) but authenticated by lead token instead of JWT.
     * Returns full rendered HTML — identical to the CRM version.
     */
    public function renderMerchantPdf(Request $request, string $token)
    {
        try {
            [$lead, $clientId] = $this->svc->resolveLeadToken($token);

            $result = $this->pdfSvc->renderPdfHtml($clientId, $lead->id);

            return response($result['html'], 200)
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('X-Frame-Options', 'SAMEORIGIN');
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 400;
            return response('<h1>' . ($code === 404 ? 'Not Found' : 'Error') . '</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>', $code)
                ->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            return response('<h1>Error</h1><p>Unable to generate PDF.</p>', 500)
                ->header('Content-Type', 'text/html');
        }
    }

    /**
     * POST /public/merchant/{token}
     * Update lead fields from merchant portal.
     */
    public function updateMerchant(Request $request, string $token)
    {
        try {
            [$lead, $clientId] = $this->svc->resolveLeadToken($token);
            $this->svc->updateMerchantLead($lead, $clientId, $request->all());

            return response()->json(['success' => true, 'message' => 'Application updated successfully.']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update application.'], 500);
        }
    }

    /**
     * GET /public/apply/{token}/download
     * Download the affiliate application as a PDF file (Content-Disposition: attachment).
     * Falls back to the built-in apply-form HTML→PDF if no CRM template is configured.
     */
    public function downloadApplicationPdf(Request $request, string $token)
    {
        try {
            [$lead, $clientId, $client] = $this->svc->resolveLeadToken($token);

            // Try CRM template first (same as merchant render-pdf)
            try {
                $result   = $this->pdfSvc->renderPdfBinary($clientId, $lead->id);
                $filename = $result['filename'];
                $binary   = $result['pdf'];
            } catch (\RuntimeException $e) {
                if ($e->getCode() !== 404) throw $e;

                // Fallback: built-in apply-form template
                $company  = $this->svc->getCompanyBranding($client, $clientId);
                $sections = $this->svc->getFormSections($clientId, true);
                $html     = $this->svc->generateApplicationHtml($clientId, $lead, $sections, $company);
                $binary   = $this->pdfSvc->htmlToPdfBytes($html);
                $filename = 'application.pdf';
            }

            return response($binary, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
                'Content-Length'      => strlen($binary),
                'Cache-Control'       => 'no-store',
            ]);
        } catch (\RuntimeException $e) {
            return response('<h1>Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>', $e->getCode() ?: 400)
                ->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            \Log::error('downloadApplicationPdf: ' . $e->getMessage());
            return response('<h1>Error</h1><p>Unable to generate PDF.</p>', 500)
                ->header('Content-Type', 'text/html');
        }
    }

    /**
     * GET /public/merchant/{token}/download
     * Download the merchant application PDF (same template as render-pdf, forced download).
     * Falls back to the built-in apply-form HTML→PDF if no CRM template is configured.
     */
    public function downloadMerchantPdf(Request $request, string $token)
    {
        try {
            [$lead, $clientId, $client] = $this->svc->resolveLeadToken($token);

            try {
                $result   = $this->pdfSvc->renderPdfBinary($clientId, $lead->id);
                $filename = $result['filename'];
                $binary   = $result['pdf'];
            } catch (\RuntimeException $e) {
                if ($e->getCode() !== 404) throw $e;

                // Fallback: built-in apply-form template
                $company  = $this->svc->getCompanyBranding($client, $clientId);
                $sections = $this->svc->getFormSections($clientId, true);
                $html     = $this->svc->generateApplicationHtml($clientId, $lead, $sections, $company);
                $binary   = $this->pdfSvc->htmlToPdfBytes($html);
                $filename = 'application.pdf';
            }

            return response($binary, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
                'Content-Length'      => strlen($binary),
                'Cache-Control'       => 'no-store',
            ]);
        } catch (\RuntimeException $e) {
            return response('<h1>Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>', $e->getCode() ?: 400)
                ->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            \Log::error('downloadMerchantPdf: ' . $e->getMessage());
            return response('<h1>Error</h1><p>Unable to generate PDF.</p>', 500)
                ->header('Content-Type', 'text/html');
        }
    }

    /**
     * GET /public/lead/{token}/signature
     * Serve the lead's signature image (no auth — validated by lead_token ownership).
     */
    public function serveSignature(Request $request, string $token)
    {
        try {
            [$lead, $clientId] = $this->svc->resolveLeadToken($token);
            [$absPath, $mime]  = $this->svc->serveLeadSignature($clientId, $lead->id);

            return response()->file($absPath, [
                'Content-Type'  => $mime,
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (\RuntimeException $e) {
            return response('<h1>Not Found</h1>', 404)->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            return response('<h1>Error</h1>', 500)->header('Content-Type', 'text/html');
        }
    }

    /**
     * POST /public/merchant/{token}/signature
     * Save or replace the signature from the merchant portal.
     * Accepts { signature_image: "data:image/png;base64,..." }
     */
    public function saveMerchantSignature(Request $request, string $token)
    {
        $this->validate($request, [
            'signature_image' => 'required|string|min:50',
        ]);

        try {
            [$lead, $clientId] = $this->svc->resolveLeadToken($token);
            $conn   = "mysql_{$clientId}";
            $result = $this->svc->storeSignature($clientId, $lead->id, $request->input('signature_image'), $conn);

            if (!$result) {
                return response()->json(['success' => false, 'message' => 'Failed to process signature image.'], 422);
            }

            // Return the backend-served URL (not the data URI) for immediate display
            $serveUrl = rtrim(env('APP_URL'), '/') . '/public/lead/' . $lead->lead_token . '/signature';

            return response()->json([
                'success'       => true,
                'message'       => 'Signature saved.',
                'signature_url' => $serveUrl,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to save signature.'], 500);
        }
    }

    /**
     * GET /public/lead/{token}/document/{docId}
     * Serve a stored lead document (no auth — validated by lead_token ownership).
     */
    public function serveDocument(Request $request, string $token, int $docId)
    {
        try {
            [$absPath, $mime, $filename] = $this->svc->serveLeadDocument($token, $docId);

            if ($mime === 'redirect') {
                return redirect($absPath);
            }

            return response()->file($absPath, [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            ]);
        } catch (\RuntimeException $e) {
            return response('<h1>Not Found</h1>', 404)->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            return response('<h1>Error</h1>', 500)->header('Content-Type', 'text/html');
        }
    }

    /**
     * GET /public/merchant/{token}/document-types
     * Returns active document types for the tenant resolved from the lead token.
     */
    public function getDocumentTypes(Request $request, string $token)
    {
        try {
            [, $clientId] = $this->svc->resolveLeadToken($token);

            $types = \Illuminate\Support\Facades\DB::connection("mysql_{$clientId}")
                ->table('crm_documents_types')
                ->where('status', 1)
                ->orderBy('id')
                ->get(['id', 'title', 'type_title_url', 'values']);

            return response()->json(['success' => true, 'data' => $types]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load document types.'], 500);
        }
    }

    /**
     * DELETE /public/merchant/{token}/document/{docId}
     * Delete a document from the merchant portal (removes file + DB row).
     */
    public function deleteDocument(Request $request, string $token, int $docId)
    {
        try {
            [$lead, $clientId] = $this->svc->resolveLeadToken($token);
            $this->svc->deleteLeadDocument($lead, $clientId, $docId);

            return response()->json(['success' => true, 'message' => 'Document deleted.']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete document.'], 500);
        }
    }

    /**
     * GET /public/document/{token}/view/{docId}
     * Serve a lead document inline (never redirects to direct storage URL).
     * Authenticated by lead_token ownership.
     */
    public function viewDocument(Request $request, string $token, int $docId)
    {
        try {
            [$absPath, $mime, $filename] = $this->svc->serveDocumentInline($token, $docId);

            return response()->file($absPath, [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
                'X-Frame-Options'     => 'SAMEORIGIN',
                'Cache-Control'       => 'private, no-store',
            ]);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 404;
            return response('<h1>Not Found</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>', $code)
                ->header('Content-Type', 'text/html');
        } catch (\Throwable $e) {
            return response('<h1>Error</h1><p>Unable to load document.</p>', 500)
                ->header('Content-Type', 'text/html');
        }
    }

    /**
     * POST /public/merchant/{token}/upload
     * Upload a document from the merchant portal or initial submission.
     */
    public function uploadDocument(Request $request, string $token)
    {
        $this->validate($request, [
            'document'      => 'required|file|max:10240|mimes:pdf',
            'document_type' => 'nullable|string|max:100',
        ]);

        try {
            [$lead, $clientId] = $this->svc->resolveLeadToken($token);
            $file   = $request->file('document');
            $result = $this->svc->storeDocument($lead, $clientId, $file, $request->input('document_type', 'general'));

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to upload document.'], 500);
        }
    }
}

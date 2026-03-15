<?php

namespace App\Http\Controllers;

use App\Services\PublicApplicationService;
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

    public function __construct(PublicApplicationService $svc)
    {
        $this->svc = $svc;
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
        $this->validate($request, [
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'email'           => 'required|email|max:255',
            'mobile'          => 'required|string|max:30',
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
     * GET /public/lead/{token}/document/{docId}
     * Serve a stored lead document (no auth — validated by lead_token ownership).
     */
    public function serveDocument(Request $request, string $token, int $docId)
    {
        try {
            [$absPath, $mime, $filename] = $this->svc->serveLeadDocument($token, $docId);

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
     * POST /public/merchant/{token}/upload
     * Upload a document from the merchant portal or initial submission.
     */
    public function uploadDocument(Request $request, string $token)
    {
        $this->validate($request, [
            'document'      => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
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

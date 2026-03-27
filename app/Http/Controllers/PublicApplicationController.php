<?php

namespace App\Http\Controllers;

use App\Services\PublicApplicationService;
use App\Services\LeadPdfService;
use App\Services\FieldValidationService;
use App\Services\LeadValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                    // 'affiliate' context: only fields with apply_to = NULL, 'affiliate', or 'both'
                    'sections'       => $this->svc->getFormSections($clientId, false, 'affiliate'),
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

            // Dynamic field validation — only validate fields relevant to affiliate form
            $dynErrors = $this->validateEavFields($request, $clientId, true, 'affiliate');
            if (!empty($dynErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please correct the highlighted fields.',
                    'errors'  => $dynErrors,
                ], 422);
            }

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
            // PDF includes all affiliate-scoped fields (apply_to = NULL | affiliate | both)
            $sections = $this->svc->getFormSections($clientId, true, 'affiliate');
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
                    // 'merchant' context: only fields with apply_to = NULL, 'merchant', or 'both'
                    'sections' => $this->svc->getFormSections($clientId, true, 'merchant'),
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

            // Type-only validation — only validate merchant-scoped fields (partial save)
            $dynErrors = $this->validateEavFields($request, $clientId, false, 'merchant');
            if (!empty($dynErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please correct the highlighted fields.',
                    'errors'  => $dynErrors,
                ], 422);
            }

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

                // Fallback: built-in apply-form template — affiliate context
                $company  = $this->svc->getCompanyBranding($client, $clientId);
                $sections = $this->svc->getFormSections($clientId, true, 'affiliate');
                $html     = $this->svc->generateApplicationHtml($clientId, $lead, $sections, $company);
                $binary   = $this->pdfSvc->htmlToPdfBytes($html);
                $names    = $this->pdfSvc->resolveLeadName($clientId, $lead->id);
                $filename = LeadPdfService::pdfFilename($names['first_name'], $names['last_name']);
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

                // Fallback: built-in apply-form template — merchant context
                $company  = $this->svc->getCompanyBranding($client, $clientId);
                $sections = $this->svc->getFormSections($clientId, true, 'merchant');
                $html     = $this->svc->generateApplicationHtml($clientId, $lead, $sections, $company);
                $binary   = $this->pdfSvc->htmlToPdfBytes($html);
                $names    = $this->pdfSvc->resolveLeadName($clientId, $lead->id);
                $filename = LeadPdfService::pdfFilename($names['first_name'], $names['last_name']);
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
     * Save or replace a signature from the merchant portal.
     *
     * Accepts:
     *   { signature_image: "data:image/png;base64,...", field?: "signature_image"|"owner_2_signature_image" }
     *
     * `field` defaults to "signature_image" (Sig 1).
     * Pass field="owner_2_signature_image" to save Signature 2 (Co-Applicant).
     */
    public function saveMerchantSignature(Request $request, string $token)
    {
        $this->validate($request, [
            'signature_image' => 'required|string|min:50',
            'field'           => 'nullable|string',
        ]);

        // Whitelist allowed field keys — reject anything else
        $allowed = ['signature_image', 'owner_2_signature_image'];
        $field   = $request->input('field', 'signature_image');
        if (!in_array($field, $allowed, true)) {
            $field = 'signature_image';
        }

        try {
            [$lead, $clientId] = $this->svc->resolveLeadToken($token);
            $conn   = "mysql_{$clientId}";
            $result = $this->svc->storeSignature($clientId, $lead->id, $request->input('signature_image'), $conn, $field);

            if (!$result) {
                return response()->json(['success' => false, 'message' => 'Failed to process signature image.'], 422);
            }

            // Return the backend-served URL matching the field saved
            $base     = rtrim(env('APP_URL'), '/');
            $token2   = $lead->lead_token;
            $serveUrl = $field === 'owner_2_signature_image'
                ? "{$base}/public/lead/{$token2}/signature2"
                : "{$base}/public/lead/{$token2}/signature";

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
     * GET /public/lead/{token}/signature2
     * Serve the lead's Co-Applicant (Signature 2) image.
     */
    public function serveSignature2(Request $request, string $token)
    {
        try {
            [$lead, $clientId] = $this->svc->resolveLeadToken($token);
            [$absPath, $mime]  = $this->svc->serveLeadSignature($clientId, $lead->id, 'owner_2_signature_image');

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

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate submitted form data against crm_labels field definitions.
     *
     * Generates validation rules dynamically from each label's field_type.
     * No field names are hardcoded — works for any crm_labels configuration.
     *
     * @param  Request $request     Incoming HTTP request
     * @param  string  $clientId    Tenant client ID (used to select DB connection)
     * @param  bool    $requireAll  When true: also enforce required on fields NOT in request.
     *                              When false: only validate fields that ARE in the request (partial save).
     * @param  string  $context     'affiliate' | 'merchant' | 'system'
     *                              Restricts validation to labels whose apply_to is compatible.
     *                              'affiliate' → apply_to IN (NULL, 'affiliate', 'both')
     *                              'merchant'  → apply_to IN (NULL, 'merchant', 'both')
     *                              'system'    → all labels (no filter)
     * @return array                { field_key: [errorMessage] } — empty means valid
     */
    private function validateEavFields(Request $request, string $clientId, bool $requireAll, string $context = 'system'): array
    {
        $query = DB::connection("mysql_{$clientId}")
            ->table('crm_labels')
            ->where('status', true);

        // ── Filter by apply_to scope ──────────────────────────────────────────
        // Only validate fields that are visible / applicable to this form context.
        // Fields with apply_to = NULL have no restriction and are always validated.
        if ($context === 'affiliate') {
            $query->where(function ($q) {
                $q->whereNull('apply_to')
                  ->orWhereIn('apply_to', ['affiliate', 'both']);
            });
        } elseif ($context === 'merchant') {
            $query->where(function ($q) {
                $q->whereNull('apply_to')
                  ->orWhereIn('apply_to', ['merchant', 'both']);
            });
        }
        // 'system' context → no additional WHERE clause

        $labels = $query
            ->get(['field_key', 'field_type', 'required', 'required_in', 'label_name', 'options', 'validation_rules'])
            ->toArray();

        $fieldSvc = new FieldValidationService();
        $leadSvc  = new LeadValidationService();
        $input    = $request->except(['_token', '_method', 'signature_image', 'owner_2_signature_image']);
        $errors   = [];

        // ── Fields with stored validation_rules → LeadValidationService ──────
        $withRules    = array_filter($labels, fn($l) => !empty($l->validation_rules));
        $withoutRules = array_filter($labels, fn($l) => empty($l->validation_rules));

        if (!empty($withRules)) {
            // When $requireAll=false (merchant update), remove 'required' from rules
            // for fields that aren't present in the input so optional edits work.
            $labelsToValidate = $requireAll
                ? array_values($withRules)
                : array_values(array_filter($withRules, fn($l) => array_key_exists($l->field_key, $input)));

            $builtRules = $leadSvc->buildRules($labelsToValidate);
            $ruleErrors = $leadSvc->validate($input, $builtRules, $labelsToValidate);
            foreach ($ruleErrors as $key => $msgs) {
                $errors[$key] = $msgs;
            }

            // Required-but-missing check for $requireAll mode
            if ($requireAll) {
                foreach ($withRules as $label) {
                    $key = $label->field_key;
                    if (isset($errors[$key])) continue;
                    $ri        = is_string($label->required_in ?? null) ? json_decode($label->required_in, true) : ($label->required_in ?? null);
                    $isReq     = !empty($ri) ? in_array($context, $ri, true) : !empty($label->required);
                    if (!array_key_exists($key, $input) && $isReq) {
                        $errors[$key] = [$label->label_name . ' is required.'];
                    }
                }
            }
        }

        // ── Fields without validation_rules → legacy FieldValidationService ──
        foreach ($withoutRules as $label) {
            $key        = $label->field_key;
            $fieldType  = strtolower(trim((string) $label->field_type));
            // required_in takes precedence; fall back to legacy required boolean
            $ri         = is_string($label->required_in ?? null) ? json_decode($label->required_in, true) : ($label->required_in ?? null);
            $isRequired = !empty($ri) ? in_array($context, $ri, true) : !empty($label->required);

            if (!array_key_exists($key, $input)) {
                if ($requireAll && $isRequired) {
                    $errors[$key] = [$label->label_name . ' is required.'];
                }
                continue;
            }

            if (isset($errors[$key])) continue;

            $raw     = $fieldSvc->sanitize($input[$key], $fieldType, $input, $key);
            $isEmpty = ($raw === null || $raw === '');

            if ($isRequired && $isEmpty) {
                $errors[$key] = [$label->label_name . ' is required.'];
                continue;
            }

            if ($isEmpty) continue;

            $options = $fieldSvc->decodeOptions($label->options ?? null);
            $errMsg  = $fieldSvc->validate($raw, $fieldType, $label->label_name, $options);
            if ($errMsg !== null) {
                $errors[$key] = [$errMsg];
            }
        }

        return $errors;
    }
}

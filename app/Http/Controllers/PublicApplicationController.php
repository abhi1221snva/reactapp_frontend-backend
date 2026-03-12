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
     * Returns company branding + dynamic form sections for the apply page.
     */
    public function getApplyForm(Request $request, string $code)
    {
        try {
            [$user, $clientId, $client] = $this->svc->resolveAffiliate($code);

            $company  = $this->svc->getCompanyBranding($client);
            $sections = $this->svc->getFormSections($clientId);

            return response()->json([
                'success' => true,
                'data'    => [
                    'company'        => $company,
                    'sections'       => $sections,
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
     * Returns the lead_token and merchant_url.
     */
    public function submitApplication(Request $request, string $code)
    {
        $this->validate($request, [
            'legal_company_name' => 'sometimes|string|max:255',
            'first_name'         => 'required|string|max:100',
            'last_name'          => 'required|string|max:100',
            'email'              => 'required|email|max:255',
            'mobile'             => 'required|string|max:30',
        ]);

        try {
            [$user, $clientId] = $this->svc->resolveAffiliate($code);

            $formData = $request->except(['_token', '_method']);
            $result   = $this->svc->createLead($clientId, $user, $formData);

            return response()->json([
                'success'      => true,
                'message'      => 'Application submitted successfully! Use your merchant link to track your application.',
                'lead_token'   => $result['lead_token'],
                'merchant_url' => $result['merchant_url'],
                'lead_id'      => $result['lead_id'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            \Log::error('Public apply submit: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to submit application.'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MERCHANT PORTAL
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /public/merchant/{token}
     * Returns company branding + lead data + form sections for merchant portal.
     */
    public function getMerchantPortal(Request $request, string $token)
    {
        try {
            [$lead, $clientId, $client] = $this->svc->resolveLeadToken($token);

            $company  = $this->svc->getCompanyBranding($client);
            $sections = $this->svc->getFormSections($clientId, true); // include all fields
            $leadData = $this->svc->getMerchantLeadData($lead, $clientId);

            return response()->json([
                'success' => true,
                'data'    => [
                    'company'  => $company,
                    'lead'     => $leadData,
                    'sections' => $sections,
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
     * POST /public/merchant/{token}/upload
     * Upload a document from the merchant portal.
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

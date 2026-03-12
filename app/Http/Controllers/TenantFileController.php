<?php

namespace App\Http\Controllers;

use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * TenantFileController
 *
 * Serves files from tenant storage with proper isolation.
 *
 * PUBLIC endpoint (no auth):
 *   GET /public/tenant/{clientId}/logo
 *     → Streams the company logo for the given client.
 *       Used on public apply forms and merchant portals.
 *
 * PRIVATE endpoint (JWT required):
 *   GET /crm/tenant-file/{subdir}/{filename}
 *     → Serves a private file for the authenticated user's tenant.
 *       Only allows: documents, uploads, applications, exports
 */
class TenantFileController extends Controller
{
    private const ALLOWED_PRIVATE_SUBDIRS = [
        'documents',
        'uploads',
        'applications',
        'exports',
    ];

    // ── Public: company logo ──────────────────────────────────────────────────

    /**
     * GET /public/tenant/{clientId}/logo
     *
     * No authentication required — logo is shown on public apply forms.
     * Returns 404 if no logo is configured.
     */
    public function serveLogo(Request $request, int $clientId): Response|BinaryFileResponse
    {
        try {
            // Resolve logo filename from crm_system_setting
            $setting = \Illuminate\Support\Facades\DB::connection("mysql_{$clientId}")
                ->table('crm_system_setting')
                ->orderBy('id')
                ->first(['logo']);

            if (!$setting || empty($setting->logo)) {
                return response('Logo not found', 404);
            }

            $logo = $setting->logo;

            // If it's a full URL, redirect to it
            if (str_starts_with($logo, 'http')) {
                return redirect($logo);
            }

            // Try tenant storage first (new path)
            $tenantPath = TenantStorageService::getPath($clientId, 'company') . '/' . $logo;
            if (file_exists($tenantPath)) {
                return $this->streamFile($tenantPath);
            }

            // Fallback: legacy public/logo/ path
            $legacyPath = public_path('logo/' . $logo);
            if (file_exists($legacyPath)) {
                return $this->streamFile($legacyPath);
            }

            return response('Logo file not found', 404);
        } catch (\Throwable $e) {
            return response('Error: ' . $e->getMessage(), 500);
        }
    }

    // ── Private: tenant files ─────────────────────────────────────────────────

    /**
     * GET /crm/tenant-file/{subdir}/{filename}
     *
     * JWT authenticated. Only serves files belonging to the requesting user's tenant.
     */
    public function serveFile(Request $request, string $subdir, string $filename): Response|BinaryFileResponse
    {
        try {
            $clientId = (int) $request->auth->parent_id;

            if (!in_array($subdir, self::ALLOWED_PRIVATE_SUBDIRS, true)) {
                return response('Access denied', 403);
            }

            // Build and validate safe path
            $relativePath = $subdir . '/' . $filename;
            $safePath     = TenantStorageService::resolveSafePath($clientId, $relativePath);

            if (!$safePath || !file_exists($safePath)) {
                return response('File not found', 404);
            }

            return $this->streamFile($safePath);
        } catch (\Throwable $e) {
            return response('Error: ' . $e->getMessage(), 500);
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function streamFile(string $path): BinaryFileResponse
    {
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        return response()->file($path, ['Content-Type' => $mime]);
    }
}

<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * TenantStorageService
 *
 * Centralised service for all per-tenant file I/O.
 *
 * Base path:  storage/app/clients/client_{id}/
 * Subdirs:    documents/ applications/ uploads/ exports/ logs/ temp/ company/
 *
 * Public logo served at:  GET /public/tenant/{clientId}/logo
 * Private files served at: GET /crm/tenant-file/{path} (JWT protected)
 */
class TenantStorageService
{
    /** Standard subdirectories created for every tenant */
    const SUBDIRS = [
        'documents',
        'applications',
        'uploads',
        'exports',
        'logs',
        'temp',
        'company',
    ];

    // ── Path helpers ──────────────────────────────────────────────────────────

    /**
     * Absolute base path for a tenant.
     * e.g.  storage/app/clients/client_5
     */
    public static function getBasePath(int $clientId): string
    {
        return storage_path('app/clients/client_' . $clientId);
    }

    /**
     * Absolute path to a subdirectory (or the base if $subdir is empty).
     */
    public static function getPath(int $clientId, string $subdir = ''): string
    {
        $base = static::getBasePath($clientId);
        return $subdir ? $base . DIRECTORY_SEPARATOR . ltrim($subdir, '/\\') : $base;
    }

    /**
     * Ensure all standard subdirectories exist (idempotent).
     */
    public static function ensureDirectories(int $clientId): void
    {
        $base = static::getBasePath($clientId);
        if (!is_dir($base)) {
            mkdir($base, 0775, true);
            @chown($base, 'www-data');
        }
        foreach (static::SUBDIRS as $dir) {
            $path = $base . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
                @chown($path, 'www-data');
            }
        }
        Log::info("TenantStorageService: directories ensured for client_{$clientId}");
    }

    // ── Logo ──────────────────────────────────────────────────────────────────

    /**
     * Store an uploaded logo in the tenant company folder.
     * Always saves as "logo.{ext}" so there is at most one logo file.
     * Returns the filename (e.g. "logo.png").
     */
    public static function storeLogo(int $clientId, UploadedFile $file): string
    {
        static::ensureDirectories($clientId);

        $ext      = strtolower($file->getClientOriginalExtension()) ?: 'png';
        $filename = 'logo.' . $ext;
        $destDir  = static::getPath($clientId, 'company');

        // Remove any previous logo files
        foreach (glob($destDir . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }

        $file->move($destDir, $filename);
        return $filename;
    }

    /**
     * Delete the current logo file for a tenant (if local).
     */
    public static function deleteLogo(int $clientId, string $filename): void
    {
        if (empty($filename) || str_starts_with($filename, 'http')) {
            return;
        }
        $path = static::getPath($clientId, 'company') . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Get the public URL to serve a tenant logo.
     * Served via a public controller endpoint (no auth needed).
     */
    public static function getLogoUrl(int $clientId, string $filename): string
    {
        return rtrim(env('APP_URL'), '/') . '/public/tenant/' . $clientId . '/logo';
    }

    /**
     * Resolve the full absolute path of a stored logo.
     * Returns null if not found.
     */
    public static function resolveLogoPath(int $clientId, string $filename): ?string
    {
        if (str_starts_with($filename, 'http')) {
            return null; // external URL — not a local file
        }
        $path = static::getPath($clientId, 'company') . DIRECTORY_SEPARATOR . $filename;
        return file_exists($path) ? $path : null;
    }

    // ── Documents / general files ─────────────────────────────────────────────

    /**
     * Store an uploaded file in a tenant subdirectory.
     * Returns the relative path from tenant base (e.g. "documents/1234_abc.pdf").
     */
    public static function storeFile(int $clientId, UploadedFile $file, string $subdir = 'uploads'): string
    {
        static::ensureDirectories($clientId);
        $dir = static::getPath($clientId, $subdir);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $ext      = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $filename = time() . '_' . uniqid() . '.' . $ext;
        $file->move($dir, $filename);
        return $subdir . '/' . $filename;
    }

    /**
     * Save raw PDF content for a lead application.
     * Returns the absolute path.
     */
    public static function saveApplicationPdf(int $clientId, int $leadId, string $content): string
    {
        static::ensureDirectories($clientId);
        $dir  = static::getPath($clientId, 'applications');
        $path = $dir . DIRECTORY_SEPARATOR . "application_{$leadId}.pdf";
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Resolve the absolute path of a file within a tenant's storage.
     * Includes path-traversal protection — returns null on violation.
     */
    public static function resolveSafePath(int $clientId, string $relativePath): ?string
    {
        $base     = realpath(static::getBasePath($clientId));
        if (!$base) {
            return null;
        }
        $full     = $base . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        $realFull = realpath($full);
        if (!$realFull || !str_starts_with($realFull, $base)) {
            Log::warning("TenantStorageService: path traversal blocked for client_{$clientId}: {$relativePath}");
            return null;
        }
        return $realFull;
    }

    /**
     * Delete a file within a tenant's storage.
     * Returns true on success, false if blocked or not found.
     */
    public static function deleteFile(int $clientId, string $relativePath): bool
    {
        $path = static::resolveSafePath($clientId, $relativePath);
        if (!$path) {
            return false;
        }
        if (file_exists($path)) {
            return (bool) @unlink($path);
        }
        return false;
    }

    // ── Directory listing ─────────────────────────────────────────────────────

    /**
     * List files in a tenant subdirectory.
     * Returns array of filenames (not paths).
     */
    public static function listFiles(int $clientId, string $subdir): array
    {
        $dir = static::getPath($clientId, $subdir);
        if (!is_dir($dir)) {
            return [];
        }
        return array_values(array_filter(scandir($dir), fn($f) => !in_array($f, ['.', '..'])));
    }
}

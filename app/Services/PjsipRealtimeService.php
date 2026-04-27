<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps ps_endpoints / ps_auths / ps_aors in sync with user_extensions
 * so Asterisk PJSIP realtime can authenticate extensions.
 *
 * IMPORTANT: Asterisk needs plaintext passwords for SIP digest auth.
 * This service REFUSES to write bcrypt hashes to ps_auths — if a
 * bcrypt-hashed password is detected, only ps_endpoints and ps_aors
 * are written; ps_auths.password is left untouched.
 */
class PjsipRealtimeService
{
    /**
     * Detect bcrypt hashes ($2y$, $2a$, $2b$).
     */
    private static function isBcrypt(string $value): bool
    {
        return (bool) preg_match('/^\$2[yab]\$/', $value);
    }

    /**
     * Insert-or-update ps_endpoints, ps_auths, and ps_aors for one extension.
     *
     * If the password is a bcrypt hash, ps_auths is NOT touched (to avoid
     * overwriting a working plaintext password that may already be there).
     */
    public static function syncExtension(
        string  $extensionId,
        string  $password,
        string  $context = 'user-extensions-phones',
        ?string $callerid = null
    ): void {
        $db = DB::connection('master');
        $passwordIsBcrypt = self::isBcrypt($password);

        if ($passwordIsBcrypt) {
            Log::warning('PjsipRealtimeService::syncExtension: bcrypt password detected, skipping ps_auths write', [
                'extension' => $extensionId,
            ]);
        }

        try {
            // ps_endpoints — always safe to upsert
            $db->statement(
                "INSERT INTO ps_endpoints
                    (id, aors, auth, context, disallow, allow, direct_media,
                     force_rport, rewrite_contact, rtp_symmetric, dtmf_mode, webrtc, callerid)
                 VALUES
                    (:id, :aors, :auth, :context, 'all', 'opus,ulaw,alaw', 'no',
                     'yes', 'yes', 'yes', 'rfc4733', 'yes', :callerid)
                 ON DUPLICATE KEY UPDATE
                    aors           = VALUES(aors),
                    auth           = VALUES(auth),
                    context        = VALUES(context),
                    disallow       = VALUES(disallow),
                    allow          = VALUES(allow),
                    direct_media   = VALUES(direct_media),
                    force_rport    = VALUES(force_rport),
                    rewrite_contact= VALUES(rewrite_contact),
                    rtp_symmetric  = VALUES(rtp_symmetric),
                    dtmf_mode      = VALUES(dtmf_mode),
                    webrtc         = VALUES(webrtc),
                    callerid       = VALUES(callerid)",
                [
                    'id'       => $extensionId,
                    'aors'     => $extensionId,
                    'auth'     => $extensionId,
                    'context'  => $context,
                    'callerid' => $callerid ?? $extensionId,
                ]
            );

            // ps_auths — ONLY if password is plaintext
            if (!$passwordIsBcrypt) {
                $db->statement(
                    "INSERT INTO ps_auths
                        (id, auth_type, username, password)
                     VALUES
                        (:id, 'userpass', :username, :password)
                     ON DUPLICATE KEY UPDATE
                        username  = VALUES(username),
                        password  = VALUES(password)",
                    [
                        'id'       => $extensionId,
                        'username' => $extensionId,
                        'password' => $password,
                    ]
                );
            } else {
                // Still ensure the row exists (with auth_type and username) but
                // do NOT overwrite an existing password
                $db->statement(
                    "INSERT IGNORE INTO ps_auths (id, auth_type, username, password)
                     VALUES (:id, 'userpass', :username, '')",
                    [
                        'id'       => $extensionId,
                        'username' => $extensionId,
                    ]
                );
            }

            // ps_aors — always safe to upsert
            $db->statement(
                "INSERT INTO ps_aors
                    (id, max_contacts, remove_existing, qualify_frequency,
                     minimum_expiration, default_expiration, maximum_expiration)
                 VALUES
                    (:id, 1, 'yes', 60, 60, 3600, 7200)
                 ON DUPLICATE KEY UPDATE
                    max_contacts       = VALUES(max_contacts),
                    remove_existing    = VALUES(remove_existing),
                    qualify_frequency  = VALUES(qualify_frequency),
                    minimum_expiration = VALUES(minimum_expiration),
                    default_expiration = VALUES(default_expiration),
                    maximum_expiration = VALUES(maximum_expiration)",
                ['id' => $extensionId]
            );
        } catch (\Throwable $e) {
            Log::error('PjsipRealtimeService::syncExtension failed', [
                'extension' => $extensionId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete ps_endpoints, ps_auths, and ps_aors for one extension.
     */
    public static function deleteExtension(string $extensionId): void
    {
        $db = DB::connection('master');

        try {
            $db->delete("DELETE FROM ps_endpoints WHERE id = ?", [$extensionId]);
            $db->delete("DELETE FROM ps_auths    WHERE id = ?", [$extensionId]);
            $db->delete("DELETE FROM ps_aors     WHERE id = ?", [$extensionId]);
        } catch (\Throwable $e) {
            Log::error('PjsipRealtimeService::deleteExtension failed', [
                'extension' => $extensionId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update only the password in ps_auths (e.g. after a password reset).
     * Refuses to write bcrypt hashes.
     */
    public static function syncPassword(string $extensionId, string $plainPassword): void
    {
        if (self::isBcrypt($plainPassword)) {
            Log::warning('PjsipRealtimeService::syncPassword: refusing to write bcrypt hash to ps_auths', [
                'extension' => $extensionId,
            ]);
            return;
        }

        try {
            $affected = DB::connection('master')
                ->table('ps_auths')
                ->where('id', $extensionId)
                ->update(['password' => $plainPassword]);

            // If the row didn't exist yet, create the full set
            if ($affected === 0) {
                $ext = DB::connection('master')
                    ->table('user_extensions')
                    ->where('username', $extensionId)
                    ->first();

                if ($ext) {
                    self::syncExtension(
                        $extensionId,
                        $plainPassword,
                        $ext->context ?? 'user-extensions-phones',
                        $ext->fullname
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error('PjsipRealtimeService::syncPassword failed', [
                'extension' => $extensionId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bulk-sync all user_extensions rows into ps_* tables.
     * Returns [created, skippedBcrypt, alreadyExists].
     */
    public static function syncAllFromUserExtensions(bool $dryRun = false): array
    {
        $created        = 0;
        $skippedBcrypt  = 0;
        $alreadyExists  = 0;

        $extensions = DB::connection('master')
            ->table('user_extensions')
            ->select('username', 'secret', 'context', 'fullname')
            ->whereNotNull('username')
            ->where('username', '!=', '')
            ->get();

        foreach ($extensions as $ext) {
            $id = $ext->username;

            // Check if already synced in all three tables
            $inEndpoint = DB::connection('master')
                ->table('ps_endpoints')->where('id', $id)->exists();
            $inAuth = DB::connection('master')
                ->table('ps_auths')->where('id', $id)->exists();
            $inAor = DB::connection('master')
                ->table('ps_aors')->where('id', $id)->exists();

            if ($inEndpoint && $inAuth && $inAor) {
                $alreadyExists++;
                continue;
            }

            // Detect bcrypt hashes — skip entirely (log warning)
            if ($ext->secret && self::isBcrypt($ext->secret)) {
                $skippedBcrypt++;
                Log::warning('PjsipRealtimeService: skipping extension with bcrypt-hashed secret', [
                    'extension' => $id,
                ]);
                continue;
            }

            if ($dryRun) {
                $created++;
                continue;
            }

            self::syncExtension(
                $id,
                $ext->secret ?? '',
                $ext->context ?? 'user-extensions-phones',
                $ext->fullname
            );
            $created++;
        }

        return compact('created', 'skippedBcrypt', 'alreadyExists');
    }
}

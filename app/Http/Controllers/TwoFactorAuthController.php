<?php
namespace App\Http\Controllers;

use App\Model\Master\TotpBackupCode;
use App\Model\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use App\Http\Helper\JwtToken;

class TwoFactorAuthController extends Controller
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * POST /2fa/setup
     * Generate TOTP secret and return QR code URL (does NOT enable 2FA yet).
     */
    public function setup(Request $request)
    {
        try {
            $user = User::findOrFail($request->auth->id);

            $secret = $this->google2fa->generateSecretKey(32);
            // DB column is google2fa_secret (no underscore between google and 2fa)
            $user->google2fa_secret = $secret;
            $user->save();

            $siteName = env('SITE_NAME', 'Dialer');
            $qrUrl = $this->google2fa->getQRCodeUrl(
                $siteName,
                $user->email,
                $secret
            );

            // Generate inline SVG QR code using BaconQrCode
            $renderer = new ImageRenderer(
                new RendererStyle(200),
                new SvgImageBackEnd()
            );
            $writer  = new Writer($renderer);
            $svgData = $writer->writeString($qrUrl);
            $qrCode  = 'data:image/svg+xml;base64,' . base64_encode($svgData);

            return response()->json([
                'status'  => true,
                'message' => '2FA setup initiated. Scan the QR code with Google Authenticator.',
                'data'    => [
                    'secret'      => $secret,
                    'qr_code'     => $qrCode,
                    'qr_code_url' => $qrUrl,
                    'site_name'   => $siteName,
                    'email'       => $user->email,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('TwoFactorAuthController::setup error', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Setup failed.'], 500);
        }
    }

    /**
     * POST /2fa/enable
     * Verify a TOTP code, then enable 2FA and return backup codes.
     */
    public function enable(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required|digits:6',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::findOrFail($request->auth->id);
            if (empty($user->google2fa_secret)) {
                return response()->json(['status' => false, 'message' => 'Please run /2fa/setup first.'], 400);
            }

            $valid = $this->google2fa->verifyKey($user->google2fa_secret, $request->input('otp'));
            if (!$valid) {
                return response()->json(['status' => false, 'message' => 'Invalid authenticator code.'], 400);
            }

            $user->is_2fa_google_enabled     = 1;
            $user->allow_google_authenticator = 1;
            $user->totp_enabled_at            = Carbon::now();
            $user->save();

            $backupCodes = TotpBackupCode::generateForUser($user->id);
            $user->totp_backup_codes_generated_at = Carbon::now();
            $user->save();

            Log::info('2FA enabled', ['user_id' => $user->id]);

            return response()->json([
                'status'  => true,
                'message' => 'Two-factor authentication enabled. Save your backup codes safely.',
                'data'    => ['enabled' => true, 'backup_codes' => $backupCodes],
            ]);
        } catch (\Throwable $e) {
            Log::error('TwoFactorAuthController::enable error', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Enable failed.'], 500);
        }
    }

    /**
     * POST /2fa/disable
     * Verify password, then disable 2FA and wipe all backup codes.
     */
    public function disable(Request $request)
    {
        try {
            $user = User::findOrFail($request->auth->id);

            $user->is_2fa_google_enabled              = 0;
            $user->allow_google_authenticator          = 0;
            $user->google2fa_secret                    = null;
            $user->totp_enabled_at                     = null;
            $user->totp_backup_codes_generated_at      = null;
            $user->save();

            TotpBackupCode::where('user_id', $user->id)->delete();

            Log::info('2FA disabled', ['user_id' => $user->id]);

            return response()->json([
                'status'  => true,
                'message' => 'Two-factor authentication disabled.',
                'data'    => ['disabled' => true],
            ]);
        } catch (\Throwable $e) {
            Log::error('TwoFactorAuthController::disable error', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Disable failed.'], 500);
        }
    }

    /**
     * POST /2fa/verify
     * Called after partial login when 2FA is required (no JWT needed).
     * Accepts 6-digit TOTP or 8-char backup code.
     */
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'otp'     => 'required|string|max:8',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::findOrFail($request->input('user_id'));

            if (!$user->is_2fa_google_enabled) {
                return response()->json(['status' => false, 'message' => '2FA is not enabled for this account.'], 400);
            }

            // Check lockout
            if (!empty($user->totp_login_locked_until)) {
                $lockedUntil = Carbon::parse($user->totp_login_locked_until);
                if ($lockedUntil->isFuture()) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Account temporarily locked due to too many failed attempts. Try again after ' . $lockedUntil->toDateTimeString() . ' UTC.',
                    ], 429);
                }
                // Lock expired — reset
                $user->totp_login_attempts    = 0;
                $user->totp_login_locked_until = null;
                $user->save();
            }

            $otp   = trim($request->input('otp'));
            $valid = false;

            // Try TOTP first (6-digit numeric)
            if (strlen($otp) === 6 && ctype_digit($otp)) {
                $valid = $this->google2fa->verifyKey($user->google2fa_secret, $otp);
            }

            // Try backup code (8-char hex, case-insensitive)
            if (!$valid && strlen($otp) === 8) {
                $valid = TotpBackupCode::consumeCode($user->id, $otp);
            }

            if (!$valid) {
                $attempts = ($user->totp_login_attempts ?? 0) + 1;
                if ($attempts >= 5) {
                    $user->totp_login_attempts    = $attempts;
                    $user->totp_login_locked_until = Carbon::now()->addMinutes(15);
                    $user->save();
                    return response()->json([
                        'status'  => false,
                        'message' => 'Too many failed attempts. Account locked for 15 minutes.',
                    ], 429);
                }
                $user->totp_login_attempts = $attempts;
                $user->save();
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid authenticator code or backup code. Please try again.',
                ], 400);
            }

            // Success — reset attempt counters
            $user->totp_login_attempts    = 0;
            $user->totp_login_locked_until = null;
            $user->save();

            // Generate JWT token identical to login flow
            $tokenData = JwtToken::createToken($user->id);
            $data               = $user->toArray();
            $data['token']      = $tokenData[0];
            $data['expires_at'] = $tokenData[1];
            $data['permissions']= $user->getPermissions(true);

            Log::info('2FA verified, JWT issued', ['user_id' => $user->id]);

            return response()->json([
                'status'  => true,
                'message' => 'Authentication successful.',
                'data'    => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('TwoFactorAuthController::verify error', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Verification failed.'], 500);
        }
    }

    /**
     * POST /2fa/backup-codes/regenerate
     * Regenerate all backup codes for the authenticated user.
     */
    public function regenerateBackupCodes(Request $request)
    {
        try {
            $user = User::findOrFail($request->auth->id);
            if (!$user->is_2fa_google_enabled) {
                return response()->json(['status' => false, 'message' => '2FA is not enabled.'], 400);
            }
            $codes = TotpBackupCode::generateForUser($user->id);
            $user->totp_backup_codes_generated_at = Carbon::now();
            $user->save();

            Log::info('2FA backup codes regenerated', ['user_id' => $user->id]);

            return response()->json([
                'status'  => true,
                'message' => 'Backup codes regenerated. Store them safely.',
                'data'    => ['backup_codes' => $codes],
            ]);
        } catch (\Throwable $e) {
            Log::error('TwoFactorAuthController::regenerateBackupCodes error', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Failed to regenerate backup codes.'], 500);
        }
    }

    /**
     * GET /2fa/status
     * Return the 2FA status for the authenticated user.
     */
    public function status(Request $request)
    {
        try {
            $user        = User::findOrFail($request->auth->id);
            $backupCount = TotpBackupCode::where('user_id', $user->id)->where('used', false)->count();

            return response()->json([
                'status'  => true,
                'message' => '2FA status retrieved.',
                'data'    => [
                    'enabled'                => (bool) $user->is_2fa_google_enabled,
                    'backup_codes_remaining' => $backupCount,
                    'enabled_at'             => $user->totp_enabled_at,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('TwoFactorAuthController::status error', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Failed to retrieve status.'], 500);
        }
    }
}

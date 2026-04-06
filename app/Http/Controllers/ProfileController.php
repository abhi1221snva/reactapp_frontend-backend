<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Model\User;
use App\Model\UserSettings;
use App\Model\Master\Permission;
use App\Model\Master\AsteriskServer;
use App\Model\Master\ForgotPasswordLink;
use App\Model\Master\OtpVerification;
use App\Model\Master\Client;
use App\Model\Master\WebEmailVerification;
use App\Model\Master\WebPhoneVerification;

use App\Model\Master\WebLeads;


use App\Model\Client\UserToken;
use App\Model\Client\VoiceMailDrop;
use App\Model\Master\VoiceAi;
use Illuminate\Support\Facades\Session;


use Plivo\RestClient;

use App\Services\RolesService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use Illuminate\Support\Facades\Log;
use App\Model\Master\LoginLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use DB;


use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Support\Str;
use App\Model\Master\PasswordReset;
use Illuminate\Support\Facades\Mail;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Services\MailService;
use App\Mail\SystemNotificationMail;
use Illuminate\Support\Facades\Config;

use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

use PragmaRX\Google2FAQRCode\Google2FA;


class ProfileController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    /**
     * @OA\Get(
     *     path="/profile",
     *     summary="Get the authenticated user's profile",
     *     tags={"Profile"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User profile data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id",          type="integer"),
     *                 @OA\Property(property="first_name",  type="string"),
     *                 @OA\Property(property="last_name",   type="string"),
     *                 @OA\Property(property="email",       type="string"),
     *                 @OA\Property(property="role",        type="integer"),
     *                 @OA\Property(property="user_level",  type="integer"),
     *                 @OA\Property(property="profile_pic", type="string", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    // Display the authenticated user's profile
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'errors' => ['User not found']
            ], 404);
        }

        $data = $user->toArray();

        // Enrich with SIP config so the WebPhone can refresh credentials
        // without requiring a full re-login.
        if ($user->asterisk_server_id) {
            $asterisk = \DB::table('asterisk_server')
                ->where('id', $user->asterisk_server_id)
                ->select('host', 'domain')
                ->first();
            $data['server'] = $asterisk->host  ?? null;
            $data['domain'] = $asterisk->domain ?? null;
        }
        $sipExt = $user->alt_extension ?: (string) $user->extension;
        if ($sipExt) {
            $extRow = \DB::table('user_extensions')
                ->where('username', $sipExt)
                ->select('secret')
                ->first();
            $data['secret'] = $extRow->secret ?? null;
        }

        // Include company name from permissions so the frontend header can display it
        $permissions = $user->getPermissions();
        if (isset($permissions[$user->parent_id]['companyName'])) {
            $data['companyName'] = $permissions[$user->parent_id]['companyName'];
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }


    /**
     * @OA\Post(
     *     path="/profile/two-factor",
     *     summary="Enable or disable SMS-based 2FA for the authenticated user",
     *     tags={"Profile"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="two_factor", type="string", enum={"on","off"})
     *         )
     *     ),
     *     @OA\Response(response=200, description="2FA setting updated"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function updateTwoFactor(Request $request)
    {
        // $request->validate([
        //     'allow_two_factor_status' => 'required|in:0,1',
        // ]);

        $user = $request->user();
        if ($user->phone_verified_at && $user->phone_number) {
            $user->is_2fa_phone_enabled = $request->allow_two_factor_status;
            $user->save();
            $status = $user->is_2fa_phone_enabled ? "Enabled" : "Disabled";
            return response()->json(['success' => true, 'message' => 'Two Factor ' . $status . ' successfully.']);
        } else {

            return response()->json(['error' => true, 'message' => 'To enable Two Factor, First Add Phone Number.', 'modal' => 'show']);
        }
    }


    /**
     * @OA\Post(
     *     path="/profile/google-authenticator",
     *     summary="Set up Google Authenticator TOTP 2FA — returns QR code and secret",
     *     tags={"Profile"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Google Authenticator setup data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success",    type="boolean"),
     *             @OA\Property(property="qr_code",    type="string", description="Base64 SVG QR code"),
     *             @OA\Property(property="secret",     type="string", description="TOTP secret key")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function updateGoogleAuthenticator(Request $request)
    {
        try {
            $user = $request->user();

            if ($request->allow_google_authenticator == 1) {
                $google2fa = new Google2FA();

                if (is_null($user->google_2fa_secret)) {
                    $secretKey = $google2fa->generateSecretKey();
                    $user->google_2fa_secret = $secretKey;
                }

                $user->is_2fa_google_enabled = true;
                $user->save();

                // $qrImage = app('pragmarx.google2fa')->getQRCodeInline(
                //     'Leadmine Pro',
                //     $user->email,
                //     $user->google_2fa_secret
                // );
                // $google2fa = new Google2FA();

                $qrImage = $google2fa->getQRCodeInline(
                    'Leadmine Pro',        // Company name or app name
                    $user->email,          // User identifier (email usually)
                    $user->google_2fa_secret  // The generated 2FA secret
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Please scan the QR code to enable Google Authenticator.',
                    'show' => 1,
                    'qr_code' => $qrImage,
                    'email' => $user->email
                ]);
            } else {
                // Disable 2FA
                $user->is_2fa_google_enabled = false;
                $user->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Google Authenticator disabled.' . $request->allow_google_authenticator,
                    'show' => 0
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error updating Google Authenticator', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating Google Authenticator.'
            ], 500);
        }
    }



    // public function verifyGoogleAuthenticator(Request $request)
    // {
    //     try {
    //         $google2fa = new Google2FA();
    //         //request->auth->id
    //         // Fetch user or secret
    //         $user = auth()->user(); // or find user by ID/token
    //         if (!$user || empty($user->google2fa_secret)) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => '2FA secret not found for user.'
    //             ], 400);
    //         }

    //         $secret = $user->google2fa_secret;

    //         // Combine OTP digits into one string
    //         $otp = $request->input('digit1') .
    //             $request->input('digit2') .
    //             $request->input('digit3') .
    //             $request->input('digit4') .
    //             $request->input('digit5') .
    //             $request->input('digit6');

    //         Log::info('Verifying OTP', ['user_id' => $user->id, 'otp' => $otp]);

    //         // Verify OTP
    //         if ($google2fa->verifyKey($secret, $otp)) {
    //             //session(['2fa_passed' => true]); // Mark 2FA passed in session
    //             Session::put('2fa_passed', true);
    //             return response()->json([
    //                 'success' => true,
    //                 'message' => 'OTP Verified'
    //             ]);
    //         }

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Invalid OTP'
    //         ], 401);
    //     } catch (\Exception $e) {
    //         Log::error('OTP verification error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'An error occurred while verifying Google Authenticator.'
    //         ], 500);
    //     }
    // }

    /**
     * @OA\Post(
     *     path="/profile/verify-google-authenticator",
     *     summary="Verify a TOTP code and activate Google Authenticator 2FA",
     *     tags={"Profile"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"code"},
     *             @OA\Property(property="code", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Google Authenticator verified and activated"),
     *     @OA\Response(response=422, description="Invalid OTP code"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function verifyGoogleAuthenticator(Request $request)
    {
        try {
            $otp = $request->input('digit1') .
                $request->input('digit2') .
                $request->input('digit3') .
                $request->input('digit4') .
                $request->input('digit5') .
                $request->input('digit6');

            if (!preg_match('/^\d{6}$/', $otp)) {
                return response()->json([
                    'success' => false,
                    'message' => 'OTP must be a 6-digit number.'
                ], 500);
            }

            $user = User::findOrFail($request->auth->id);
            //  $email = Session('email');
            // $email = Session::get('email');
            // $user = User::where('email', $email)->first();
            $google2fa = new Google2FA();

            // You can also use $user->google2fa_secret instead of hardcoding
            $isValid = $google2fa->verifyKey($user->google_2fa_secret, $otp);
            if ($isValid) {
                // Session::put('2fa_passed', true);
                //  session(['2fa_passed' => true]);
                //  $user->last_login = now();
                $user->is_2fa_google_enabled = true;
                $user->save();
                return response()->json([
                    'success' => true,
                    'message' => 'Google Authenticator verified successfully.'
                ]);
            }
            return response()->json(['success' => false, 'message' => 'Invalid or expired code.'], 401);
        } catch (\Exception $e) {
            Log::error('Error verifying Google Authenticator', [
                'error' => $e->getMessage(),
                'user_id' => $request->user_id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while verifying Google Authenticator.'
            ], 500);
        }
    }
}

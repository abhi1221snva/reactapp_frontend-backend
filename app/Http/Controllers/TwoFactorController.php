<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\User;
use App\Exceptions\RenderableException;
use App\Services\RolesService;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Laravel\Lumen\Auth\Authorizable;
use Illuminate\Support\Facades\Hash;
use App\Http\Helper\JwtToken;
use App\Model\Dids;
use App\Model\Master\Client;
use App\Model\UserSettings;
use App\Model\Master\Permission;
use App\Model\Master\AsteriskServer;
use App\Model\Master\ForgotPasswordLink;
use App\Model\Master\OtpVerification;

use App\Model\Master\WebEmailVerification;
use App\Model\Master\WebPhoneVerification;

use App\Model\Master\WebLeads;


use App\Model\Client\UserToken;
use App\Model\Client\VoiceMailDrop;
use App\Model\Master\VoiceAi;


use Plivo\RestClient;


use Illuminate\Database\Eloquent\ModelNotFoundException;

use Illuminate\Support\Facades\Log;
use App\Model\Master\LoginLog;
use Carbon\Carbon;

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
use PragmaRX\Google2FA\Google2FA;






class TwoFactorController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function verify_google_otp(Request $request)
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


            $email = $request->input('email');;

            Log::info('Verifying OTP', ['user_key' => $email, 'otp' => $otp]);

            $user = User::where('email', $email)->first();

            Log::info('user', ['user_key' => $user->google_2fa_secret, 'otp' => $otp]);


            if (is_null($user->google_2fa_secret)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Google Authenticator setup is incomplete. Please try enabling again.'
                ], 400);
            }

            // Verify OTP
            $google2fa = new Google2FA();
            $isValid = $google2fa->verifyKey($user->google_2fa_secret, $otp);

            if ($isValid) {
                $user->is_2fa_google_enabled = 1;
                //$user->google_auth_verifyAt = now();
                $user->save();

                // $didObj = Dids::on('mysql_' . $user->parent_id)->where([["sms_email", "=", $user->id]])->first();

                // $data = $user->toArray();
                // $data['permissions'] = $user->getPermissions(true);

                // if ($didObj) {
                //     $data['did'] = $didObj->cli;
                // } else {
                //     $data['did'] = '';
                // }
                // //keep existing user table role too
                // $roleInfo = RolesService::getById($data['role']);
                // $data['role'] = $roleInfo["name"];
                // $data['level'] = $roleInfo["level"];

                // $token = JwtToken::createToken($user->id);
                // $data['token'] = $token[0];
                // $data['expires_at'] = $token[1];

                // return $data;


                return response()->json([
                    'success' => true,
                    'message' => 'Google Authenticator verified successfully.'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP. Please try again.'
            ]);
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

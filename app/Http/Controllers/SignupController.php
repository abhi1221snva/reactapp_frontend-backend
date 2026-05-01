<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionClientJob;
use App\Jobs\ReplenishPoolJob;
use App\Model\Master\EmailOtp;
use App\Model\Master\PhoneOtp;
use App\Model\Master\ProspectInitialData;
use App\Model\Master\RegistrationLog;
use App\Model\Master\RegistrationProgress;
use App\Services\ReservedPoolService;
use App\Services\SmsGatewayService;
use App\Services\TrialPackageService;
use App\Services\WelcomeEmailService;
use App\Services\MailService;
use App\Mail\SystemNotificationMail;
use App\Model\Client\SmtpSetting;
use App\Model\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Artisan;

/**
 * Streamlined signup flow (V3):
 *
 *  Step 1  POST /signup/init              — email + password + auto-send email OTP
 *  Step 2  POST /signup/verify-email-otp  — verify email OTP
 *  Step 3  POST /signup/complete-profile  — profile details + auto-send phone OTP
 *  Step 4  POST /signup/verify-phone-otp  — verify phone OTP + complete registration
 *
 *  Aux:    POST /signup/resend-otp        — unified resend (type: email|phone)
 *          POST /signup/google            — Google OAuth signup
 *          POST /signup/check-email       — email availability check
 *          GET  /signup/status/{id}       — slow-path provisioning poll
 */
class SignupController extends Controller
{
    // ----------------------------------------------------------------
    // STEP 1 — Email + Password + Auto-Send Email OTP
    // ----------------------------------------------------------------

    public function init(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'                 => 'required|email|max:255',
            'password'              => 'required|string|min:10|max:64',
            'password_confirmation' => 'required|same:password',
        ], [
            'email.required'                 => 'Email address is required.',
            'email.email'                    => 'Please enter a valid email address.',
            'password.min'                   => 'Password must be at least 10 characters.',
            'password_confirmation.required' => 'Please confirm your password.',
            'password_confirmation.same'     => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim($request->input('email')));

        // ── Email uniqueness check against registered users ──────────
        $blocked = $this->checkEmailBlocked($email);
        if ($blocked) {
            return $blocked;
        }

        // ── Rate-limit: max 5 pending OTPs per email per 15 min ──────
        $recentCount = EmailOtp::where('email', $email)
            ->where('verified', false)
            ->where('expires_at', '>', Carbon::now()->subMinutes(15))
            ->count();

        if ($recentCount >= 5) {
            return response()->json([
                'status'  => false,
                'message' => 'Too many OTP requests. Please wait 15 minutes before trying again.',
            ], 429);
        }

        try {
            // Reuse existing prospect with same email or create new
            $prospect = ProspectInitialData::where('email', $email)->first();
            if (!$prospect) {
                $prospect = new ProspectInitialData();
            }
            $prospect->email    = $email;
            $prospect->password = Hash::make($request->input('password'));
            $prospect->save();

            // Clean expired OTPs
            EmailOtp::where('email', $email)->where('expires_at', '<', Carbon::now())->delete();

            // Generate and store OTP
            $otp = $this->generateOtp();
            EmailOtp::create([
                'email'      => $email,
                'otp'        => Hash::make($otp),
                'expires_at' => Carbon::now()->addMinutes(5),
                'verified'   => false,
                'attempts'   => 0,
            ]);

            // Send verification email
            $this->dispatchVerificationEmail($email, $otp);

            RegistrationLog::log(
                RegistrationLog::STEP_STARTED,
                $email, null,
                ['email' => $email],
                ['registration_id' => $prospect->id],
                RegistrationLog::STATUS_SUCCESS,
                $prospect->id
            );

            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_OTP_SENT,
                $email, null,
                ['registration_id' => $prospect->id, 'email' => $email],
                ['otp_sent' => true],
                RegistrationLog::STATUS_SUCCESS,
                $prospect->id
            );

            return response()->json([
                'status'  => true,
                'message' => 'Verification code sent to your email.',
                'data'    => ['registration_id' => $prospect->id],
            ]);
        } catch (\Throwable $e) {
            Log::error('SignupController::init error', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => false,
                'message' => 'Failed to initialize signup. Please try again.',
            ], 500);
        }
    }

    // ----------------------------------------------------------------
    // STEP 2 — Verify Email OTP
    // ----------------------------------------------------------------

    public function verifyEmailOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'registration_id' => 'required|integer|exists:master.prospect_initial_data,id',
            'email'           => 'required|email',
            'otp'             => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $email          = strtolower(trim($request->input('email')));
        $otp            = $request->input('otp');
        $registrationId = (int) $request->input('registration_id');

        $record = EmailOtp::latestForEmail($email);

        if (!$record) {
            return response()->json([
                'status'  => false,
                'message' => 'No pending OTP found for this email. Please request a new one.',
            ], 404);
        }

        // Attempt limit
        if ($record->attempts >= 5) {
            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_VERIFIED,
                $email, null,
                ['email' => $email],
                ['error' => 'Max attempts exceeded'],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            return response()->json([
                'status'  => false,
                'message' => 'Maximum OTP attempts exceeded. Please request a new OTP.',
            ], 429);
        }

        $record->recordAttempt();

        // Expiry check
        if (Carbon::now()->gt($record->expires_at)) {
            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_VERIFIED,
                $email, null,
                ['email' => $email],
                ['error' => 'OTP expired'],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            return response()->json([
                'status'  => false,
                'code'    => 'OTP_EXPIRED',
                'message' => 'OTP has expired. Please request a new one.',
            ], 400);
        }

        // OTP match check
        if (!Hash::check($otp, $record->otp)) {
            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_VERIFIED,
                $email, null,
                ['email' => $email],
                ['error' => 'Invalid OTP', 'attempts' => $record->attempts],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            $remaining = max(0, 5 - $record->attempts);
            return response()->json([
                'status'  => false,
                'code'    => 'INVALID_OTP',
                'message' => "Invalid OTP. {$remaining} attempt(s) remaining.",
            ], 400);
        }

        $record->markVerified();

        RegistrationLog::log(
            RegistrationLog::STEP_EMAIL_VERIFIED,
            $email, null,
            ['email' => $email],
            ['verified' => true],
            RegistrationLog::STATUS_SUCCESS,
            $registrationId
        );

        return response()->json([
            'status'  => true,
            'message' => 'Email verified successfully.',
            'data'    => ['email_verified' => true],
        ]);
    }

    // ----------------------------------------------------------------
    // STEP 3 — Complete Profile + Auto-Send Phone OTP
    // ----------------------------------------------------------------

    public function completeProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'registration_id' => 'required|integer|exists:master.prospect_initial_data,id',
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'country_code'    => 'required|string|max:5',
            'phone'           => 'required|digits_between:7,15',
        ], [
            'first_name.required' => 'First name is required.',
            'last_name.required'  => 'Last name is required.',
            'phone.required'      => 'Phone number is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $registrationId = (int) $request->input('registration_id');
        $firstName      = trim($request->input('first_name'));
        $lastName       = trim($request->input('last_name'));
        $countryCode    = ltrim($request->input('country_code'), '+');
        $localPhone     = preg_replace('/[^0-9]/', '', $request->input('phone'));

        if (strlen($localPhone) < 7 || strlen($localPhone) > 15) {
            return response()->json([
                'status'  => false,
                'message' => 'Phone number must be between 7 and 15 digits.',
            ], 422);
        }

        $e164Phone = '+' . $countryCode . $localPhone;

        // Load prospect
        $prospect = ProspectInitialData::find($registrationId);
        if (!$prospect) {
            return response()->json([
                'status'  => false,
                'message' => 'Registration session not found.',
            ], 404);
        }

        // Verify email was verified first
        $emailVerified = EmailOtp::where('email', $prospect->email)
            ->where('verified', true)
            ->exists();

        if (!$emailVerified) {
            return response()->json([
                'status'  => false,
                'code'    => 'EMAIL_NOT_VERIFIED',
                'message' => 'Please verify your email address first.',
            ], 400);
        }

        // Phone uniqueness check
        $existsInUsers = DB::connection('master')->table('users')
            ->where('mobile', $localPhone)
            ->exists();

        if ($existsInUsers) {
            RegistrationLog::log(
                RegistrationLog::STEP_PHONE_OTP_SENT,
                $prospect->email, $e164Phone,
                ['phone' => $e164Phone],
                ['error' => 'Phone already registered'],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            return response()->json([
                'status'  => false,
                'code'    => 'PHONE_ALREADY_REGISTERED',
                'message' => 'An account with this phone number already exists.',
            ], 422);
        }

        // Rate-limit phone OTPs
        $recentCount = PhoneOtp::where('phone', $e164Phone)
            ->where('verified', false)
            ->where('expires_at', '>', Carbon::now()->subMinutes(15))
            ->count();

        if ($recentCount >= 5) {
            return response()->json([
                'status'  => false,
                'message' => 'Too many OTP requests. Please wait 15 minutes before trying again.',
            ], 429);
        }

        try {
            // Update prospect with profile data
            $prospect->first_name   = $firstName;
            $prospect->last_name    = $lastName;
            $prospect->name         = $firstName . ' ' . $lastName;
            $prospect->company_name = $prospect->company_name ?: ($firstName . "'s Business");
            $prospect->phone_number = $localPhone;
            $prospect->country_code = '+' . $countryCode;
            $prospect->save();

            // Clean expired phone OTPs
            PhoneOtp::where('phone', $e164Phone)->where('expires_at', '<', Carbon::now())->delete();

            // Generate and store phone OTP
            $otp = $this->generateOtp();
            PhoneOtp::create([
                'phone'      => $e164Phone,
                'otp'        => Hash::make($otp),
                'expires_at' => Carbon::now()->addMinutes(5),
                'verified'   => false,
                'attempts'   => 0,
            ]);

            // Send SMS
            $sms    = new SmsGatewayService();
            $result = $sms->sendOtp($e164Phone, $otp);

            if (!$result['success']) {
                Log::warning('SignupController::completeProfile SMS failed', $result);
            }

            RegistrationLog::log(
                RegistrationLog::STEP_PHONE_OTP_SENT,
                $prospect->email, $e164Phone,
                ['registration_id' => $registrationId, 'phone' => $e164Phone],
                ['otp_sent' => true, 'sms_result' => $result],
                RegistrationLog::STATUS_SUCCESS,
                $registrationId
            );

            return response()->json([
                'status'  => true,
                'message' => 'Profile saved. Verification SMS sent.',
                'data'    => ['registration_id' => $registrationId],
            ]);
        } catch (\Throwable $e) {
            Log::error('SignupController::completeProfile error', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => false,
                'message' => 'Failed to save profile. Please try again.',
            ], 500);
        }
    }

    // ----------------------------------------------------------------
    // STEP 4 — Verify Phone OTP + Complete Registration
    // ----------------------------------------------------------------

    public function verifyPhoneOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'registration_id' => 'required|integer|exists:master.prospect_initial_data,id',
            'phone'           => 'required|string|max:20',
            'otp'             => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $registrationId = (int) $request->input('registration_id');
        $phone          = $request->input('phone'); // E.164
        $otp            = $request->input('otp');

        $record = PhoneOtp::latestForPhone($phone);

        if (!$record) {
            return response()->json([
                'status'  => false,
                'message' => 'No pending OTP found for this phone. Please request a new one.',
            ], 404);
        }

        // Attempt limit
        if ($record->attempts >= 5) {
            return response()->json([
                'status'  => false,
                'message' => 'Maximum OTP attempts exceeded. Please request a new OTP.',
            ], 429);
        }

        $record->recordAttempt();

        // Expiry
        if (Carbon::now()->gt($record->expires_at)) {
            RegistrationLog::log(
                RegistrationLog::STEP_PHONE_VERIFIED,
                null, $phone,
                ['phone' => $phone],
                ['error' => 'OTP expired'],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            return response()->json([
                'status'  => false,
                'code'    => 'OTP_EXPIRED',
                'message' => 'OTP has expired. Please request a new one.',
            ], 400);
        }

        // Match
        if (!Hash::check($otp, $record->otp)) {
            RegistrationLog::log(
                RegistrationLog::STEP_PHONE_VERIFIED,
                null, $phone,
                ['phone' => $phone],
                ['error' => 'Invalid OTP', 'attempts' => $record->attempts],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            $remaining = max(0, 5 - $record->attempts);
            return response()->json([
                'status'  => false,
                'code'    => 'INVALID_OTP',
                'message' => "Invalid OTP. {$remaining} attempt(s) remaining.",
            ], 400);
        }

        $record->markVerified();

        RegistrationLog::log(
            RegistrationLog::STEP_PHONE_VERIFIED,
            null, $phone,
            ['phone' => $phone],
            ['verified' => true],
            RegistrationLog::STATUS_SUCCESS,
            $registrationId
        );

        // Load prospect
        $prospect = ProspectInitialData::find($registrationId);
        if (!$prospect) {
            return response()->json([
                'status'  => false,
                'message' => 'Registration session not found.',
            ], 404);
        }

        // Ensure email was verified
        $emailVerified = EmailOtp::where('email', $prospect->email)
            ->where('verified', true)
            ->exists();

        if (!$emailVerified) {
            return response()->json([
                'status'  => false,
                'message' => 'Email verification is required before completing registration.',
            ], 400);
        }

        return $this->completeRegistration($prospect, $phone);
    }

    // ----------------------------------------------------------------
    // UNIFIED RESEND OTP
    // ----------------------------------------------------------------

    public function resendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'registration_id' => 'required|integer|exists:master.prospect_initial_data,id',
            'type'            => 'required|in:email,phone',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $registrationId = (int) $request->input('registration_id');
        $type           = $request->input('type');

        $prospect = ProspectInitialData::find($registrationId);
        if (!$prospect) {
            return response()->json([
                'status'  => false,
                'message' => 'Registration session not found.',
            ], 404);
        }

        if ($type === 'email') {
            return $this->resendEmailOtp($prospect);
        }

        return $this->resendPhoneOtp($prospect);
    }

    private function resendEmailOtp(ProspectInitialData $prospect)
    {
        $email = $prospect->email;
        if (!$email) {
            return response()->json([
                'status'  => false,
                'message' => 'No email address found for this registration.',
            ], 400);
        }

        // Rate-limit
        $recentCount = EmailOtp::where('email', $email)
            ->where('verified', false)
            ->where('expires_at', '>', Carbon::now()->subMinutes(15))
            ->count();

        if ($recentCount >= 5) {
            return response()->json([
                'status'  => false,
                'message' => 'Too many OTP requests. Please wait 15 minutes before trying again.',
            ], 429);
        }

        try {
            EmailOtp::where('email', $email)->where('expires_at', '<', Carbon::now())->delete();

            $otp = $this->generateOtp();
            EmailOtp::create([
                'email'      => $email,
                'otp'        => Hash::make($otp),
                'expires_at' => Carbon::now()->addMinutes(5),
                'verified'   => false,
                'attempts'   => 0,
            ]);

            $this->dispatchVerificationEmail($email, $otp);

            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_OTP_SENT,
                $email, null,
                ['registration_id' => $prospect->id, 'resend' => true],
                ['otp_sent' => true],
                RegistrationLog::STATUS_SUCCESS,
                $prospect->id
            );

            return response()->json([
                'status'  => true,
                'message' => 'Verification code resent to your email.',
            ]);
        } catch (\Throwable $e) {
            Log::error('SignupController::resendEmailOtp error', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => false,
                'message' => 'Failed to resend OTP. Please try again.',
            ], 500);
        }
    }

    private function resendPhoneOtp(ProspectInitialData $prospect)
    {
        $localPhone  = $prospect->phone_number;
        $countryCode = ltrim($prospect->country_code ?? '+1', '+');

        if (!$localPhone) {
            return response()->json([
                'status'  => false,
                'message' => 'No phone number found. Please complete your profile first.',
            ], 400);
        }

        $e164Phone = '+' . $countryCode . $localPhone;

        // Rate-limit
        $recentCount = PhoneOtp::where('phone', $e164Phone)
            ->where('verified', false)
            ->where('expires_at', '>', Carbon::now()->subMinutes(15))
            ->count();

        if ($recentCount >= 5) {
            return response()->json([
                'status'  => false,
                'message' => 'Too many OTP requests. Please wait 15 minutes before trying again.',
            ], 429);
        }

        try {
            PhoneOtp::where('phone', $e164Phone)->where('expires_at', '<', Carbon::now())->delete();

            $otp = $this->generateOtp();
            PhoneOtp::create([
                'phone'      => $e164Phone,
                'otp'        => Hash::make($otp),
                'expires_at' => Carbon::now()->addMinutes(5),
                'verified'   => false,
                'attempts'   => 0,
            ]);

            $sms    = new SmsGatewayService();
            $result = $sms->sendOtp($e164Phone, $otp);

            if (!$result['success']) {
                Log::warning('SignupController::resendPhoneOtp SMS failed', $result);
            }

            RegistrationLog::log(
                RegistrationLog::STEP_PHONE_OTP_SENT,
                $prospect->email, $e164Phone,
                ['registration_id' => $prospect->id, 'resend' => true],
                ['otp_sent' => true, 'sms_result' => $result],
                RegistrationLog::STATUS_SUCCESS,
                $prospect->id
            );

            return response()->json([
                'status'  => true,
                'message' => 'Verification SMS resent.',
            ]);
        } catch (\Throwable $e) {
            Log::error('SignupController::resendPhoneOtp error', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => false,
                'message' => 'Failed to resend OTP. Please try again.',
            ], 500);
        }
    }

    // ----------------------------------------------------------------
    // GOOGLE OAUTH SIGNUP
    // ----------------------------------------------------------------

    public function googleSignup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'credential'    => 'required|string',
            'business_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $tokenInfo = $this->verifyGoogleToken($request->input('credential'));
            if (!$tokenInfo) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid or expired Google token. Please try again.',
                ], 401);
            }

            $email = strtolower(trim($tokenInfo['email'] ?? ''));
            $name  = trim($tokenInfo['name'] ?? $tokenInfo['given_name'] ?? $email);

            if (empty($email)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Google account email could not be verified.',
                ], 400);
            }

            // Email uniqueness check
            $blocked = $this->checkEmailBlocked($email);
            if ($blocked) {
                return $blocked;
            }

            // Reuse existing prospect or create new
            $prospect = ProspectInitialData::where('email', $email)->first();
            if (!$prospect) {
                $prospect        = new ProspectInitialData();
                $prospect->name  = $name;
                $prospect->email = $email;
            }
            $prospect->company_name = $request->input('business_name');
            $tempPassword           = \Illuminate\Support\Str::random(12);
            $prospect->password     = Hash::make($tempPassword);
            $prospect->save();

            // Pre-verify email (Google already verified it)
            EmailOtp::where('email', $email)->delete();
            EmailOtp::create([
                'email'      => $email,
                'otp'        => Hash::make('000000'),
                'expires_at' => Carbon::now()->addHours(24),
                'verified'   => true,
                'attempts'   => 0,
            ]);

            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_VERIFIED,
                $email, null,
                ['source' => 'google_oauth', 'business_name' => $request->input('business_name')],
                ['registration_id' => $prospect->id],
                RegistrationLog::STATUS_SUCCESS,
                $prospect->id
            );

            return response()->json([
                'status'  => true,
                'message' => 'Google account verified. Please complete your profile.',
                'data'    => [
                    'registration_id' => $prospect->id,
                    'name'            => $name,
                    'email'           => $email,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SignupController::googleSignup error', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => false,
                'message' => 'Google signup failed. Please try again.',
            ], 500);
        }
    }

    // ----------------------------------------------------------------
    // EMAIL AVAILABILITY CHECK
    // ----------------------------------------------------------------

    public function checkEmail(Request $request)
    {
        $this->validate($request, ['email' => 'required|email']);

        $email   = strtolower(trim($request->input('email')));
        $blocked = $this->checkEmailBlocked($email);

        if ($blocked) {
            return $blocked;
        }

        return response()->json([
            'status'  => true,
            'message' => 'Email is available.',
        ]);
    }

    // ----------------------------------------------------------------
    // SLOW-PATH PROVISIONING STATUS
    // ----------------------------------------------------------------

    public function registrationStatus(Request $request, $id)
    {
        $progress = RegistrationProgress::find($id);

        if (!$progress) {
            return response()->json([
                'status'  => false,
                'message' => 'Progress record not found.',
            ], 404);
        }

        $data = [
            'stage'        => $progress->stage,
            'progress_pct' => $progress->progress_pct,
            'path'         => $progress->path,
            'ready'        => $progress->stage === RegistrationProgress::STAGE_COMPLETED,
            'failed'       => $progress->stage === RegistrationProgress::STAGE_FAILED,
        ];

        if ($progress->stage === RegistrationProgress::STAGE_COMPLETED) {
            $data['client_id'] = $progress->client_id;
            $data['user_id']   = $progress->user_id;
        }

        if ($progress->stage === RegistrationProgress::STAGE_FAILED) {
            $data['error_message'] = 'Account setup encountered an issue. Please contact support or try again.';
            $data['retry_count']   = $progress->retry_count;
        }

        $stageLabels = [
            'queued'            => 'Waiting in queue...',
            'creating_record'   => 'Creating your account...',
            'creating_database' => 'Setting up your workspace...',
            'seeding_data'      => 'Configuring your CRM...',
            'assigning_trial'   => 'Activating your trial...',
            'sending_welcome'   => 'Almost done...',
            'completed'         => 'All set!',
            'failed'            => 'Setup encountered an issue.',
        ];
        $data['stage_label'] = $stageLabels[$progress->stage] ?? $progress->stage;

        return response()->json([
            'status' => true,
            'data'   => $data,
        ]);
    }

    // ================================================================
    // PRIVATE HELPERS
    // ================================================================

    /**
     * Check if an email is already registered. Returns a JsonResponse
     * if blocked, null if available.
     */
    private function checkEmailBlocked(string $email): ?\Illuminate\Http\JsonResponse
    {
        $existingUser = DB::connection('master')->table('users')
            ->where('email', $email)
            ->select('is_deleted', 'status')
            ->first();

        if (!$existingUser) {
            return null;
        }

        if ($existingUser->is_deleted) {
            return response()->json([
                'status'  => false,
                'code'    => 'ACCOUNT_DEACTIVATED',
                'message' => 'This account has been deactivated. Please contact support.',
            ], 422);
        }

        if (isset($existingUser->status) && $existingUser->status == 0) {
            return response()->json([
                'status'  => false,
                'code'    => 'ACCOUNT_INACTIVE',
                'message' => 'This account is not active. Please contact support.',
            ], 422);
        }

        return response()->json([
            'status'  => false,
            'code'    => 'EMAIL_ALREADY_REGISTERED',
            'message' => 'An account with this email already exists. Please sign in instead.',
        ], 422);
    }

    /**
     * Smart registration router — Fast path (reserved pool) or Slow path (provision job).
     */
    private function completeRegistration($prospect, string $e164Phone): \Illuminate\Http\JsonResponse
    {
        try {
            // ── FAST PATH — Try reserved pool ────────────────────────────
            $poolService = new ReservedPoolService();
            $claimed     = $poolService->claimSlot($prospect, $e164Phone);

            if ($claimed) {
                $clientId = $claimed['client_id'];
                $userId   = $claimed['user_id'];

                // Generate JWT for auto-login
                $autoLoginToken = null;
                try {
                    $user = User::find($userId);
                    if ($user) {
                        $auth        = new \App\Model\Authentication();
                        $tokenResult = $auth->loginByUserId($userId);
                        $autoLoginToken = $tokenResult['token'] ?? null;
                    }
                } catch (\Throwable $e) {
                    Log::warning('SignupController: auto-login token failed', [
                        'user_id' => $userId, 'error' => $e->getMessage(),
                    ]);
                }

                RegistrationLog::log(
                    RegistrationLog::STEP_USER_CREATED,
                    $prospect->email, $e164Phone,
                    ['registration_id' => $prospect->id],
                    ['user_id' => $userId, 'client_id' => $clientId, 'path' => 'fast'],
                    RegistrationLog::STATUS_SUCCESS,
                    $prospect->id
                );

                RegistrationLog::log(
                    RegistrationLog::STEP_CLIENT_DB_CREATED,
                    $prospect->email, $e164Phone,
                    ['client_id' => $clientId],
                    ['db_name' => 'client_' . $clientId, 'path' => 'fast'],
                    RegistrationLog::STATUS_SUCCESS,
                    $prospect->id
                );

                // Trial assignment (non-blocking)
                try {
                    $trialSvc = new TrialPackageService();
                    $trialSvc->assignTrial($clientId, $userId);
                } catch (\Throwable $e) {
                    Log::error('SignupController: trial assignment failed', [
                        'client_id' => $clientId, 'error' => $e->getMessage(),
                    ]);
                }

                // Welcome email (non-blocking)
                try {
                    $welcomeService = new WelcomeEmailService();
                    $welcomeService->sendWelcome(
                        email:    $prospect->email,
                        name:     $prospect->name ?? 'User',
                        loginUrl: env('PORTAL_NAME', '#'),
                        password: null
                    );
                } catch (\Throwable $e) {
                    Log::error('SignupController: welcome email failed', [
                        'email' => $prospect->email, 'error' => $e->getMessage(),
                    ]);
                }

                try {
                    Artisan::call('cache:clear');
                } catch (\Throwable $e) {
                    // Non-fatal
                }

                RegistrationLog::log(
                    RegistrationLog::STEP_COMPLETED,
                    $prospect->email, $e164Phone,
                    ['registration_id' => $prospect->id],
                    ['user_id' => $userId, 'client_id' => $clientId, 'path' => 'fast'],
                    RegistrationLog::STATUS_SUCCESS,
                    $prospect->id
                );

                // Replenish pool if low
                if ($poolService->needsReplenish()) {
                    dispatch(new ReplenishPoolJob())->onConnection('database')->onQueue('clients');
                }

                $responseData = [
                    'path'      => 'fast',
                    'user_id'   => $userId,
                    'client_id' => $clientId,
                    'ready'     => true,
                ];

                if ($autoLoginToken) {
                    $responseData['token'] = $autoLoginToken;
                    $freshUser = User::find($userId);
                    if ($freshUser) {
                        $responseData['user'] = [
                            'id'            => $freshUser->id,
                            'parent_id'     => $freshUser->parent_id,
                            'first_name'    => $freshUser->first_name,
                            'last_name'     => $freshUser->last_name,
                            'email'         => $freshUser->email,
                            'level'         => $freshUser->level ?? 6,
                            'extension'     => $freshUser->extension,
                            'alt_extension' => $freshUser->alt_extension,
                        ];
                    }
                }

                return response()->json([
                    'status'  => true,
                    'message' => 'Registration complete!',
                    'data'    => $responseData,
                ]);
            }

            // ── SLOW PATH — Provision from scratch ───────────────────────
            Log::info('SignupController: no reserved slot — using slow path', [
                'registration_id' => $prospect->id,
            ]);

            $progress = RegistrationProgress::create([
                'registration_id' => $prospect->id,
                'email'           => $prospect->email,
                'phone'           => $e164Phone,
                'path'            => 'slow',
                'stage'           => RegistrationProgress::STAGE_QUEUED,
                'progress_pct'    => 5,
            ]);

            dispatch(new ProvisionClientJob(
                progressId:     $progress->id,
                registrationId: $prospect->id,
                name:           $prospect->name ?? 'User',
                email:          $prospect->email,
                companyName:    $prospect->company_name,
                hashedPassword: $prospect->password,
                e164Phone:      $e164Phone
            ))->onConnection('database')->onQueue('clients');

            RegistrationLog::log(
                RegistrationLog::STEP_USER_CREATED,
                $prospect->email, $e164Phone,
                ['registration_id' => $prospect->id],
                ['path' => 'slow', 'progress_id' => $progress->id],
                RegistrationLog::STATUS_SUCCESS,
                $prospect->id
            );

            return response()->json([
                'status'  => true,
                'message' => 'Your account is being set up. This usually takes about 30 seconds.',
                'data'    => [
                    'path'        => 'slow',
                    'progress_id' => $progress->id,
                    'ready'       => false,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SignupController::completeRegistration error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            RegistrationLog::log(
                RegistrationLog::STEP_COMPLETED,
                $prospect->email ?? null,
                $e164Phone,
                ['registration_id' => $prospect->id ?? null],
                ['error' => $e->getMessage()],
                RegistrationLog::STATUS_FAILURE,
                $prospect->id ?? null
            );

            return response()->json([
                'status'  => false,
                'message' => 'Registration failed. Please contact support.',
            ], 500);
        }
    }

    /** Generate 6-digit OTP (or use DEV_STATIC_OTP env var). */
    private function generateOtp(): string
    {
        return env('DEV_STATIC_OTP')
            ? (string) env('DEV_STATIC_OTP')
            : (string) mt_rand(100000, 999999);
    }

    /** Send email OTP via portal SMTP. */
    private function dispatchVerificationEmail(string $email, string $otp): void
    {
        try {
            $smtpSetting                  = new SmtpSetting();
            $smtpSetting->mail_driver     = 'SMTP';
            $smtpSetting->mail_host       = env('PORTAL_MAIL_HOST');
            $smtpSetting->mail_port       = env('PORTAL_MAIL_PORT');
            $smtpSetting->mail_username   = env('PORTAL_MAIL_USERNAME');
            $smtpSetting->mail_password   = env('PORTAL_MAIL_PASSWORD');
            $smtpSetting->from_name       = env('PORTAL_MAIL_SENDER_NAME');
            $smtpSetting->from_email      = env('PORTAL_MAIL_SENDER_EMAIL');
            $smtpSetting->mail_encryption = env('PORTAL_MAIL_ENCRYPTION');

            $fromAddress = empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email;
            $fromName    = empty($smtpSetting->from_name)  ? env('DEFAULT_NAME')  : $smtpSetting->from_name;

            $subject = 'Verify your email — ' . env('SITE_NAME', 'Dialer');

            // Render template directly and send via Symfony mailer
            // (bypasses SystemNotificationMail::build() double-call issue)
            $html = view('emails.email-verification-otp', [
                'subject' => $subject,
                'data'    => ['name' => $email, 'code' => $otp],
            ])->render();

            $dsn = sprintf(
                'smtp://%s:%s@%s:%d?encryption=%s',
                urlencode($smtpSetting->mail_username),
                urlencode($smtpSetting->mail_password),
                $smtpSetting->mail_host,
                $smtpSetting->mail_port,
                $smtpSetting->mail_encryption ?? 'tls'
            );

            $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
            $mailer    = new \Symfony\Component\Mailer\Mailer($transport);

            $message = (new \Symfony\Component\Mime\Email())
                ->from(sprintf('%s <%s>', $fromName, $fromAddress))
                ->to($email)
                ->subject($subject)
                ->html($html);

            $mailer->send($message);

            Log::info('SignupController: verification email sent', ['email' => $email]);
        } catch (\Throwable $e) {
            Log::error('SignupController: verification email failed', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /** Verify Google ID token via tokeninfo endpoint. */
    private function verifyGoogleToken(string $idToken): ?array
    {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 10, 'http_errors' => false]);
            $response = $client->get('https://oauth2.googleapis.com/tokeninfo', [
                'query' => ['id_token' => $idToken],
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $info = json_decode((string) $response->getBody(), true);
            if (!isset($info['sub'], $info['email'], $info['aud'])) {
                return null;
            }
            $clientId = env('GOOGLE_CLIENT_ID');
            if ($info['aud'] !== $clientId) {
                Log::warning('SignupController: Google token audience mismatch', [
                    'aud' => $info['aud'], 'expected' => $clientId,
                ]);
                return null;
            }
            if (isset($info['exp']) && (int) $info['exp'] < time()) {
                return null;
            }
            return $info;
        } catch (\Exception $e) {
            Log::error('SignupController::verifyGoogleToken error', ['message' => $e->getMessage()]);
            return null;
        }
    }
}

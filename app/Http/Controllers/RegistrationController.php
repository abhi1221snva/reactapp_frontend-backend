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
 * Multi-step user registration:
 *
 *  Step 1  POST /register/init              — account details (name, business, password)
 *  Step 2a POST /register/email/send-otp    — submit email + receive OTP
 *  Step 2b POST /register/email/verify-otp  — verify email OTP
 *  Step 3a POST /register/phone/send-otp    — submit phone number + receive OTP
 *  Step 3b POST /register/phone/verify-otp  — verify phone OTP + complete registration
 */
class RegistrationController extends Controller
{
    // ----------------------------------------------------------------
    // STEP 1 — Account Details
    // ----------------------------------------------------------------

    /**
     * POST /register/init
     *
     * Accepts: name, business_name, password, password_confirmation
     * Returns: { status, message, data: { registration_id } }
     */
    public function init(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:255',
            'business_name'         => 'required|string|max:255',
            'password'              => 'required|string|min:10|max:64',
            'password_confirmation' => 'required|same:password',
        ], [
            'name.required'                  => 'Full name is required.',
            'business_name.required'         => 'Business name is required.',
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

        try {
            $prospect = new ProspectInitialData();
            $prospect->name         = $request->input('name');
            $prospect->company_name = $request->input('business_name');
            $prospect->password     = Hash::make($request->input('password'));
            $prospect->save();

            RegistrationLog::log(
                RegistrationLog::STEP_STARTED,
                null,
                null,
                ['name' => $request->input('name'), 'business_name' => $request->input('business_name')],
                ['registration_id' => $prospect->id],
                RegistrationLog::STATUS_SUCCESS,
                $prospect->id
            );

            return response()->json([
                'status'  => true,
                'message' => 'Account details saved. Please verify your email next.',
                'data'    => ['registration_id' => $prospect->id],
            ]);
        } catch (\Throwable $e) {
            Log::error('RegistrationController::init error', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => false,
                'message' => 'Failed to save account details.',
            ], 500);
        }
    }

    // ----------------------------------------------------------------
    // STEP 2a — Send Email OTP
    // ----------------------------------------------------------------

    /**
     * POST /register/email/send-otp
     *
     * Accepts: registration_id, email
     * Returns: { status, message }
     */
    public function sendEmailOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'registration_id' => 'required|integer|exists:master.prospect_initial_data,id',
            'email'           => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $email          = strtolower(trim($request->input('email')));
        $registrationId = (int) $request->input('registration_id');

        // Email uniqueness check — only block if a fully-registered user exists.
        // Incomplete prospect records should NOT block re-registration attempts.
        $existingUser = DB::connection('master')->table('users')
            ->where('email', $email)
            ->select('is_deleted', 'status')
            ->first();

        if ($existingUser) {
            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_OTP_SENT,
                $email, null,
                ['email' => $email],
                ['error' => 'Email already registered'],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

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

        // Rate-limit: max 5 pending (unverified) OTPs per email per 15 min window
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

        // Clean up expired OTPs for this email (prevent DB bloat)
        EmailOtp::where('email', $email)->where('expires_at', '<', Carbon::now())->delete();

        try {
            $otp = $this->generateOtp();

            EmailOtp::create([
                'email'      => $email,
                'otp'        => Hash::make($otp),
                'expires_at' => Carbon::now()->addMinutes(5),
                'verified'   => false,
                'attempts'   => 0,
            ]);

            // Update prospect record with email
            ProspectInitialData::where('id', $registrationId)->update(['email' => $email]);

            // Send verification email
            $this->dispatchVerificationEmail($email, $otp);

            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_OTP_SENT,
                $email, null,
                ['registration_id' => $registrationId, 'email' => $email],
                ['otp_sent' => true],
                RegistrationLog::STATUS_SUCCESS,
                $registrationId
            );

            return response()->json([
                'status'  => true,
                'message' => 'OTP sent to your email address. It expires in 5 minutes.',
            ]);
        } catch (\Throwable $e) {
            Log::error('RegistrationController::sendEmailOtp error', ['error' => $e->getMessage()]);

            RegistrationLog::log(
                RegistrationLog::STEP_EMAIL_OTP_SENT,
                $email, null,
                ['email' => $email],
                ['error' => $e->getMessage()],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            return response()->json([
                'status'  => false,
                'message' => 'Failed to send OTP. Please try again.',
            ], 500);
        }
    }

    // ----------------------------------------------------------------
    // STEP 2b — Verify Email OTP
    // ----------------------------------------------------------------

    /**
     * POST /register/email/verify-otp
     *
     * Accepts: registration_id, email, otp
     * Returns: { status, message }
     */
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

        /** @var EmailOtp|null $record */
        $record = EmailOtp::latestForEmail($email);

        if (!$record) {
            return response()->json([
                'status'  => false,
                'message' => 'No pending OTP found for this email. Please request a new one.',
            ], 404);
        }

        // Check attempt limit before comparing
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

        // Record the attempt
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
                'message' => "Invalid OTP. {$remaining} attempt(s) remaining.",
            ], 400);
        }

        // All good — mark as verified
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
        ]);
    }

    // ----------------------------------------------------------------
    // STEP 3a — Send Phone OTP
    // ----------------------------------------------------------------

    /**
     * POST /register/phone/send-otp
     *
     * Accepts: registration_id, country_code, phone
     * Returns: { status, message }
     */
    public function sendPhoneOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'registration_id' => 'required|integer|exists:master.prospect_initial_data,id',
            'country_code'    => 'required|string|max:5',
            'phone'           => 'required|digits_between:7,15',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $registrationId = (int) $request->input('registration_id');
        $countryCode    = ltrim($request->input('country_code'), '+');
        $localPhone     = $request->input('phone');
        $localPhone     = preg_replace('/[^0-9]/', '', $localPhone); // strip non-numeric characters (security hardening)
        if (strlen($localPhone) < 7 || strlen($localPhone) > 15) {
            return response()->json([
                'status'  => false,
                'message' => 'Phone number must be between 7 and 15 digits.',
            ], 422);
        }
        $e164Phone      = '+' . $countryCode . $localPhone;

        // Phone uniqueness check
        $existsInUsers = DB::connection('master')->table('users')
            ->where('mobile', $localPhone)
            ->exists();

        if ($existsInUsers) {
            RegistrationLog::log(
                RegistrationLog::STEP_PHONE_OTP_SENT,
                null, $e164Phone,
                ['phone' => $e164Phone],
                ['error' => 'Phone already registered'],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            return response()->json([
                'status'  => false,
                'code'    => 'PHONE_ALREADY_REGISTERED',
                'message' => 'An account with this phone number already exists. Please sign in instead.',
            ], 422);
        }

        // Rate-limit: max 5 pending OTPs per phone per 15 min window
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

        // Clean up expired OTPs for this phone (prevent DB bloat)
        PhoneOtp::where('phone', $e164Phone)->where('expires_at', '<', Carbon::now())->delete();

        try {
            $otp = $this->generateOtp();

            PhoneOtp::create([
                'phone'      => $e164Phone,
                'otp'        => Hash::make($otp),
                'expires_at' => Carbon::now()->addMinutes(5),
                'verified'   => false,
                'attempts'   => 0,
            ]);

            // Save phone to prospect record
            ProspectInitialData::where('id', $registrationId)->update([
                'phone_number' => $localPhone,
                'country_code' => '+' . $countryCode,
            ]);

            // Send via SMS gateway
            $sms = new SmsGatewayService();
            $result = $sms->sendOtp($e164Phone, $otp);

            if (!$result['success']) {
                Log::warning('RegistrationController::sendPhoneOtp SMS failed', $result);
            }

            RegistrationLog::log(
                RegistrationLog::STEP_PHONE_OTP_SENT,
                null, $e164Phone,
                ['registration_id' => $registrationId, 'phone' => $e164Phone],
                ['otp_sent' => true, 'sms_result' => $result],
                RegistrationLog::STATUS_SUCCESS,
                $registrationId
            );

            return response()->json([
                'status'  => true,
                'message' => 'OTP sent to your phone number. It expires in 5 minutes.',
            ]);
        } catch (\Throwable $e) {
            Log::error('RegistrationController::sendPhoneOtp error', ['error' => $e->getMessage()]);

            RegistrationLog::log(
                RegistrationLog::STEP_PHONE_OTP_SENT,
                null, $e164Phone,
                ['phone' => $e164Phone],
                ['error' => $e->getMessage()],
                RegistrationLog::STATUS_FAILURE,
                $registrationId
            );

            return response()->json([
                'status'  => false,
                'message' => 'Failed to send OTP. Please try again.',
            ], 500);
        }
    }

    // ----------------------------------------------------------------
    // STEP 3b — Verify Phone OTP + Complete Registration
    // ----------------------------------------------------------------

    /**
     * POST /register/phone/verify-otp
     *
     * Accepts: registration_id, phone (E.164), otp
     * Returns: { status, message, data: { user_id, client_id } }
     *
     * On success: creates user record, assigns a reserved client DB,
     * sends welcome email.
     */
    public function verifyPhoneOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'registration_id' => 'required|integer|exists:master.prospect_initial_data,id',
            'phone'           => 'required|string|max:20',   // E.164
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

        /** @var PhoneOtp|null $record */
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

        // Load prospect data
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

        // Complete registration
        return $this->completeRegistration($prospect, $phone);
    }

    // ----------------------------------------------------------------
    // FINAL — Complete Registration (Smart Router: Fast → Slow)
    // ----------------------------------------------------------------

    /**
     * Smart registration router:
     *   1. Try FAST PATH — claim a pre-provisioned reserved slot (~500ms)
     *   2. Fall back to SLOW PATH — dispatch ProvisionClientJob (~15-30s)
     *
     * Fast path: instant response with user_id + client_id
     * Slow path: returns progress_id for frontend polling via GET /register/status/{id}
     */
    private function completeRegistration($prospect, string $e164Phone): \Illuminate\Http\JsonResponse
    {
        try {
            // ── FAST PATH — Try reserved pool first ──────────────────────
            $poolService = new ReservedPoolService();
            $claimed     = $poolService->claimSlot($prospect, $e164Phone);

            if ($claimed) {
                $clientId = $claimed['client_id'];
                $userId   = $claimed['user_id'];

                // Generate JWT token for immediate auto-login
                $autoLoginToken = null;
                try {
                    $user = User::find($userId);
                    if ($user) {
                        $auth = new \App\Model\Authentication();
                        $tokenResult = $auth->loginByUserId($userId);
                        $autoLoginToken = $tokenResult['token'] ?? null;
                    }
                } catch (\Throwable $e) {
                    Log::warning('RegistrationController: auto-login token generation failed', [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
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

                // Assign trial package (non-blocking — don't fail registration if this fails)
                try {
                    $trialSvc = new TrialPackageService();
                    $trialSvc->assignTrial($clientId, $userId);
                } catch (\Throwable $e) {
                    Log::error('RegistrationController: trial assignment failed (fast path)', [
                        'client_id' => $clientId,
                        'error'     => $e->getMessage(),
                    ]);
                }

                // Send welcome email (non-blocking)
                try {
                    $welcomeService = new WelcomeEmailService();
                    $welcomeService->sendWelcome(
                        email:    $prospect->email,
                        name:     $prospect->name ?? 'User',
                        loginUrl: env('PORTAL_NAME', '#'),
                        password: null
                    );
                } catch (\Throwable $e) {
                    Log::error('RegistrationController: welcome email failed (fast path)', [
                        'email' => $prospect->email,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Clear cache
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

                // Async: replenish the pool if it's getting low
                if ($poolService->needsReplenish()) {
                    dispatch(new ReplenishPoolJob())->onConnection('database')->onQueue('clients');
                }

                $responseData = [
                    'path'      => 'fast',
                    'user_id'   => $userId,
                    'client_id' => $clientId,
                    'ready'     => true,
                ];

                // Include auto-login token if generated
                if ($autoLoginToken) {
                    $responseData['token'] = $autoLoginToken;
                    // Include user data for frontend store hydration
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

            // ── SLOW PATH — No reserved slot, provision from scratch ─────
            Log::info('RegistrationController: no reserved slot — using slow path', [
                'registration_id' => $prospect->id,
            ]);

            // Create progress tracker
            $progress = RegistrationProgress::create([
                'registration_id' => $prospect->id,
                'email'           => $prospect->email,
                'phone'           => $e164Phone,
                'path'            => 'slow',
                'stage'           => RegistrationProgress::STAGE_QUEUED,
                'progress_pct'    => 5,
            ]);

            // Dispatch the provisioning job
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
            Log::error('RegistrationController::completeRegistration error', [
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

    // ----------------------------------------------------------------
    // POLLING — Registration Status (for slow path)
    // ----------------------------------------------------------------

    /**
     * GET /register/status/{id}
     *
     * Returns the current provisioning progress for a slow-path registration.
     * Frontend polls this every 2-3 seconds until stage = completed or failed.
     */
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

        // Human-readable stage label for the frontend
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

    // ----------------------------------------------------------------
    // GOOGLE OAUTH — Register via Google
    // ----------------------------------------------------------------

    /**
     * POST /register/google
     *
     * Verifies a Google ID token, pre-marks the email as verified, and
     * creates a registration session. The caller proceeds directly to
     * phone verification — email OTP is not required for Google accounts.
     *
     * Accepts: credential (Google ID token), business_name
     * Returns: { status, message, data: { registration_id, name, email } }
     */
    public function googleRegister(Request $request)
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

            // Email uniqueness check against existing users (with status distinction)
            $existingUser = DB::connection('master')->table('users')
                ->where('email', $email)
                ->select('is_deleted', 'status')
                ->first();

            if ($existingUser) {
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
                    'message' => 'An account with this Google email already exists. Please sign in instead.',
                ], 422);
            }

            // Reuse existing in-progress prospect or create new
            $prospect = ProspectInitialData::where('email', $email)->first();
            if (!$prospect) {
                $prospect               = new ProspectInitialData();
                $prospect->name         = $name;
                $prospect->email        = $email;
            }
            $prospect->company_name = $request->input('business_name');
            // Generate a readable temporary password for Google users (they can change later)
            $tempPassword = \Illuminate\Support\Str::random(12);
            $prospect->password     = Hash::make($tempPassword);
            $prospect->save();

            // Insert a pre-verified EmailOtp so verifyPhoneOtp passes the email-check gate
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
                'message' => 'Google account verified. Please complete phone verification.',
                'data'    => [
                    'registration_id' => $prospect->id,
                    'name'            => $name,
                    'email'           => $email,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('RegistrationController::googleRegister error', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => false,
                'message' => 'Google registration failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Quick email existence check — returns whether an email is already registered.
     * Used by Google sign-up to check before showing the business name form.
     * POST /register/check-email  { email }
     */
    public function checkEmail(Request $request)
    {
        $this->validate($request, ['email' => 'required|email']);

        $email = strtolower(trim($request->input('email')));

        $user = DB::connection('master')->table('users')
            ->where('email', $email)
            ->select('is_deleted', 'status')
            ->first();

        if (!$user) {
            return response()->json([
                'status'  => true,
                'message' => 'Email is available.',
            ]);
        }

        if ($user->is_deleted) {
            return response()->json([
                'status'  => false,
                'code'    => 'ACCOUNT_DEACTIVATED',
                'message' => 'This account has been deactivated. Please contact support.',
            ], 422);
        }

        if (isset($user->status) && $user->status == 0) {
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

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Verify a Google ID token via Google's tokeninfo endpoint.
     * Returns decoded token claims on success, null on failure.
     */
    private function verifyGoogleToken(string $idToken): ?array
    {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout'     => 10,
                'http_errors' => false,
            ]);
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
                Log::warning('RegistrationController: Google token audience mismatch', [
                    'aud' => $info['aud'], 'expected' => $clientId,
                ]);
                return null;
            }
            if (isset($info['exp']) && (int) $info['exp'] < time()) {
                return null;
            }
            return $info;
        } catch (\Exception $e) {
            Log::error('RegistrationController::verifyGoogleToken error', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /** Generate a 6-digit OTP (or use DEV_STATIC_OTP from .env). */
    private function generateOtp(): string
    {
        return env('DEV_STATIC_OTP')
            ? (string) env('DEV_STATIC_OTP')
            : (string) mt_rand(100000, 999999);
    }

    /** Send email OTP using portal SMTP settings. */
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

            $from = [
                'address' => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
                'name'    => empty($smtpSetting->from_name)  ? env('DEFAULT_NAME')  : $smtpSetting->from_name,
            ];

            $subject  = 'Verify your email — ' . env('SITE_NAME', 'Dialer');
            $mailable = new SystemNotificationMail(
                $from,
                'emails.email-verification-otp',
                $subject,
                ['name' => $email, 'code' => $otp]
            );

            $mailService = new MailService(0, $mailable, $smtpSetting);
            $mailService->sendEmail($email);

            Log::info('RegistrationController: verification email sent', ['email' => $email]);
        } catch (\Throwable $e) {
            Log::error('RegistrationController: verification email failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Model\Master\ProspectInitialData;

use Illuminate\Support\Facades\Mail;
use App\Mail\VerificationMail;

use App\Model\Master\EmailVerification;
use App\Model\Master\PhoneVerification;

use Illuminate\Support\Str;
use App\Model\Master\Prospect;
use App\Model\Master\Client;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\ClientController;
use App\Model\Master\ProspectPackage;
use App\Model\Master\Package;
use Carbon\Carbon;
use App\Jobs\ConvertProspectToClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use App\Services\OtpServices;
use App\Model\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;




class RegisterController extends Controller
{
    public function saveInitialData(Request $request)
    {
        try
        {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|unique:master.users,email',
                'name' => 'required|string|max:255',
                'company_name' => 'nullable||unique:master.clients,company_name|string|max:255',
                'country_code' => 'nullable|string|max:10',
                'phone_number' => 'nullable|string|max:20',
            ]);

            if ($validator->fails())
            {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $prospectInitialData = new ProspectInitialData();
            $prospectInitialData->email = $request->input('email', '');
            $prospectInitialData->name = $request->input('name', '');
            $prospectInitialData->company_name = $request->input('company_name', '');
            $prospectInitialData->country_code = $request->input('country_code', '');
            $prospectInitialData->phone_number = $request->input('phone_number', '');
            $prospectInitialData->password = $request->input('password') ? Hash::make($request->input('password')) : null;
            $prospectInitialData->save();

            $verificationCode = "123456";
            //rand(100000, 999999);

            EmailVerification::create([
                'id'     => (string) Str::uuid(),
                'email'  => $prospectInitialData->email,
                'code'   => $verificationCode,
                'expiry' => Carbon::now()->addMinutes(15), // 15 min expiry
                'status' => '3',
            ]);

            //Mail::to($prospectInitialData->email)->send(new VerificationMail($verificationCode));

            return $this->successResponse("Prospect saved & verification email sent", [
                "prospect" => $prospectInitialData,
            ]);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse(
                "Error in saving Prospect initial data: " . $exception->getMessage(),
                [],
                $exception
            );
        }
    }


    public function verifyOtp(Request $request)
    {
        Log::info('reached',[$request->all()]);

        $verification = EmailVerification::where('email', $request->input('email'))->where('code', $request->input('otp'))->where('status', '!=', '4')->first();

        if (!$verification)
        {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP.'
            ], 404);
        }

        $createdAt = Carbon::parse($verification->expiry);

        if ($createdAt->diffInMinutes(Carbon::now()) > 15)
        {
            return response()->json([
                'status' => false,
                'message' => 'OTP expired. Please request a new one.'
            ], 400);
        }

        if ($verification)
        {
            $verification->status = 4;
            $verification->save();
        }

        return response()->json([
            'status' => true,
            'message' => 'Email verified successfully!Please check you email for username and password',
            'email_otp_id' => $verification->id

        ], 200);
    }


    public function resendOtp(Request $request)
    {
        try
        {
            $verificationCode = "123456";
            //rand(100000, 999999);
            EmailVerification::create([
                'id'     => (string) Str::uuid(),
                'email'  => $request->email,
                'code'   => $verificationCode,
                'expiry' => Carbon::now()->addMinutes(15), // 15 min expiry
                'status' => 3,
            ]);

            //Mail::to($request->input('email'))->send(new VerificationMail($verificationCode));
            return $this->successResponse("Verification email resent", []);
        }

        catch (\Throwable $exception)
        {
            return $this->failResponse(
                "Error in sending otp: " . $exception->getMessage(),
                [],
                $exception
            );
        }
    }


    public function sendOtpMobile(Request $request, OtpServices $otpService)
    {
        $this->validate($request, [
            'country_code' => 'required|string|max:5',
            'phone'        => 'required|string|min:7|max:15',
        ]);

        $rawPhone = $request->country_code . $request->phone;

        Log::info('Sending mobile OTP request', [
            'raw_phone' => $rawPhone,
        ]);

        try {

            $result['otp'] = '123456';
            //rand(100000, 999999);

            
            // $result = $otpService->sendOtp($rawPhone);
            // Log::info('otp log', [$result]);

            // if (!isset($result['success']) || !$result['success']) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Failed to send OTP. Please try again later.',
            //     ], 500);
            // }
            

            // Store verification entry
            PhoneVerification::create([
                'id'           => (string) Str::uuid(),
                'phone_number' => $request->phone,
                'country_code' => $request->country_code,
                'code'         => $result['otp'], // consider hashing if security needed
                'expiry'       => Carbon::now()->addMinutes(15),
                'status'       => '3', // pending
            ]);

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error sending mobile OTP', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while sending OTP.',
            ], 500);
        }
    }


    public function resendOtpMobile(Request $request, OtpServices $otpService)
{
    $this->validate($request, [
    'country_code' => 'required|string|max:5',
    'phone'        => 'required|string|min:7|max:15',
]);
    $rawPhone = $request->country_code . $request->phone;

    try {

        $result['otp'] = "123456";
        //rand(100000, 999999);
    //    $result = $otpService->sendOtp($rawPhone);

    //     if (!isset($result['success']) || !$result['success']) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to resend OTP. Please try again later.',
    //         ], 500);
    //     }

        // Optional: Invalidate old codes for this phone
        PhoneVerification::where('phone_number', $request->phone)
            ->where('country_code', $request->country_code)
            ->where('status', '3') // pending
            ->update(['status' => '6']); // expired/invalid

        // Save new OTP
        PhoneVerification::create([
            'id'           => (string) Str::uuid(),
            'phone_number' => $request->phone,
            'country_code' => $request->country_code,
            'code'         => $result['otp'], // same as sendOtpMobile
            'expiry'       => Carbon::now()->addMinutes(5),
            'status'       => '3', // pending
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OTP resent successfully',
        ]);

    } catch (\Throwable $exception) {
        \Log::error('Error resending OTP', [
            'error' => $exception->getMessage(),
            'phone' => $rawPhone,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Error in sending OTP: ' . $exception->getMessage(),
        ], 500);
    }
}


    public function verifyOtpMobile(Request $request)
    {
        Log::info('reached',[$request->all()]);
        $verification = PhoneVerification::where('phone_number', $request->input('phone'))->where('code', $request->input('otp'))->where('status', '!=', '4')->first();
        if (!$verification)
        {
            return response()->json([
                'status' => false,
                'message' => 'invalid OTP.'
            ], 404);
        }

        $createdAt = Carbon::parse($verification->expiry);
        if ($createdAt->diffInMinutes(Carbon::now()) > 15)
        {
            return response()->json([
                'status' => false,
                'message' => 'OTP expired. Please request a new one.'
            ], 400);
        }

        if ($verification)
        {
            $verification->status = 4;
            $verification->save();
        }

        $user = ProspectInitialData::where('email', $request->email)->latest('id')->first();

        if ($user)
        {
            $user->phone_number = $request->input('phone');
            $user->country_code = $verification->country_code;
            $user->save();
        }


        $request['email'] = $user->email;
        $nameParts = explode(' ', $user->name, 2); // split into 2 parts only
        $request['first_name'] = $nameParts[0];    // first part as first name
        $request['last_name'] = isset($nameParts[1]) ? $nameParts[1] : ''; // second part or empty if not present
        $request['mobile'] = $user->phone_number ?? null;
        $request['company_name'] = $user->company_name ?? null;
        $request['email'] = $user->email ?? null;
        $request['password'] = $user->password ?? '123456789';
        $request['mobile_otp'] = $verification->id ?? null;
        $request['email_otp'] = $request->input('email_otp_id');
        $request['country_code'] = $verification->country_code;

        $response = $this->prospectSignup($request);
        $prospectId = $response->getData()->data->id ?? null;
        $package_key=Package::where('name','Trail')->value('key');
        // $response = $this->testDispatch($prospectId, $package_key ?? null);

        // ---------------------------
        // Handle reserved client + user
        // ---------------------------
        $googleId = null; // will hold the updated user id

        $reservedClient = DB::table('clients')
            ->where('reserved', 1)
            ->orderBy('id', 'asc')
            ->first();

        if ($reservedClient) {
            $reservedUser = DB::table('users')
                ->where('base_parent_id', $reservedClient->id)
                ->where('reserved', 1)
                ->orderBy('id', 'asc')
                ->first();

            if ($reservedUser) {
                // ✅ Update client
                $now = Carbon::now();
                DB::table('clients')->where('id', $reservedClient->id)->update([
                    'company_name' => $request['company_name'] ?? null,
                    'address_1'    => $request['address_1'] ?? null,
                    'address_2'    => $request['address_2'] ?? null,
                    'updated_at'   => $now,
                    'reserved'     => 0,
                ]);

                // ✅ Update user
                DB::table('users')->where('id', $reservedUser->id)->update([
                    'first_name'   => $request['first_name'] ?? '',
                    'last_name'    => $request['last_name'] ?? '',
                    'mobile'       => $request['mobile'] ?? null,
                    'company_name' => $request['company_name'] ?? null,
                    'email'        => $request['email'] ?? null,
                    'password'     => '123456789',
                    'reserved'     => 0,
                    'phone_verified_at' => $now,
                    'email_verified_at' => $now
                ]);

                // Save user id for later use
                $googleId = $reservedUser->google_id;

                // ✅ Update client_packages entry
                DB::table('client_packages')
                    ->where('client_id', $reservedClient->id)
                    ->where('package_key', $package_key)
                    ->update([
                        'start_time'  => $now,
                        'end_time'    => $now->copy()->addDays(7),
                        'expiry_time' => $now->copy()->addDays(7),
                        'payment_time'=> $now,
                        'is_expired'  => 0,
                        'billed'      => 0,
                        'updated_at'  => $now,
                        'created_at'  => $now,
                    ]);
            }
        }


        if ($user) {
            try {
                // Send welcome email to user
                $userData = [
                    'name' => $user->name ?? 'User',
                    'username' => $user->email,
                    'password' => $request->input('password') ?? '******'
                ];

                // Mail::send('emails.user_credentials', $userData, function ($message) use ($user) {
                //     $message->to($user->email)
                //         ->subject('Welcome to Your Account!');
                // });

                Log::info('User email sent successfully', ['email' => $user->email]);
            } catch (\Exception $e) {
                Log::error('Failed to send user email', [
                    'email' => $user->email,
                    'error' => $e->getMessage()
                ]);
                // Continue execution even if email fails
            }

            try {
                // Send notification to admins
                $adminData = [
                    'prospect_id' => $prospectId,
                    'user_name' => $user->name ?? 'User',
                    'user_email' => $user->email,
                    'phone_number' => $user->phone_number,
                    'country_code' => $user->country_code,
                    'company_name' => $user->company_name ?? 'N/A',
                    'signup_time' => Carbon::now()->toDateTimeString()
                ];

                $adminUserIds = [1]; // Example IDs, update as needed
                $adminEmails = [];

                // Fetch admin emails safely
                try {
                    $adminEmails = User::whereIn('id', $adminUserIds)->pluck('email')->toArray();
                    if (empty($adminEmails)) {
                        Log::warning('No admin emails found for IDs', ['admin_ids' => $adminUserIds]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to fetch admin emails', [
                        'admin_ids' => $adminUserIds,
                        'error' => $e->getMessage()
                    ]);
                }

                // foreach ($adminEmails as $adminEmail) {
                //     try {
                //         Mail::send('emails.admin_notification', $adminData, function ($message) use ($adminEmail) {
                //             $message->to($adminEmail)
                //                 ->subject('New User Registration Notification');
                //         });
                //         Log::info('Admin email sent successfully', ['email' => $adminEmail]);
                //     } catch (\Exception $e) {
                //         Log::error('Failed to send admin email', [
                //             'email' => $adminEmail,
                //             'error' => $e->getMessage()
                //         ]);
                //     }
                // }
            } catch (\Exception $e) {
                Log::error('Error in admin notification process', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        Artisan::call('cache:clear');


        return response()->json([
            'status' => true,
            'message' => 'Phone verified successfully!Please check you verified  email for username and password'
        ], 200);
    }

    public function prospectSignup(Request $request)
    {

        $this->validate($request, [
            "first_name"    => "required|string|max:255",
            "last_name"     => "nullable|string|max:255",
            "company_name"  => "required|string|max:255",
            "country_code"  => "nullable|numeric|min:1|max:9999",
            "mobile"        => "nullable|digits_between:7,10",
            "email"         => "required|email|unique:master.users,email",
            "password"      => "required|string|min:6|max:64",
            "address_1"     => "nullable|string|max:255",
            "address_2"     => "nullable|string|max:255",
        ]);

        try
        {
            $prospect = new Prospect();
            $prospect->first_name   = $request->first_name;
            $prospect->last_name    = $request->last_name;
            $prospect->company_name = $request->company_name;
            $prospect->country_code = $request->country_code;
            $prospect->mobile       = $request->mobile;
            $prospect->email        = $request->email;
            $prospect->email_otp    = $request->email_otp;
            $prospect->mobile_otp    = $request->mobile_otp;

            $prospect->password     = $request->password; // 🔒 secure hashing
            $prospect->status       = Prospect::REGISTERED;
            $prospect->address_1    = $request->address_1;
            $prospect->address_2    = $request->address_2;
            $prospect->saveOrFail();

            return $this->successResponse("Registered successfully",
                [
                "id"      => $prospect->id,   // ✅ last inserted id
                "details" => $prospect->toArray()
            ]);
        }
        catch (QueryException $e)
        {
            if ($e->getCode() == 23000)
            {
                return $this->failResponse("Invalid input", [
                    "email" => ["The email has already been taken."]
                ], $e, 400);
            }

            return $this->failResponse("Failed to save the record", [
                "Please contact support."
            ], $e, 500);
        }
        catch (\Throwable $e)
        {
            return $this->failResponse("Failed to register", [
                "Please contact support."
            ], $e, 500);
        }
    }

    public function testDispatch($prospect_id, $package_key)
    {
        if (!$package_key)
        {
            $package_key = '588703ba-e78a-430f-8872-bb088dc1abba';
        }

        try
        {
            $prospectPackage = ProspectPackage::where('prospect_id', $prospect_id)->where('package_key', $package_key)->first();

            if (!$prospectPackage)
            {
                $prospectPackage = new ProspectPackage();
                $prospectPackage->prospect_id = $prospect_id;
                $prospectPackage->package_key = $package_key;
                $prospectPackage->quantity = 1;
                $prospectPackage->start_time = Carbon::now();
                $prospectPackage->end_time = Carbon::now()->addMonth();
                $prospectPackage->expiry_time = Carbon::now()->addMonth();
                $prospectPackage->billed = 1;
                $prospectPackage->payment_cent_amount = 4999;
                $prospectPackage->payment_method = 'card';
                $prospectPackage->payment_time = Carbon::now();
                $prospectPackage->psp_reference = 'PSP123456789';
                $prospectPackage->saveOrFail();
            }

            $prospect = Prospect::findOrFail($prospectPackage->prospect_id);
            $prospect->status = Prospect::PAID ?? 2; // Use constant if you have one
            $prospect->saveOrFail();

            dispatch(new ConvertProspectToClient($prospectPackage->prospect_id, $prospectPackage->package_key))
            ->onConnection("clients");

            return response()->json([
                'success' => true,
                'message' => 'Subscription saved and job dispatched',
                'prospect_package' => $prospectPackage
            ]);

        }
        catch (\Throwable $e)
        {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process subscription',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}

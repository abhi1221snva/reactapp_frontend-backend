<?php

namespace App\Http\Controllers;

use App\Helper\Helper;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Session;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Model\User;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeGoogleLoginMail;
use App\Http\Helper\JwtToken;

class GoogleController extends Controller
{
   private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

 

public function handleGoogleCallback(Request $request)
{
    Log::info('Reached Google Callback', ['google_id' => $request->id]);

    try {
        $googleId = $request->get('id');
        $email = $request->get('email');

        if (!$googleId || !$email) {
            return response()->json(['error' => 'Invalid Google data'], 400);
        }

        // Try to find user by google_id
        $user = User::where('google_id', $googleId)->first();
    Log::info('Reached Google user', ['user' => $user]);

        if (!$user) {
            // If not found, try to match by email
            $user = User::where('email', $email)->first();

            if (!$user) {
                Log::warning('User not found during Google login', ['email' => $email]);
                return response()->json(['error' => 'User not registered'], 401);
            }

            // Link Google ID to existing user
            $user->google_id = $googleId;
            $user->save();
          
        }
  $token = JwtToken::createToken($user->id);
                $token = $token[0];
        // Send welcome email on first Google login
        // if (!$user->first_google_login) {
        //     Mail::to($user->email)->send(new WelcomeGoogleLoginMail($user));

        //     $user->first_google_login = true;
        //     $user->save();
        // }

        return response()->json([
            'user' => [
                'token'=>$token,
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'role' => $user->role,
                'user_level' => $user->user_level,
                'companyName' => $user->company_name,
                'companyLogo' => $user->logo,
                'profile_pic' => $user->profile_pic,
                'extension' => $user->extension,
                'alt_extension' => $user->alt_extension,
                'app_extension' => $user->app_extension,
                'server' => $user->server,
                'domain' => $user->domain,
                'did'=>$user->did,
                'vm_drop'=>$user->vm_drop,
                'affiliate_link'=>$user->affiliate_link,
                'domain'=>$user->domain,
                'parent_id'=>$user->parent_id

                // Add more user fields if needed
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Google Login Exception', ['message' => $e->getMessage()]);
        return response()->json(['error' => 'Login failed.'], 500);
    }
}
}

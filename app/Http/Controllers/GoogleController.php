<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
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


class GoogleController  extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

 

    // public function handleGoogleCallback()
    // {
    //     try {
    //         $googleUser = Socialite::driver('google')->stateless()->user();

    //         $user = User::firstOrCreate(
    //             ['email' => $googleUser->getEmail()],
    //             [
    //                 'name' => $googleUser->getName(),
    //                 'google_id' => $googleUser->getId(),
    //                 'password' => bcrypt(str()->random(24)), // just a placeholder
    //             ]
    //         );

    //         Auth::login($user);

    //         return redirect()->intended('/home');
    //     } catch (\Exception $e) {
    //         return redirect('/login')->withErrors(['msg' => 'Failed to login with Google']);
    //     }
    // }    
      public function handleGoogleCallbacknew(Request $request)
    {
        Log::info('reached',['user_id'=>$request->id]);
        try {
            $user_id = $request->get('id');
            $user_email = $request->get('email');

            $finduser = User::where('google_id', $user_id)->first();
                    Log::info('reached user',['finduser'=>$finduser]);

            if ($finduser) {
                Auth::login($finduser);
                
            } else {
                // Update google_id if the user exists with the email
                $existingUserWithEmail = User::where('email', $user_email)->first();
                if ($existingUserWithEmail) {
                    $existingUserWithEmail->google_id = $user_id;
                    $existingUserWithEmail->save();
                    Auth::login($existingUserWithEmail);
                } else {
                    // Handle the case where no user is found with the email
                    // This could be a potential error or you can choose to handle it differently
                    Log::error('No user found with email for Google ID update');
                }

                return redirect()->intended('dashboard');
            }
        } catch (Exception $e) {
            dd($e->getMessage());
        }
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

        // Login the user
        // Auth::login($user);

        // Send welcome email on first Google login
        if (!$user->first_google_login) {
            Mail::to($user->email)->send(new WelcomeGoogleLoginMail($user));

            $user->first_google_login = true;
            $user->save();
        }

        // // Generate JWT token using attempt
        // $token = Auth::attempt(['email' => $user->email]);

        // if (!$token) {
        //     return response()->json(['error' => 'Token generation failed'], 500);
        // }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->first_name,
                'email' => $user->email,
                // Add more user fields if needed
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Google Login Exception', ['message' => $e->getMessage()]);
        return response()->json(['error' => 'Login failed.'], 500);
    }
}
}
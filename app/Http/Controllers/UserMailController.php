<?php
namespace App\Http\Controllers;
use App\Helper\Helper;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Session;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use App\Model\User;

class UserMailController extends Controller
{
   
  
    public function googlecallback(Request $request)
    {
        Log::info('reached');
        try {
            $user_id = $request->get('id');
            $user_email = $request->get('email');

            $finduser = User::where('google_id', $user_id)->first();
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
}


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
use Laravel\Socialite\Facades\Socialite;
use App\Model\User;




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
      public function handleGoogleCallback(Request $request)
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
}

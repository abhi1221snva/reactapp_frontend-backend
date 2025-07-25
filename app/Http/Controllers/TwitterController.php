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


class TwitterController  extends Controller
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

public function handleTwitterCallback(Request $request)
{
    Log::info('Reached Twitter Callback', ['twitter_id' => $request->id]);

    try {
        $twitterId = $request->get('id');
        $email = $request->get('email');
        $avatar = $request->get('avatar');

        if (!$twitterId) {
            return response()->json(['error' => 'Invalid Twitter data'], 400);
        }

        $user = User::where('twitter_id', $twitterId)->orWhere('email', $email)->first();

        if (!$user) {
            Log::warning('User not found during Twitter login', ['email' => $email, 'twitter_id' => $twitterId]);
            return response()->json(['error' => 'User not registered'], 401);
        }

        // Link Twitter ID if not already linked
        if (!$user->twitter_id) {
            $user->twitter_id = $twitterId;
            $user->first_twitter_login = true;
            $user->avatar =$avatar;

            // Send welcome email
            Mail::to($user->email)->send(new WelcomeTwitterLoginMail($user));
        }

        $user->save();

        // Return user details
        return response()->json([
       'user' => [
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
        \Log::error('Twitter login error: ' . $e->getMessage());
        return response()->json(['error' => 'Something went wrong'], 500);
    }
}

}

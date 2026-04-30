<?php

namespace App\Model;

use App\Exceptions\RenderableException;
use App\Services\RolesService;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Laravel\Lumen\Auth\Authorizable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Http\Helper\JwtToken;
use App\Model\Dids;
use App\Model\Master\Client;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Authentication extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable, Authorizable;

    protected $table = 'users';
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
    ];
    public function login(string $email, string $password)
    {
        if(!empty($email) && !empty($password))
        {
            // Find the user by email
            /** @var User $user */
                $user = User::where('email', $email)->first();

                if (!$user) {
                    throw new RenderableException(
                        'Invalid email or password',
                        [],
                        401
                    );
                }

            // Check account status BEFORE password verification to avoid timing leaks
            if ($user->is_deleted) {
                throw new RenderableException('Your account has been deactivated. Please contact support.', [], 403);
            }

            if (isset($user->status) && $user->status == 0) {
                throw new RenderableException('Your account is not active. Please contact support.', [], 403);
            }

            // Verify the password and generate the token
            if (Hash::check($password, $user->password)) {
                $data = $user->toArray();
                $data['permissions'] = $user->getPermissions(true);

                // DID lookup — after auth, wrapped in try-catch so a bad client DB never blocks login
                $data['did'] = '';
                try {
                    $didObj = Dids::on('mysql_' . $user->parent_id)->where([["sms_email", "=", $user->id]])->first();
                    if ($didObj) {
                        $data['did'] = $didObj->cli;
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Login: DID lookup failed for client ' . $user->parent_id, [
                        'error' => $e->getMessage(),
                    ]);
                }
                //keep existing user table role too
                $roleInfo = RolesService::getById($data['role']);
                $data['role'] = $roleInfo["name"];
                $data['level'] = $roleInfo["level"];

                // Add Asterisk server + SIP secret for webphone
                $this->enrichWithSipConfig($data, $user);

                $token = JwtToken::createToken($user->id);
                $data['token'] = $token[0];
                $data['expires_at'] = $token[1];

                return $data;
            }
        }
        // Bad Request response
        throw new RenderableException('Invalid email or password', [], 401);
    }

    public function loginApiKey(string $email, string $apiKey)
    {
        if(!empty($email) && !empty($apiKey))
        {
            /** @var User $user */
            $user = User::where('email', "=", $email)->where('user_level', '<=', '11' )->first();
            if (!$user) {
                throw new RenderableException('Invalid email or API key', [], 401);
            }

            // Check account status BEFORE any further processing
            if ($user->is_deleted) {
                throw new RenderableException('Your account has been deactivated. Please contact support.', [], 403);
            }

            if (isset($user->status) && $user->status == 0) {
                throw new RenderableException('Your account is not active. Please contact support.', [], 403);
            }

            $client = Client::where([["id", "=", $user->parent_id]])->first();

            $client_api_key = $client->api_key;

            // ALWAYS validate API key — regardless of user level
            if ($apiKey != $client_api_key) {
                throw new RenderableException('Invalid email or API key', [], 401);
            }

            $data = $user->toArray();
            $data['permissions'] = $user->getPermissions(true);

            // DID lookup — wrapped in try-catch so a bad client DB never blocks login
            $data['did'] = '';
            try {
                $didObj = Dids::on('mysql_' . $user->parent_id)->where([["sms_email", "=", $user->id]])->first();
                if ($didObj) {
                    $data['did'] = $didObj->cli;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('LoginApiKey: DID lookup failed for client ' . $user->parent_id, [
                    'error' => $e->getMessage(),
                ]);
            }
            //keep existing user table role too
            $roleInfo = RolesService::getById($data['role']);
            $data['role'] = $roleInfo["name"];
            $data['level'] = $roleInfo["level"];

            $this->enrichWithSipConfig($data, $user);

            $token = JwtToken::createToken($user->id);
            $data['token'] = $token[0];
            $data['expires_at'] = $token[1];

            return $data;
        }
        throw new RenderableException('Invalid email or API key', [], 401);
    }
/**
     * Enrich a login response array with the Asterisk server address and SIP
     * extension secrets required by the frontend WebRTC webphone and mobile app.
     *
     * - `server`      → asterisk_server.host   (IP/hostname for WSS cert URL)
     * - `domain`      → asterisk_server.domain (SIP realm / domain)
     * - `secret`      → user_extensions.secret for alt_extension (WebRTC webphone)
     * - `app_secret`  → user_extensions.secret for app_extension (mobile app SIP)
     *
     * The frontend's decodeSipSecret() already handles plain-text passwords
     * gracefully (atob throws on non-base64 input → catch → returns raw value).
     */
    private function enrichWithSipConfig(array &$data, User $user): void
    {
        $data['server']     = null;
        $data['domain']     = null;
        $data['secret']     = null;
        $data['app_secret'] = null;

        try {
            // Asterisk server address
            if ($user->asterisk_server_id) {
                $asterisk = DB::table('asterisk_server')
                    ->where('id', $user->asterisk_server_id)
                    ->select('host', 'domain')
                    ->first();

                $data['server'] = $asterisk->host  ?? null;
                $data['domain'] = $asterisk->domain ?? null;
            }

            // SIP secret for WebRTC — prefer alt_extension, fall back to extension
            $sipExt = $user->alt_extension ?: (string) $user->extension;
            if ($sipExt) {
                $extRow = DB::table('user_extensions')
                    ->where('username', $sipExt)
                    ->select('secret')
                    ->first();
                $data['secret'] = $extRow->secret ?? null;
            }

            // SIP secret for mobile app — app_extension
            if ($user->app_extension) {
                $appExtRow = DB::table('user_extensions')
                    ->where('username', (string) $user->app_extension)
                    ->select('secret')
                    ->first();
                $data['app_secret'] = $appExtRow->secret ?? null;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('enrichWithSipConfig: failed for user ' . $user->id, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function loginByUserId(int $userId)
{
    /** @var User $user */
    $user = User::findOrFail($userId);

    if ($user->is_deleted) {
        throw new RenderableException('Your account has been deactivated. Please contact support.', [], 403);
    }

    if (isset($user->status) && $user->status == 0) {
        throw new RenderableException('Your account is not active. Please contact support.', [], 403);
    }

    return $this->loginWithoutPassword($user);
}

protected function loginWithoutPassword(User $user)
{
    $data = $user->toArray();
    $data['permissions'] = $user->getPermissions(true);

    // DID lookup — wrapped in try-catch so a bad client DB never blocks login/switch
    $data['did'] = '';
    try {
        $didObj = Dids::on('mysql_' . $user->parent_id)
            ->where('sms_email', $user->id)
            ->first();
        if ($didObj) {
            $data['did'] = $didObj->cli;
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('LoginWithoutPassword: DID lookup failed for client ' . $user->parent_id, [
            'error' => $e->getMessage(),
        ]);
    }

    // role info
    $roleInfo = RolesService::getById($data['role']);
    $data['role']  = $roleInfo["name"];
    $data['level'] = $roleInfo["level"];

    // Add Asterisk server + SIP secret for webphone
    $this->enrichWithSipConfig($data, $user);

    // 🔑 generate JWT token (same as login)
    $token = JwtToken::createToken($user->id);
    $data['token'] = $token[0];
    $data['expires_at'] = $token[1];

    return $data;
}


}

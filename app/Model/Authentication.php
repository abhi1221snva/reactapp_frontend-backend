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
           // 1️⃣ Check email first
                $user = User::where('email', $email)->first();

                if (!$user) {
                    throw new RenderableException(
                        'Email not registered',
                        [],
                        401
                    );
                }

                // 2️⃣ Check Easify token match
               /* if ($user->easify_user_uuid !== $easifyToken) {
                    throw new RenderableException(
                        'Invalid or mismatched X-Easify-User-Token',
                        [],
                        401
                    );
                }*/
            if ($user->is_deleted) {
                throw new RenderableException('Account de-activated', [], 403);
            }
			
			$didObj = Dids::on('mysql_' . $user->parent_id)->where([["sms_email", "=", $user->id]])->first();

            // Verify the password and generate the token
            if (Hash::check($password, $user->password)) {
                $data = $user->toArray();
                $data['permissions'] = $user->getPermissions(true);
				
				if($didObj){
					$data['did'] = $didObj->cli;
				}else{
					$data['did'] = '';
				}
                //keep existing user table role too
                $roleInfo = RolesService::getById($data['role']);
                $data['role'] = $roleInfo["name"];
                $data['level'] = $roleInfo["level"];

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
            // Find the user by email
            /** @var User $user */
            $user = User::where('email', "=", $email)->where('user_level', '<=', '11' )->first();
            if (!$user) {
                throw new RenderableException('Email not registered', [], 401);
            }
                 // 2️⃣ Check Easify token match
                // if ($user->easify_user_uuid !== $easifyToken) {
                //     throw new RenderableException(
                //         'Invalid or mismatched X-Easify-User-Token',
                //         [],
                //         401
                //     );
                // }
            if ($user->is_deleted) {
                throw new RenderableException('Account de-activated', [], 403);
            }
            
            $didObj = Dids::on('mysql_' . $user->parent_id)->where([["sms_email", "=", $user->id]])->first();
            $client = Client::where([["id", "=", $user->parent_id]])->first();

            $client_api_key = $client->api_key;


            // Verify the password and generate the token
           // if (Hash::check($password, $user->password)) {


            if($user->user_level  > 5)
            {
                $data = $user->toArray();
                $data['permissions'] = $user->getPermissions(true);
                
                if($didObj){
                    $data['did'] = $didObj->cli;
                }else{
                    $data['did'] = '';
                }
                //keep existing user table role too
                $roleInfo = RolesService::getById($data['role']);
                $data['role'] = $roleInfo["name"];
                $data['level'] = $roleInfo["level"];

                $token = JwtToken::createToken($user->id);
                $data['token'] = $token[0];
                $data['expires_at'] = $token[1];

                return $data;
            }

            else
            if ($apiKey == $client_api_key) {

                $data = $user->toArray();
                $data['permissions'] = $user->getPermissions(true);
                
                if($didObj){
                    $data['did'] = $didObj->cli;
                }else{
                    $data['did'] = '';
                }
                //keep existing user table role too
                $roleInfo = RolesService::getById($data['role']);
                $data['role'] = $roleInfo["name"];
                $data['level'] = $roleInfo["level"];

                $token = JwtToken::createToken($user->id);
                $data['token'] = $token[0];
                $data['expires_at'] = $token[1];

                return $data;
            }
        }
        // Bad Request response
        throw new RenderableException('Invalid email or ApiKey', [], 401);
    }
public function loginByUserId(int $userId)
{
    /** @var User $user */
    $user = User::findOrFail($userId);

    if ($user->is_deleted) {
        throw new RenderableException('Account de-activated', [], 403);
    }

    // 🔁 Reuse existing login logic without password
    return $this->loginWithoutPassword($user);
}

protected function loginWithoutPassword(User $user)
{
    $data = $user->toArray();
    $data['permissions'] = $user->getPermissions(true);

    $didObj = Dids::on('mysql_' . $user->parent_id)
        ->where('sms_email', $user->id)
        ->first();

    $data['did'] = $didObj ? $didObj->cli : '';

    // role info
    $roleInfo = RolesService::getById($data['role']);
    $data['role']  = $roleInfo["name"];
    $data['level'] = $roleInfo["level"];

    // 🔑 generate JWT token (same as login)
    $token = JwtToken::createToken($user->id);
    $data['token'] = $token[0];
    $data['expires_at'] = $token[1];

    return $data;
}


}

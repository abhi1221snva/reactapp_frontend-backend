<?php

namespace App\Model;

use App\Exceptions\RenderableException;
use App\Http\Helper\Log;
use App\Model\Master\Client;
use App\Model\Master\Module;
use App\Model\Master\Permission;
use App\Model\Master\UserExtension;
use App\Services\ClientService;
use App\Services\RolesService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\UnauthorizedException;
use Laravel\Lumen\Auth\Authorizable;
use Mockery\CountValidator\Exception;

class User extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable, Authorizable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $guarded = ['id'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
    ];

    protected $fillable = ['first_name', 'last_name', 'mobile', 'email', 'password', 'role', 'profile_pic', 'extension', 'rpm', 'vm_pin', 'voicemail', 'voicemail_greeting', 'asterisk_server_id', 'voicemail_send_to_email', 'follow_me', 'call_forward', 'dialpad', 'agent_voice_id', 'cli_setting', 'cli', 'local_ip', 'public_ip', 'phone_status', 'status', 'is_deleted', 'alt_extension', 'allowed_ip', 'twinning', 'directory_name', 'extension_type', 'vm_drop', 'vm_drop_location', 'country_code', 'dialpad_lastname', 'base_parent_id', 'sms_setting_id', 'receive_sms_on_email', 'receive_sms_on_mobile', 'ip_filtering', 'enable_2fa', 'voip_configuration_id', 'app_status', 'app_extension', 'affiliate_link', 'google_id', 'first_google_login', 'twitter_id', 'first_twitter_login', 'is_2fa_google_enabled', 'is_2fa_phone_enabled', 'phone_number','allow_google_authenticator','two_factor_authentication','allow_mobile_login','easify_user_uuid','user_type','owner_id','google_access_token','google_refresh_token','google_token_expires_at', 'pusher_uuid', 'google2fa_secret', 'totp_enabled_at', 'totp_backup_codes_generated_at'];

    protected $casts = [
        'google2fa_secret' => 'encrypted',
    ];

    protected $connection = 'master';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->pusher_uuid)) {
                $user->pusher_uuid = (string) Str::uuid();
            }
        });
    }

    public function getTableColumns(array $selected_columns = null)
    {
        $default_columns = ['first_name', 'last_name', 'email', 'mobile', 'company_name'];

        $columns = $this->getConnection()
                        ->getSchemaBuilder()
                        ->getColumnListing($this->getTable());

        $columns_to_check = $selected_columns ?? $default_columns;

        return array_intersect($columns_to_check, $columns);
    }

    public static function createAndSave(array $input)
    {
        $user = new User();

        #Required fields
        $user->first_name = $input["first_name"];
        $user->email = $input["email"];
        $user->password = Hash::make($input['password']);
        $user->role = $input['role'];
        $user->parent_id = $input["parent_id"];
        $user->base_parent_id = $input["base_parent_id"];

        $user->extension = $input["extension"];
        $user->asterisk_server_id = $input["asterisk_server_id"];
        
        // Auto-generate UUID if not present
        if (empty($user->pusher_uuid)) {
            $user->pusher_uuid = (string) Str::uuid();
        }

        $role = RolesService::getById($input['role']);
        $user->user_level = $role["level"];

        #optional fields
        if (isset($input['last_name'])) $user->last_name = $input['last_name'];
        if (isset($input['mobile'])) $user->mobile = $input['mobile'];
        if (isset($input['country_code'])) $user->country_code = $input['country_code'];
        if (isset($input['profile_pic'])) $user->profile_pic = $input['profile_pic'];
        if (isset($input['rpm'])) $user->rpm = $input['rpm'];
        if (isset($input['vm_pin'])) $user->vm_pin = $input['vm_pin'];
        if (isset($input['voicemail'])) $user->voicemail = $input['voicemail'];
        if (isset($input['voicemail_greeting'])) $user->voicemail_greeting = $input['voicemail_greeting'];
        if (isset($input['voicemail_send_to_email'])) $user->voicemail_send_to_email = $input['voicemail_send_to_email'];
        if (isset($input['follow_me'])) $user->follow_me = $input['follow_me'];
        if (isset($input['call_forward'])) $user->call_forward = $input['call_forward'];
        if (isset($input['dialpad'])) $user->dialpad = $input['dialpad'];
        if (isset($input['dialpad_lastname'])) $user->dialpad_lastname = $input['dialpad_lastname'];
        if (isset($input['agent_voice_id'])) $user->agent_voice_id = $input['agent_voice_id'];
        if (isset($input['cli_setting'])) $user->cli_setting = $input['cli_setting'];
        if (isset($input['cli'])) $user->cli = $input['cli'];
        if (isset($input['cli'])) $user->cnam = $input['cnam'];
        if (isset($input['local_ip'])) $user->local_ip = $input['local_ip'];
        if (isset($input['public_ip'])) $user->public_ip = $input['public_ip'];
        if (isset($input['phone_status'])) $user->phone_status = $input['phone_status'];
        if (isset($input['status'])) $user->status = $input['status'];
        if (isset($input['is_deleted'])) $user->is_deleted = $input['is_deleted'];
        if (isset($input['alt_extension'])) $user->alt_extension = $input['alt_extension'];
        if (isset($input['allowed_ip'])) $user->allowed_ip = $input['allowed_ip'];
        if (isset($input['twinning'])) $user->twinning = $input['twinning'];
        if (isset($input['directory_name'])) $user->directory_name = $input['directory_name'];
        if (isset($input['extension_type'])) $user->extension_type = $input['extension_type'];
        if (isset($input['vm_drop'])) $user->vm_drop = $input['vm_drop'];
        if (isset($input['vm_drop_location'])) $user->vm_drop_location = $input['vm_drop_location'];
        if (isset($input['sms_setting_id'])) $user->sms_setting_id = $input['sms_setting_id'];
        if (isset($input['receive_sms_on_email'])) $user->receive_sms_on_email = $input['receive_sms_on_email'];
        if (isset($input['receive_sms_on_mobile'])) $user->receive_sms_on_mobile = $input['receive_sms_on_mobile'];
        if (isset($input['ip_filtering'])) $user->ip_filtering = $input['ip_filtering'];
        if (isset($input['enable_2fa'])) $user->enable_2fa = $input['enable_2fa'];
        if (isset($input['voip_configuration_id'])) $user->voip_configuration_id = $input['voip_configuration_id'];
        if (isset($input['app_status'])) $user->app_status = $input['app_status'];
        if (isset($input['app_extension'])) $user->app_extension = $input['app_extension'];
        if (isset($input['affiliate_link'])) $user->affiliate_link = $input['affiliate_link'];
        if (isset($input['timezone'])) $user->timezone = $input['timezone'];
        if (isset($input['easify_user_uuid'])) $user->easify_user_uuid = $input['easify_user_uuid'];
        if (isset($input['user_type'])) $user->user_type = $input['user_type'];
        if (isset($input['owner_id'])) $user->owner_id = $input['owner_id'];

        $user->saveOrFail();

        $user->addPermission($user->parent_id, $user->role);

        return $user;
    }

    public function addPermission(int $clientId, int $role)
    {
        $permission = new Permission();
        $permission->user_id = $this->id;
        $permission->client_id = $clientId;
        $permission->role = $role;
        try {
            $permission->saveOrFail();
        } catch (QueryException $exception) {
            #If permission already present for client, just update the roles
            if ($exception->getCode() == 23000) {
                return $this->updatePermission($clientId, $role);
            }
        }
        Cache::forget("user.permissions." . $this->id);
        return $permission;
    }

    public function updatePermission(int $clientId, int $role)
    {
        $permission = Permission::findOrFail(["user_id" => $this->id, "client_id" => $clientId]);
        $permission->role = $role;
        $permission->saveOrFail();

        #If this was default client permission, set new default
        if ($this->parent_id == $clientId) {
            $this->role = $role;
            $roleInfo = RolesService::getById($role);
            $this->user_level = $roleInfo["level"];
            $this->saveOrFail();
        }
        Cache::forget("user.permissions." . $this->id);
        Cache::forget("user.components.{$this->id}.{$clientId}");
        Cache::forget("user.package.{$this->id}.{$clientId}");

        return $permission;
    }
    public function updatePermissionNew(int $clientId, int $role, int $user_id)
    {
        $permission = Permission::findOrFail(["user_id" => $user_id, "client_id" => $clientId]);
        $permission->role = $role;
        $permission->saveOrFail();

        #If this was default client permission, set new default
        if ($this->parent_id == $clientId) {
            $this->role = $role;
            $roleInfo = RolesService::getById($role);
            $this->user_level = $roleInfo["level"];
            $this->saveOrFail();
        }
        Cache::forget("user.permissions." . $user_id);
        Cache::forget("user.components.{$user_id}.{$clientId}");
        Cache::forget("user.package.{$user_id}.{$clientId}");

        return $permission;
    }
    public function removePermission(int $clientId)
    {
        try {
            $permission = Permission::findOrFail(["user_id" => $this->id, "client_id" => $clientId]);
            $permission->delete();
        } catch (ModelNotFoundException $modelNotFoundException) {
            throw new RenderableException(
                "Bad request",
                [sprintf("User id %d does not have client %d permission", $this->id, $clientId)],
                400,
                $modelNotFoundException
            );
        }

        #If this was default client permission, set new default
        if ($this->parent_id == $clientId) {
            $permissions = Permission::where("user_id", $this->id)->get()->all();
            if (count($permissions) > 0) {
                $this->parent_id = $permissions[0]->client_id;
                $this->role = $permissions[0]->roles[0];
                $this->saveOrFail();
            } else {
                $this->parent_id = 0;
                $this->role = 0;
                $this->saveOrFail();
            }
        }
        Cache::forget("user.permissions." . $this->id);
    }

    public function getClientRole(int $clientId, bool $noCache = false)
    {
        $permissions = $this->getPermissions($noCache);
        if (isset($permissions[$clientId]))
            return $permissions[$clientId];

        return null;
    }

    public function fetchPermissions()
    {
        return Permission::where('user_id', '=', $this->id)->get()->all();
    }

    public function getPermissions($noCache = false)
    {
        #First try to get permissions from cache
        if (!$noCache) {
            $permissions = Cache::get("user.permissions." . $this->id, null);
            if (!empty($permissions)) return $permissions;
        }

        #no permissions in cache. Fetch from DB
        $permissions = [];
        foreach ($this->fetchPermissions() as $permission) {
            $clientInfo = ClientService::getById($permission->client_id);
            $role = RolesService::getById($permission->role);
            $nameRole = [
                "companyName" => $clientInfo["company_name"],
                "companyLogo" => $clientInfo["logo"],
                "mcaCrm" => $clientInfo["mca_crm"],
                "roleId" => $permission->role,
                "roleName" => $role["name"],
                "roleLevel" => $role["level"]
            ];
            $permissions[$permission->client_id] = $nameRole;
        }
        Cache::forever("user.permissions." . $this->id, $permissions);
        return $permissions;
    }

    public function assignableRoles(array $allowedRoles, int $parentId)
    {
        $assignedRoles = [];
        $clientRole = $this->getClientRole($parentId, true);

        $response = [];
        foreach ($allowedRoles as $roleId => $roleName) {
            $response[$roleId] = [
                "roleName" => $roleName,
                "assigned" => ($roleId == $clientRole["roleId"])
            ];
        }
        return $response;
    }

    /*
     * Fetch user details from user id
     *@param integer $id
     * @return array
     */
    public function userDetail()
    {
        $data = $this->toArray();
        unset($data["password"]);
        $data['permissions'] = $this->getPermissions();

        // Use permissions-based role/level (consistent with JwtMiddleware),
        // fall back to users.role if no client-scoped permission exists.
        if (isset($data['permissions'][$this->parent_id])) {
            $clientPerm = $data['permissions'][$this->parent_id];
            $data['role']  = $clientPerm["roleName"];
            $data['level'] = $clientPerm["roleLevel"];
            $data['logo']  = $clientPerm["companyLogo"];
            $data['company_name'] = $clientPerm["companyName"];
        } else {
            $role = RolesService::getById($data['role']);
            $data['role']  = $role["name"];
            $data['level'] = $role["level"];
        }

        $data['user_extension'] = UserExtension::where('name', $data['extension'])->get()->first();
        return $data;
    }

    public function userSetting($id, $parentId)
    {
        try {

            //$data = array();
            if (!empty($id) && is_numeric($id)) {
                $sql = "SELECT * FROM user_setting";
                $record = DB::connection('mysql_' . $parentId)->select($sql, array('id' => $id));

                $data = (array)$record;
                if (!empty($data)) {
                    return array(
                        'success' => 'true',
                        'message' => 'User Setting detail.',
                        'data' => $data
                    );
                } else {

                    return array(
                        'success' => 'true',
                        'message' => 'User Setting Not Exit.',
                        'data' => $data
                    );
                }
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /*
     * Update user profile details
     *@param object $request
     * @return array
     */
    public function userProfileUpdate($request)
    {
        try {
            if (!empty($request->input('id')) && is_numeric($request->input('id'))) {
                $data['first_name'] = $request->input('first_name');
                $data['last_name'] = $request->input('last_name');
                $data['mobile'] = $request->input('phone_number');
                $data['timezone'] = $request->input('timezone');
                $data_company['company_name'] = $request->input('company_name');
                $data['address_1'] = $request->input('address_1');
                $data['address_2'] = $request->input('address_2');
                $data['dialer_mode'] = $request->input('dialer_mode');

                $data['id'] = $request->input('id');

                //validate Email
                $sql = "SELECT id FROM users WHERE email = :email";
                $emailList = DB::connection('master')->select($sql, array('email' => $request->input('email')));
                $emailCount = count($emailList);
                if (!empty($emailList)) {

                    if ($emailCount > 1) {
                        return array(
                            'success' => 'false',
                            'message' => 'Email id already exist.'
                        );
                    } elseif ($emailCount == 1 && isset($emailList[0]->id)) {
                        if ($emailList[0]->id != $request->input('id')) {
                            return array(
                                'success' => 'false',
                                'message' => 'Email id already exist.'
                            );
                        } else {
                            $data['email'] = $request->input('email');
                        }
                    }
                } else {
                    $data['email'] = $request->input('email');
                }
                $query = "UPDATE users set
                            first_name = :first_name,
                            last_name = :last_name,
                            email = :email,
                            mobile = :mobile,
                            timezone = :timezone,
                            address_1 = :address_1,
                            address_2 = :address_2,
                            dialer_mode=:dialer_mode
                            WHERE id = :id
                            ";
                $save = DB::connection('master')->update($query, $data);

                $client = Client::find($request->input('parentId'));
                if ($client && !empty($data_company['company_name'])) {
                    $client->company_name = $data_company['company_name'];
                    $client->save();
                }

                return array(
                    'success' => 'true',
                    'message' => 'Profile Detail updated successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Profile Detail not updated successfully.'
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /*
     * Fetch user menu details from user id
     *@param integer $id
     * @return array
     */
    public function userMenus($useCache = true)
    {
        try {
            $arrAssignedUserComponents = $this->getAssignedUserComponents($useCache);

            return [
                'success' => true,
                'message' => 'User Menu detail.',
                'data' => $arrAssignedUserComponents
            ];
        } catch (Exception $e) {
            Log::log($e->getMessage(), [$e->getLine(), $e->getFile(), $e->getCode()]);
            return array(
                'success' => false,
                'message' => 'Menu doesn\'t exist.'
            );
        }
    }

    /*
     * Fetch user menu details from user id
     *@param integer $id
     * @return array
     */
    public function updateUserPassword($id, $password, $new_password)
    {
        try {
            if (!empty($id) && is_numeric($id)) {
                $sql = "SELECT password,extension,alt_extension,app_extension FROM users WHERE id = :id";
                $record = DB::connection('master')->selectOne($sql, array('id' => $id));
                if (!empty($record)) {
                    if (!empty($record->password)) {

                        $sql_extension = "UPDATE user_extensions set secret = '" . $new_password . "' WHERE name =" . $record->extension . "";
                        DB::connection('master')->update($sql_extension);

                        $sql_alt = "UPDATE user_extensions set secret = '" . $new_password . "' WHERE name =" . $record->alt_extension . "";
                        DB::connection('master')->update($sql_alt);

                        if (!empty($record->app_extension)) {

                            $sql_app_extension = "UPDATE user_extensions set secret = '" . $new_password . "' WHERE name =" . $record->app_extension . "";
                            DB::connection('master')->update($sql_app_extension);
                        }



                        // Verify the password and generate the token
                        if (Hash::check($password, $record->password)) {

                            $sql = "UPDATE users set password = '" . Hash::make($new_password) . "' WHERE id =" . $id;
                            $record = DB::connection('master')->update($sql);
                            \App\Services\AuthAuditService::log((int) $id, 'password.changed');
                            return array(
                                'success' => 'true',
                                'message' => 'Password changed successfully.',
                                'data' => array()
                            );
                        } else {
                            return array(
                                'success' => 'false',
                                'message' => 'In correct old password.'
                            );
                        }
                    } else {
                        return array(
                            'success' => 'false',
                            'message' => 'User Menu records not created.'
                        );
                    }
                }
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }


    public function resetUserPassword($email, $new_password)
    {
        try {
            if (!empty($email)) {
                $sql = "SELECT email,id,extension,alt_extension FROM users WHERE email = :email";
                $record = DB::connection('master')->selectOne($sql, array('email' => $email));
                if (!empty($record)) {
                    if (!empty($record->email)) {
                        $sql = "UPDATE users set password = '" . Hash::make($new_password) . "' WHERE id ='" . $record->id . "'";
                        DB::connection('master')->update($sql);

                        $sql_extension = "UPDATE user_extensions set secret = '" . $new_password . "' WHERE name =" . $record->extension . "";
                        DB::connection('master')->update($sql_extension);

                        $sql_alt = "UPDATE user_extensions set secret = '" . $new_password . "' WHERE name =" . $record->alt_extension . "";
                        DB::connection('master')->update($sql_alt);

                        $reset_password_link = "UPDATE password_reset_email_varification set status = '0' WHERE email ='" . $email . "'";
                        DB::connection('master')->update($reset_password_link);
                        \App\Services\AuthAuditService::log((int) $record->id, 'password.reset', ['email' => $email]);
                        return array(
                            'success' => 'true',
                            'message' => 'Password changed successfully.',
                            'data' => array()
                        );
                    } else {
                        return array(
                            'success' => 'false',
                            'message' => 'Password change not successfully.'
                        );
                    }
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Password change not successfully.'
                    );
                }
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function updateAgentByAdminPassword($ext_id, $new_password)
    {
        try {
            if (!empty($new_password)) {
                // fetch user detail

                $UserData = User::where('id', '=', $ext_id)->get()->first();

                $sql_uext = "SELECT count(*) as row_count FROM user_extensions WHERE name = :name";
                $record_ustext = DB::connection('master')->selectOne($sql_uext, array('name' => $UserData->extension));
                $response_ust = (array)$record_ustext;
                if ($response_ust['row_count'] == 0) {
                    /*
                    $dt['name'] = $response_data['extension'];
                    $dt['username'] = $response_data['extension'];
                    */
                    $dt['name'] = $UserData->extension;
                    $dt['username'] = $UserData->extension;
                    $dt['secret'] = $new_password;
                    $dt['context'] = 'user-extensions-phones'; //'default';
                    $dt['host'] = 'dynamic';
                    $dt['nat'] = 'force_rport,comedia';
                    $dt['qualify'] = 'no';
                    $dt['type'] = 'friend';
                    $dt['fullname'] = $UserData->first_name . ' ' . $UserData->last_name;
                    $insertData = "INSERT INTO user_extensions SET fullname= :fullname, context= :context, name= :name, type= :type , qualify= :qualify , nat= :nat , host= :host, secret= :secret,username= :username";
                    $record_ustextSav = DB::connection('master')->select($insertData, $dt);
                } else {
                    // $dt['secret'] = $new_password;
                    //  $dt['name'] = $UserData->extension;
                    //UserExtension::where('name', '=', $UserData->extension)->update(array('secret' => $new_password));
                    //$insertData = "UPDATE user_extensions SET secret= :secret WHERE name= :name ";
                    //$record_ustext = DB::connection('master')->select($insertData, $dt);

                    $sql = "UPDATE user_extensions set secret = '" . $new_password . "' WHERE name =" . $UserData->extension;
                    $record = DB::connection('master')->update($sql);

                    $sql_alt = "UPDATE user_extensions set secret = '" . $new_password . "' WHERE name =" . $UserData->alt_extension;
                    $record = DB::connection('master')->update($sql_alt);

                    if (!empty($UserData->app_extension)) {
                        $sql_app_extension = "UPDATE user_extensions set secret = '" . $new_password . "' WHERE name =" . $UserData->app_extension;
                        $record = DB::connection('master')->update($sql_app_extension);
                    }
                }

                User::where('id', '=', $ext_id)->update(array('password' =>  Hash::make($new_password)));
                // Verify the password and generate the token
                //$sql = "UPDATE users set password = '" . Hash::make($new_password) . "' WHERE id =" . $ext_id;
                //$record = DB::connection('master')->update($sql);
                return array(
                    'success' => 'true',
                    'message' => 'Password changed successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Password changed not successfully.'
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            eLog::log($e->getMessage());
        }
    }

    public function updateAllowedIp($ext_id, $allowed_ip)
    {
        try {

            if (!empty($allowed_ip)) {
                // Verify the password and generate the token

                $sql = "UPDATE users set allowed_ip = '" . $allowed_ip . "' WHERE id =" . $ext_id;
                $record = DB::connection('master')->update($sql);
                return array(
                    'success' => 'true',
                    'message' => 'Allowed Ip changed successfully.',
                    //'data'   => array()
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Allowed Ip changed not successfully.'
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            eLog::log($e->getMessage());
        }
    }

    public function deleteVoicemail($auto_id, $voicemail_id, $parentId)
    {
        try {

            if (!empty($voicemail_id)) {
                // Verify the password and generate the token

                $sql = "delete from voicemail_drop WHERE id =" . $voicemail_id;
                $record = DB::connection('mysql_' . $parentId)->delete($sql);
                return array(
                    'success' => 'true',
                    'message' => 'Vm Drop Location delete successfully.',
                    //'data'   => array()
                );
            }

            if (!empty($auto_id)) {
                // Verify the password and generate the token

                $sql = "UPDATE users set vm_drop_location = '' WHERE id =" . $auto_id;
                $record = DB::connection('master')->update($sql);
                return array(
                    'success' => 'true',
                    'message' => 'Vm Drop Location delete successfully.',
                    //'data'   => array()
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Vm Drop Location delete not successfully.'
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            eLog::log($e->getMessage());
        }
    }

    public function updateVoiceMail($auto_id, $voice_mail_location)
    {


        try {
            if (!empty($auto_id)) {
                // Verify the password and generate the token

                $sql = "UPDATE users set vm_drop_location = '" . $voice_mail_location . "' WHERE id =" . $auto_id;
                $record = DB::connection('master')->update($sql);
                return array(
                    'success' => 'true',
                    'message' => 'Vm Drop Location Update successfully.',
                    //'data'   => array()
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Vm Drop Location update not successfully.'
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function editVoiceMailDrop($voicemail_id, $parentId)
    {


        try {
            if (!empty($voicemail_id)) {
                // Verify the password and generate the token

                $sql = "select * from voicemail_drop where id =" . $voicemail_id;
                $record = DB::connection('mysql_' . $parentId)->select($sql);
                return array(
                    'success' => 'true',
                    'message' => 'Vm Drop Location Update successfully.',
                    'data'   => $record
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Vm Drop Location update not successfully.'
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    public function editVoiceAi($voicemail_id, $parentId)
    {


        try {
            if (!empty($voicemail_id)) {
                // Verify the password and generate the token

                $sql = "select * from user_wise_voice_ai where id =" . $voicemail_id;
                $record = DB::connection('master')->select($sql);
                return array(
                    'success' => 'true',
                    'message' => 'Voice Ai Update successfully.',
                    'data'   => $record
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Voice Ai update not successfully.'
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    public function deleteVoiceAi($auto_id, $voicemail_id, $parentId)
    {
        try {

            if (!empty($voicemail_id)) {
                // Verify the password and generate the token

                $sql = "delete from user_wise_voice_ai WHERE id =" . $voicemail_id;
                $record = DB::connection('master')->delete($sql);
                return array(
                    'success' => 'true',
                    'message' => 'Voice Ai deleted successfully.',
                    //'data'   => array()
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            eLog::log($e->getMessage());
        }
    }
    function updateLogo(int $id, int $parentId, string $logo)
    {
        /** @var Client $client */
        $client = Client::findOrFail($parentId);
        $client->logo = $logo;
        $client->saveOrFail();
        ClientService::clearCache();
        return [
            'success' => 'true',
            'message' => 'Logo Updated Successfully'
        ];
    }

    function updateEmailSetting($emails, $chk)
    {

        //return $this->id;die;
        try {
            if (!empty($this->id) && is_numeric($this->id)) {
                $sql_setting = "Select * FROM user_setting";
                $record_show = DB::connection('mysql_' . $this->parent_id)->select($sql_setting, array());
                if (!empty($record_show)) {
                    $data = (array)$record_show;

                    //return $emails;

                    foreach ($emails as $key => $email) {


                        $mails = json_encode($email);
                        if (empty($chk[$key][0])) {
                            $chk[$key][0] = 0;
                        }



                        $updateData = "Update user_setting set sender_list='" . $mails . "',status='" . $chk[$key][0] . "' where auto_id= '" . $key . "'";
                        $record_updateData = DB::connection('mysql_' . $this->parent_id)->select($updateData, array());
                    }
                    return array(
                        'success' => 'true',
                        'message' => 'Email Setting  Updated Successfully.'
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Email Setting doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }


    /**
     * Get all user ids for pusher
     * @param type $request
     * @return type
     */
    function checkAndGetUserIdForPusher($platform, $to, $event)
    {
        try {
            $user_ids = [];
            switch ($platform) {
                case "fax":
                    $didData = $this->getUserAndParentFromDid($to);
                    if (isset($didData['parent_id'])) {
                        $user_ids = $this->getFaxPusherNotifcationData($didData['parent_id'], $to);
                    }
                    break;
                case "text":
                    $didData = $this->getUserAndParentFromDid($to);
                    if (isset($didData['user_id'])) {
                        $user_ids = [$didData['user_id']];
                    }
                    break;
                case "call":
                case "voicemail":
                    $user_ids = $this->getCallPusherNotifcationData($to, $event);
                    break;
            }

            return array(
                'success' => 'true',
                'message' => 'Cient ids for pusher notification',
                'data' => $user_ids
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        }
    }

    /**
     *
     * @param type $to
     * @return type
     */
    public function getUserAndParentFromDid($to)
    {
        $data = [];
        $data['cli'] = $to;
        $sql = "SELECT parent_id, user_id FROM did WHERE cli = :cli ";
        $did_record = DB::connection('master')->selectOne($sql, $data);
        if (!empty($did_record)) {
            return ['parent_id' => $did_record->parent_id, 'user_id' => $did_record->user_id];
        } else {
            return [];
        }
    }

    /**
     *
     * @param type $parent_id
     * @param type $to
     * @return type
     */
    public function getFaxPusherNotifcationData($parent_id, $to)
    {
        $user_ids = [];
        $data['to'] = $to;
        $sql = "SELECT userId FROM fax_did WHERE did = :to";
        $record = DB::connection('mysql_' . $parent_id)->select($sql, $data);
        $response = (array)$record;
        if (!empty($response)) {
            foreach ($response as $usr) {
                $user_ids[] = $usr->userId;
            }
        }
        return $user_ids;
    }

    /**
     *
     * @param type $parent_id
     * @param type $user_id
     * @param type $ext
     * @return type
     * @throws type
     */
    public function getCallPusherNotifcationData($ext, $event)
    {
        $user_ids = $extensions = [];
        $data['extension'] = $ext;
        $data['is_deleted'] = 0;
        $sql = "SELECT id, parent_id, extension, extension_type FROM users "
            . "WHERE extension = :extension and is_deleted = :is_deleted ";
        $record = DB::connection('master')->selectOne($sql, $data);
        $response = (array)$record;
        if (empty($response)) {
            throw new \Exception('No record found in user for given extension');
        } else {
            if ($event == 'received' || $event == 'completed') { //return single user id if event is rec || comp
                $user_ids[] = $response['id'];
                return $user_ids;
            }
            if ($response['extension_type'] == 1) { //return single user id if ext type == 1
                $user_ids[] = $response['id'];
            } else {
                $data = [];
                $data['extension'] = $response['extension'];
                $sql = "SELECT extensions FROM ring_group WHERE title = :extension";
                $record = DB::connection('mysql_' . $response['parent_id'])->select($sql, $data);
                $response = (array)$record;
                if (isset($response[0]->extensions) && $response[0]->extensions != '') {
                    $arrExt = explode("&", $response[0]->extensions);
                    foreach ($arrExt as $ext) {
                        $extensions[] = str_replace("SIP/", "", trim($ext));
                    }

                    $sql = "SELECT id FROM users WHERE extension IN (" . implode(',', $extensions) . ")";
                    $record = DB::connection('master')->select($sql);
                    $response = (array)$record;
                    if (!empty($response)) {
                        foreach ($response as $row) {
                            $user_ids[] = $row->id;
                        }
                    }
                }
            }
        }
        return $user_ids;
    }

    public function prepareObjectDataToArray($arrToPrepare, $key)
    {
        $arrToSend = [];
        foreach ($arrToPrepare as $modulesResult) {
            $arrModulesResult = (array)$modulesResult;
            $arrToSend = array_merge($arrToSend, json_decode($arrModulesResult[$key]));
        }
        return array_unique($arrToSend);
    }

    /**
     * @return array|mixed
     */
    public function getAssignedUserComponents($useCache = true)
    {
        if ($useCache && Cache::has("user.components.{$this->id}.{$this->parent_id}")) {
            $components = Cache::get("user.components.{$this->id}.{$this->parent_id}");
            if (!empty($components)) {
                return $components;
            }
        }

        if ($this->user_level > 7) {
            $modulesResultsPrepared = Module::all("key")->pluck("key")->toArray();
        } else {
            $modulesSql = "SELECT p.modules FROM master.packages as p
    JOIN master.client_packages as cp ON ( cp.package_key = p.key )
    JOIN client_{$this->parent_id}.user_packages as up ON ( cp.id = up.client_package_id )
    WHERE up.user_id = :user_id";
            $modulesResults         = DB::select($modulesSql, array('user_id' => $this->id));
            $modulesResultsPrepared = $this->prepareObjectDataToArray($modulesResults, 'modules');
            if ($this->user_level == 7) $modulesResultsPrepared[] = "client-admin";
        }

        $componentsSql              = "SELECT m.components FROM master.modules as m WHERE m.key IN ( '" . implode("', '", $modulesResultsPrepared) . "')";
        $componentsResults          = DB::select($componentsSql);
        $componentsResultsPrepared  = $this->prepareObjectDataToArray($componentsResults, 'components');

        $componentsSql      = "SELECT mc.* FROM master.module_components as mc WHERE min_level <= :min_level AND mc.key IN ( '" . implode("', '", $componentsResultsPrepared) . "') ORDER BY mc.display_order";
        $componentsResult   = DB::select($componentsSql, array('min_level' => $this->user_level));

        Cache::put("user.components.{$this->id}.{$this->parent_id}", $componentsResult, Carbon::now()->addHours(12));
        return $componentsResult;
    }

    /**
     * @return array|mixed
     */
    public function getAssignedUserPackage($noCache = false)
    {
        if (Cache::has("user.package.{$this->id}.{$this->parent_id}") && $noCache == false) {
            return Cache::get("user.package.{$this->id}.{$this->parent_id}");
        } else {
            $packageSql = "SELECT cp.*,
                                up.id as user_package_id,
                                up.client_package_id,
                                up.user_id,
                                up.free_call_minutes,
                                up.free_sms,
                                up.free_fax,
                                up.free_emails,
                                p.name,
                                p.currency_code,
                                p.call_rate_per_minute,
                                p.rate_per_sms,
                                p.rate_per_fax,
                                p.rate_per_email
                            FROM master.client_packages as cp
                                JOIN client_{$this->parent_id}.user_packages as up ON ( cp.id = up.client_package_id )
                                JOIN packages as p ON ( p.key = cp.package_key )
                            WHERE up.user_id = :user_id AND cp.end_time >= :current_date";
            $package    = DB::select($packageSql, array('user_id' => $this->id, 'current_date' => Carbon::now()));

            if (isset($package[0])) {
                Cache::put("user.package.{$this->id}.{$this->parent_id}", $package[0], Carbon::now()->addHours(12));
                return $package[0];
            }
            return null;
        }
    }

    public function switchClient(int $clientId)
    {
        $permissions = $this->getPermissions();
        if (isset($permissions[$clientId])) {
            $this->parent_id = $clientId;
            $this->role = $permissions[$clientId]["roleId"];
            $this->user_level = $permissions[$clientId]["roleLevel"];
            $this->update();
            return $this;
        }

        throw new UnauthorizedException("You do not have permissions for client id $clientId");
    }

    public static function getAllSuperAdmins() //change role by 5 to 6 for system administrator
    {
        $adminIds = [];
        $sql = "SELECT DISTINCT user_id as user_id FROM master.permissions WHERE role=6";
        $result = DB::select($sql);
        foreach ($result as $record) {
            $adminIds[] = $record->user_id;
        }
        return $adminIds;
    }

    public function activePlanList($request, $cleint_id = "")
    {
        if ($request->auth->level > 7) {
            $packageSql = "SELECT cp.*, p.* from master.client_packages as cp JOIN  packages as p ON ( p.key = cp.package_key ) WHERE cp.client_id = :client_id AND cp.end_time >= :current_date";

            $package = DB::select($packageSql, array('client_id' => $cleint_id, 'current_date' => Carbon::now()));
            $data = (array) $package;
            foreach ($data as $key => $used) {
                $usedPackage = "SELECT count(user_id) as InUsed from client_{$request->auth->parent_id}.user_packages where client_package_id=:client_id";

                $licencedInUsed    = DB::select($usedPackage, array('client_id' => $used->client_id));
                $data[$key]->InUsed = $licencedInUsed[0]->InUsed;
            }
        } else {
            $packageSql = "SELECT cp.*, p.*,up.* from master.client_packages as cp JOIN client_{$request->auth->parent_id}.user_packages as up ON ( cp.id = up.client_package_id ) JOIN packages as p ON ( p.key = cp.package_key ) WHERE up.user_id = :user_id AND cp.end_time >= :current_date";

            $package    = DB::select($packageSql, array('user_id' => $request->auth->id, 'current_date' => Carbon::now()));
            $data = (array) $package;
            foreach ($data as $key => $used) {
                $usedPackage = "SELECT count(user_id) as InUsed from client_{$request->auth->parent_id}.user_packages where client_package_id=:client_id";
                $licencedInUsed    = DB::select($usedPackage, array('client_id' => $used->client_id));
                $data[$key]->InUsed = $licencedInUsed[0]->InUsed;
            }
        }

        if (isset($data)) {
            return $data;
        }
        return null;
    }
}

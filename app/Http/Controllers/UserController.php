<?php

namespace App\Http\Controllers;

use App\Model\User;
use App\Model\UserSettings;
use App\Model\Master\Permission;
use App\Model\Master\AsteriskServer;
use App\Model\Master\ForgotPasswordLink;
use App\Model\Master\OtpVerification;
use App\Model\Master\Client;
use App\Model\Master\WebEmailVerification;
use App\Model\Master\WebPhoneVerification;

use App\Model\Master\WebLeads;


use App\Model\Client\UserToken;
use App\Model\Client\VoiceMailDrop;
use App\Model\Master\VoiceAi;


use Plivo\RestClient;

use App\Services\RolesService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Master\LoginLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use DB;


use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Support\Str;
use App\Model\Master\PasswordReset;
use Illuminate\Support\Facades\Mail;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Services\MailService;
use App\Services\SystemMailerService;
use App\Mail\SystemNotificationMail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
class UserController extends Controller
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

    /**
     * @OA\Get(
     *     path="/user/{userId}/permission",
     *     summary="Retrieve the list of user permissions",
     *     tags={"User"},
     *    security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         required=true,
     *         description="ID of the user whose permission details are to be retrieved",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User permissions retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="User permissions retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="permission_id", type="integer", example=101),
     *                     @OA\Property(property="permission_name", type="string", example="edit_profile")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User ID not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="User ID not found.")
     *         )
     *     )
     * )
     */
        public function getSelectedUsers(Request $request)
    {
        $user = new User();

        // Step 1: get allowed columns from the model
        $columns = $user->getTableColumns();

        // Step 2: fetch only these columns from DB
        $users = User::select($columns)->get();

        // Step 3: return API response
        return response()->json([
            'status' => true,
            'message' => 'Users fetched successfully',
            'columns' => $columns,
            'data' => $users
        ]);
    }
    public function showPermission(int $userId)
    {
        try {
            /** @var User $user */
            $user = User::findOrFail($userId);
            $permissions = $user->getPermissions();
            return response()->json($permissions);
        } catch (ModelNotFoundException $modelNotFoundException) {
            throw new NotFoundHttpException("Resource with userId $userId not found");
        }
    }
    /**
     * @OA\Put(
     *     path="/user/{userId}/permission",
     *     summary="Assigns a permission role to a user",
     *     description="Assigns  a role for a user under the authenticated client (parent_id).",
     *     operationId="putUserPermission",
     *     tags={"User"},
     *    security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         required=true,
     *         description="ID of the user to assign role permission",
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"role"},
     *             @OA\Property(
     *                 property="role",
     *                 type="integer",
     *                 example=2,
     *                 description="Role ID to assign"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Permission updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="permissions", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="client_id", type="integer", example=1),
     *                     @OA\Property(property="role", type="integer", example=2)
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */

    public function addPermission(int $userId)
    {
        $this->validate($this->request, [
            'role' => 'required|exists:master.roles,id'
        ]);

        $input = $this->request->all();
        try {
            /** @var User $user */
            $user = User::findOrFail($userId);
            $user->addPermission($this->request->auth->parent_id, $input['role']);
        } catch (ModelNotFoundException $modelNotFoundException) {
            throw new NotFoundHttpException("Resource with userId $userId not found");
        }

        #store new permissions in cache
        $newUser = $user->toArray();
        $newUser["permissions"] = $user->getPermissions(true);
        return response()->json($newUser);
    }

    /**
     * @OA\Post(
     *     path="/user/{userId}/super-admin-permission",
     *     summary="Update Super Admin Permissions",
     *     description="Updates the permissions of a user to Super Admin.",
     *     tags={"User"},
     *  security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id","roleId", "clients_name"},
     *  @OA\Property(property="user_id", type="integer", example=5),
     *                       
     *  @OA\Property(property="roleId", type="integer", example=5),
     *             @OA\Property(
     *                 property="clients_name",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={1,2,3}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permissions updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(
     *                 property="permissions",
     *                 type="array",
     *                 @OA\Items(type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     )
     * )
     */
    public function updatePermissionSuperAdmin(Request $request)
    {
        $userId = $request->user_id;
        $roleId = $request->roleId;
        $permission_delete = Permission::where('user_id', $userId)->delete();

        if ($roleId == 5) //role Id Super admin
        {
            // $clients = \App\Model\Master\Client::all();
            $clients = $request->clients_name;
            foreach ($clients as $client) {
                $permission = new Permission();
                $permission->user_id = $userId;
                $permission->client_id = $client;
                $permission->role = $roleId;
                try {
                    $permission->saveOrFail();
                } catch (ModelNotFoundException $modelNotFoundException) {
                    throw new NotFoundHttpException("Resource with userId $userId not found");
                }
            }
        }


        try {
            /** @var User $user */
            $user = User::findOrFail($userId);
            $user->updatePermission($this->request->auth->parent_id, $roleId);
        } catch (ModelNotFoundException $modelNotFoundException) {
            throw new NotFoundHttpException("Resource with userId $userId not found");
        }

        #store new permissions in cache
        $newUser = $user->toArray();
        $newUser["permissions"] = $user->getPermissions(true);
        return response()->json($newUser);
    }
    /**
     * @OA\Post(
     *     path="/user/{userId}/permission",
     *     summary="Update a permission role to a user",
     *     tags={"User"},
     *    security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         required=true,
     *         description="ID of the user to assign or update permission",
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"role"},
     *             @OA\Property(
     *                 property="role",
     *                 type="integer",
     *                 example=2,
     *                 description="Update a Role ID to assign"
     *             )
     *         )
     *     ),
     *
     *    *     @OA\Response(
     *         response=200,
     *         description="User permissions updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=123),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *             @OA\Property(
     *                 property="permissions",
     *                 type="array",
     *                 @OA\Items(type="string", example="edit_profile")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function updatePermission(int $userId)
    {
        $this->validate($this->request, [
            'role' => 'required|exists:master.roles,id'
        ]);

        $input = $this->request->all();
        $permission_delete = Permission::where('user_id', $userId)->delete();

        if ($input['role'] == 5) //role Id Super admin
        {
            $clients = \App\Model\Master\Client::all();
            foreach ($clients as $client) {
                $permission = new Permission();
                $permission->user_id = $userId;
                $permission->client_id = $client->id;
                $permission->role = $input['role'];
                try {
                    $permission->saveOrFail();
                } catch (ModelNotFoundException $modelNotFoundException) {
                    throw new NotFoundHttpException("Resource with userId $userId not found");
                }
            }
        } else {
            $permission = new Permission();
            $permission->user_id = $userId;
            $permission->client_id = $this->request->auth->parent_id;
            $permission->role = $input['role'];
            try {
                $permission->saveOrFail();
            } catch (ModelNotFoundException $modelNotFoundException) {
                throw new NotFoundHttpException("Resource with userId $userId not found");
            }
        }

        try {
            /** @var User $user */
            $user = User::findOrFail($userId);
            $user->updatePermission($this->request->auth->parent_id, $input['role']);
        } catch (ModelNotFoundException $modelNotFoundException) {
            throw new NotFoundHttpException("Resource with userId $userId not found");
        }

        #store new permissions in cache
        $newUser = $user->toArray();
        $newUser["permissions"] = $user->getPermissions(true);
        return response()->json($newUser);
    }
     public function updatePermissionNew()
    {  
        Log::info('reached update',[$this->request->auth->parent_id]);
        $this->validate($this->request, [
            'role' => 'required|exists:master.roles,id',
            'user_id'=>'required',
        ]);

        $input = $this->request->all();
        $permission_delete = Permission::where('user_id', $input['user_id'])->delete();

        if ($input['role'] == 5) //role Id Super admin
        {
            $clients = \App\Model\Master\Client::all();
            foreach ($clients as $client) {
                $permission = new Permission();
                $permission->user_id = $input['user_id'];
                $permission->client_id = $client->id;
                $permission->role = $input['role'];
                try {
                    $permission->saveOrFail();
                } catch (ModelNotFoundException $modelNotFoundException) {
                    throw new NotFoundHttpException("Resource with userId $userId not found");
                }
            }
        } else {
            $permission = new Permission();
            $permission->user_id = $input['user_id'];
            $permission->client_id = $this->request->auth->parent_id;
            $permission->role = $input['role'];
            try {
                $permission->saveOrFail();
            } catch (ModelNotFoundException $modelNotFoundException) {
                throw new NotFoundHttpException("Resource with userId $userId not found");
            }
        }

        try {
            /** @var User $user */
            $user = User::findOrFail($input['user_id']);
            $user->updatePermissionNew($this->request->auth->parent_id, $input['role'],$input['user_id']);
        } catch (ModelNotFoundException $modelNotFoundException) {
            throw new NotFoundHttpException("Resource with userId $userId not found");
        }

        #store new permissions in cache
        $newUser = $user->toArray();
        $newUser["permissions"] = $user->getPermissions(true);
        return response()->json($newUser);
    }
    /**
     * @OA\Delete(
     *     path="/user/{userId}/permission",
     *     summary="Remove a specific permission from a user",
     *     tags={"User"},
     *    security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         description="ID of the user from whom the permission will be removed",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Permission removed successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(
     *                 property="permissions",
     *                 type="array",
     *                 @OA\Items(type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request"
     *     )
     * )
     */
    public function removePermission(int $userId)
    {
        try {
            /** @var User $user */
            $user = User::findOrFail($userId);
            $user->removePermission($this->request->auth->parent_id);
        } catch (ModelNotFoundException $modelNotFoundException) {
            throw new NotFoundHttpException("Resource with userId $userId not found");
        }

        #store new permissions in cache
        $newUser = $user->toArray();
        $newUser["permissions"] = $user->getPermissions(true);
        return response()->json($newUser);
    }

    /**
     * @OA\Post(
     *     path="/switch-client/{clientId}",
     *     summary="Switch authenticated user to another client context",
     *     description="Allows an authenticated user to switch to another client if they have permissions for that client.",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="clientId",
     *         in="path",
     *         required=true,
     *         description="Client ID to switch to",
     *         @OA\Schema(type="integer", example=45)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client switched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="User client switched"),
     *             @OA\Property(property="data", type="object",
     *                 description="Updated user data",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="parent_id", type="integer", example=45),
     *                 @OA\Property(property="role", type="string", example="admin"),
     *                 @OA\Property(property="user_level", type="string", example="2")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized to switch client",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="You do not have permissions for client id 45")
     *         )
     *     )
     * )
     */

    public function switchClient($clientId)
    {
        /** @var User $user */
        $user = $this->request->user();
        $user = $user->switchClient($clientId);
        return array(
            'success' => true,
            'message' => 'User client switched',
            'data' => $user->toArray()
        );
    }
    /**
     * @OA\Post(
     *     path="/user/{userId}/assignable-roles",
     *     summary="Retrieve assignable roles for a user",
     *     tags={"User"},
     *    security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         required=true,
     *         description="ID of the user to retrive assignable roles",
     *         @OA\Schema(type="integer")
     *     ),
     *        @OA\Response(
     *         response=200,
     *         description="Assignable roles for a user retrieved successfully",
     *         @OA\JsonContent(
     *             description="extension data"
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    // public function assignableRoles(int $userId)
    // {
    //     try {
    //         $allowedRoles = RolesService::getRolesForLevel($this->request->auth->level);

    //         /** @var User $user */
    //         $user = User::findOrFail($userId);
    //     } catch (ModelNotFoundException $modelNotFoundException) {
    //         throw new NotFoundHttpException("Resource with userId $userId not found");
    //     }

    //     return array(
    //         'success' => true,
    //         'message' => 'Allowed roles',
    //         'data' => $user->assignableRoles($allowedRoles, $this->request->auth->parent_id)
    //     );
    // }
    public function assignableRoles( int $userId) 
{
    $allowedRoles = RolesService::getRolesForLevel($this->request->auth->level);
     $user = User::findOrFail($userId);
    $response = [];
    foreach ($allowedRoles as $roleId => $roleName) {
        $response[] = [
            "roleId"   => $roleId,
            "roleName" => $roleName,
            "assigned" => ($roleId == $user["roleId"])
        ];
    }
    return $response;
}
public function assignableRolesNew(Request $request) 
{

    $allowedRoles = RolesService::getRolesForLevel($this->request->auth->level);
    $user = User::findOrFail($request->userId);

    $response = [];
    foreach ($allowedRoles as $roleId => $roleName) {
        $response[] = [
            "roleId"   => $roleId,
            "roleName" => $roleName,
            "assigned" => ($roleId == $user->roleId)
        ];
    }
        return response()->json([
        'success' => true,
        'message' => 'Allowed roles',
        'data'    => $response,   // return the roles list
    ]);
}


    /*
     * Fetch user details
     * @return json
     */
    /**
     * @OA\post(
     *     path="/user-detail",
     *     summary="Get authenticated user's profile details",
     *     tags={"User"},
     *      security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User profile detail retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User Profile detail"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 example={
     *                     "id": 1,
     *                     "name": "John Doe",
     *                     "email": "john.doe@example.com",
     *                     "timezone": "America/New_York",
     *                     
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated")
     *         )
     *     )
     * )
     */
    public function userDetail(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        return $this->successResponse('User Profile detail', $user->userDetail());
    }

    /*
     * Update user details
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/update-profile",
     *     summary="Update user's profile details",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"first_name", "email", "timezone"},
     *             @OA\Property(property="id", type="integer", example=123, description="User ID"),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john.doe@example.com"),
     *             @OA\Property(property="timezone", type="string", example="America/New_York"),
     *             @OA\Property(property="phone_number", type="string", example="12345678900"),
     *             @OA\Property(property="company_name", type="string", example="Example Inc."),
     *             @OA\Property(property="address_1", type="string", example="123 Main Street"),
     *             @OA\Property(property="address_2", type="string", example="Suite 4B"),
     *             @OA\Property(property="dialer_mode", type="string", example="predictive"),
     *             @OA\Property(property="parentId", type="integer", example=45, description="Client/parent ID")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Profile updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Profile Detail updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation or update error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Email id already exist.")
     *         )
     *     )
     * )
     */

    public function userProfileUpdate(User $user)
    {
        $this->validate($this->request, [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email',
            'timezone' => 'required',
            'phone_number' => 'numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'company_name' => 'string|max:255',
            'address_1' => 'string|max:255',
            'address_2' => 'string|max:255',
            'id' => 'numeric'
        ]);
        $response = $user->userProfileUpdate($this->request);

        // Sync to Easify (best-effort — never block the response if it fails)
        try {
            $userProfile = User::where('id', $this->request->input('id'))->first();
            $easify_user_uuid = $userProfile->easify_user_uuid ?? null;
            if ($easify_user_uuid && env('EASIFY_URL')) {
                $easifyResponse = Http::withHeaders([
                    'X-Application-Token' => env('PHONIFY_APP_TOKEN'),
                    'X-Easify-User-Token' => $easify_user_uuid,
                    'Content-Type'        => 'application/json'
                ])->post(env('EASIFY_URL') . '/api/user/profile/update', [
                    'first_name' => $this->request->first_name,
                    'last_name'  => $this->request->last_name,
                    'timezone'   => $this->request->timezone,
                ]);
                if (!$easifyResponse->successful()) {
                    Log::warning('Easify profile sync failed (non-blocking)', [
                        'user_id' => $this->request->input('id'),
                        'status'  => $easifyResponse->status(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Easify profile sync exception (non-blocking)', ['error' => $e->getMessage()]);
        }

        return response()->json($response);
        
    }

    /*
     * Update user details
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/update-user-password",
     *     summary="Update user password and related extension secrets",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "password", "new_password"},
     *             @OA\Property(property="id", type="integer", example=101, description="User ID"),
     *             @OA\Property(property="password", type="string", format="password", example="oldPassword123", description="Current password"),
     *             @OA\Property(property="new_password", type="string", format="password", example="newSecurePass456", description="New password to be set")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password changed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Password changed successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid current password or update failure",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="In correct old password.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found or password empty",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="User Menu records not created.")
     *         )
     *     )
     * )
     */

    public function updateUserPassword(User $user)
    {
        $response = $user->updateUserPassword($this->request->input('id'), $this->request->input('password'), $this->request->input('new_password'));
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/reset-password",
     *     summary="Reset user password",
     *     description="Reset a user's password using a valid token and a new password. The token must be obtained via the forgot-password flow.",
     *     tags={"User"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token", "new_password"},
     *             @OA\Property(
     *                 property="token",
     *                 type="string",
     *                 example="123456",
     *                 description="Reset token sent to the user's email"
     *             ),
     *             @OA\Property(
     *                 property="new_password",
     *                 type="string",
     *                 format="password",
     *                 example="MySecurePass123!",
     *                 description="The new password to set"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password reset successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Password changed successfully."),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Reset failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Password change not successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Token not found or invalid",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Invalid or expired token.")
     *         )
     *     )
     * )
     */

    public function resetPassword(User $user)
    {
        $ForgotPasswordLink = ForgotPasswordLink::findOrFail($this->request->input('token'));
        $response = $user->resetUserPassword($ForgotPasswordLink->email, $this->request->input('new_password'));
        return response()->json($response);
    }

    /*
     * Fetch user menu details
     * @return json
     */
    public function userMenus(Request $request)
    {
        $useCache = $request->get("useCache", true);
        return response()->json($request->user()->userMenus($useCache));
    }

    /**
     * @OA\Post(
     *     path="/update-agent-password-by-admin",
     *     summary="Update agent's SIP and login password by admin",
     *     description="Allows an admin to update a user's SIP password and login password based on extension ID.",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"ext_id", "password"},
     *             @OA\Property(property="ext_id", type="integer", example=123, description="User ID (extension ID) to update"),
     *             @OA\Property(property="password", type="string", example="new_secure_password", description="New password to set for user")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password update status",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Password changed successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */

    public function updateAgentByAdminPassword(User $user)
    {
        $response = $user->updateAgentByAdminPassword($this->request->input('ext_id'), $this->request->input('password'));
        return response()->json($response);
    }


    public function updateAllowedIp(User $user)
    {
        $response = $user->updateAllowedIp($this->request->input('ext_id'), $this->request->input('allowed_ip'));
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/delete-voicemail",
     *     summary="Delete a voicemail drop or reset user's voicemail drop location",
     *     description="Deletes a voicemail drop entry if voicemail_id is provided, or clears vm_drop_location for a user if auto_id is provided.",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="auto_id", type="integer", example=101, description="User ID to reset voicemail drop location"),
     *             @OA\Property(property="voicemail_id", type="integer", example=55, description="Voicemail ID to delete from voicemail_drop table"),
     *             @OA\Property(property="parentId", type="integer", example=12, description="Client-specific DB identifier for multi-tenant connection")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voicemail entry deleted or user voicemail drop reset",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Vm Drop Location delete successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing parameters or deletion failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Vm Drop Location delete not successfully.")
     *         )
     *     )
     * )
     */


    public function deleteVoicemail(User $user)
    {
        $response = $user->deleteVoicemail($this->request->input('auto_id'), $this->request->input('voicemail_id'), $this->request->input('parentId'));
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/edit-voicemail",
     *     summary="Fetch voicemail drop details by ID",
     *     tags={"User"},
     *  security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"voicemail_id", "parentId"},
     *             @OA\Property(property="voicemail_id", type="integer", example=1),
     *             @OA\Property(property="parentId", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Vm Drop Location Update successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="Voicemail Title"),
     *                     @OA\Property(property="file_path", type="string", example="/storage/vm/file.wav"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     )
     * )
     */
    public function editVoiceMailDrop(User $user)
    {
        $response = $user->editVoiceMailDrop($this->request->input('voicemail_id'), $this->request->input('parentId'));
        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/edit-voiceai",
     *     summary="Fetch a specific Voice AI record by ID",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"voicemail_id"},
     *             @OA\Property(property="voicemail_id", type="integer", example=1),
     *             @OA\Property(property="parentId", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice AI record fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Voice Ai Update successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="user_id", type="integer", example=99),
     *                     @OA\Property(property="speech_text", type="string", example="Hello, how can I assist you?"),
     *                     @OA\Property(property="language", type="string", example="en"),
     *                     @OA\Property(property="voice_name", type="string", example="Amy"),
     *                     @OA\Property(property="file_name", type="string", example="-"),
     *                     @OA\Property(property="ivr_desc", type="string", example="Welcome Message"),
     *                     @OA\Property(property="prompt_option", type="string", example="1"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-28T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-28T10:15:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid voicemail ID"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */


    public function editVoiceAi(User $user)
    {
        $response = $user->editVoiceAi($this->request->input('voicemail_id'), $this->request->input('parentId'));
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/update-voiceai",
     *     summary="Update a specific Voice AI record",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"auto_id"},
     *             @OA\Property(property="auto_id", type="integer", example=1, description="The ID of the Voice AI record to update"),
     *             @OA\Property(property="file_name", type="string", example="greeting.wav"),
     *             @OA\Property(property="ivr_desc", type="string", example="Updated Voice Prompt"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Amy"),
     *             @OA\Property(property="speech_text", type="string", example="Updated speech text content."),
     *             @OA\Property(property="prompt_option", type="string", example="2")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice AI record updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Updated Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="user_id", type="integer", example=101),
     *                 @OA\Property(property="file_name", type="string", example="greeting.wav"),
     *                 @OA\Property(property="ivr_desc", type="string", example="Updated Voice Prompt"),
     *                 @OA\Property(property="language", type="string", example="en"),
     *                 @OA\Property(property="voice_name", type="string", example="Amy"),
     *                 @OA\Property(property="speech_text", type="string", example="Updated speech text content."),
     *                 @OA\Property(property="prompt_option", type="string", example="2"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-27T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-28T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Voice AI record not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save Voice AI setting"
     *     )
     * )
     */

    public function updateVoiceAi(Request $request)
    {
        try {
            $input = $request->all();

            if (empty($input['auto_id']) || !is_numeric($input['auto_id']) || (int) $input['auto_id'] <= 0) {
                return $this->failResponse("Invalid auto_id.", [], null, 400);
            }
            if (!empty($input['file_name']) && basename($input['file_name']) !== $input['file_name']) {
                return $this->failResponse("Invalid file_name.", [], null, 400);
            }

            $VoiceAi = VoiceAi::on('master')
                ->where('id', (int) $input['auto_id'])
                ->firstOrFail();
            Log::info('retrieved value', ['VoiceAi' => $VoiceAi]);
            if (!empty($input["file_name"])) $VoiceAi->file_name = $input["file_name"];
            if (!empty($input["ivr_desc"])) $VoiceAi->ivr_desc = $input["ivr_desc"];
            if (!empty($input["language"])) $VoiceAi->language = $input["language"];
            if (!empty($input["voice_name"])) $VoiceAi->voice_name = $input["voice_name"];
            if (!empty($input["speech_text"])) $VoiceAi->speech_text = $input["speech_text"];
            if (!empty($input["prompt_option"])) $VoiceAi->prompt_option = $input["prompt_option"];

            // Assign the user_id to the VoiceAi object
            $VoiceAi->user_id = $request->auth->id;

            // Log the VoiceAi data for debugging purposes
            Log::info('reached voiceai', ['voiceAi' => $VoiceAi]);

            // Save the changes to the VoiceAi object
            $VoiceAi->save();
            Log::info('saved voiceai', ['voiceAi' => $VoiceAi]);

            // Return a success response
            return $this->successResponse("Updated Successfully", $VoiceAi->toArray());
        } catch (\Throwable $exception) {
            // Return a failure response in case of an exception
            return $this->failResponse("Failed to save Voice Ai setting", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/delete-voiceai",
     *     summary="Delete a specific Voice AI record by ID",
     *     tags={"User"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"voicemail_id"},
     *             @OA\Property(property="auto_id", type="integer", example=1),
     *             @OA\Property(property="voicemail_id", type="integer", example=5),
     *             @OA\Property(property="parentId", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice AI record deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Voice Ai deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request or voicemail_id missing"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function deleteVoiceAi(User $user)
    {
        $response = $user->deleteVoiceAi($this->request->input('auto_id'), $this->request->input('voicemail_id'), $this->request->input('parentId'));
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/update-voice-mail",
     *     summary="Update Voice Mail Drop location",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"auto_id", "vm_drop_location"},
     *             @OA\Property(property="auto_id", type="integer", example=1, description="The ID of the voicemail drop record"),
     *             @OA\Property(property="vm_drop_location", type="string", example="Location 2", description="The new drop location")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice Mail Drop location updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Voice Mail Drop updated successfully."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="vm_drop_location", type="string", example="Location 2"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-28T14:30:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Voice Mail Drop record not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function updateVoiceMail(User $user)
    {
        $response = $user->updateVoiceMail($this->request->input('auto_id'), $this->request->input('vm_drop_location'));
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/update-logo",
     *     summary="Update client logo for the authenticated user's organization",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "parentId", "logo"},
     *             @OA\Property(property="id", type="integer", example=123, description="ID of the user or entity"),
     *             @OA\Property(property="parentId", type="integer", example=10, description="Parent client ID"),
     *             @OA\Property(property="logo", type="string", example="https://example.com/logo.png", description="New logo URL or base64 image string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Logo updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Logo Updated Successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Client not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No query results for model [Client] ...")
     *         )
     *     )
     * )
     */

    public function updateLogo(User $user)
    {
        $response = $user->updateLogo($this->request->input('id'), $this->request->input('parentId'), $this->request->input('logo'));
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/user-setting",
     *     summary="Retrieve user settings based on ID and parent client",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "parentId"},
     *             @OA\Property(property="id", type="integer", example=123, description="User ID"),
     *             @OA\Property(property="parentId", type="integer", example=10, description="Parent client ID used for dynamic DB connection")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User setting data retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="User Setting detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="auto_id", type="integer", example=1),
     *                     @OA\Property(property="sender_list", type="array", @OA\Items(type="string"), example={"noreply@example.com"}),
     *                     @OA\Property(property="status", type="integer", example=1)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User setting not found or error occurred",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="User Setting Not Exit."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */


    public function userSetting(User $user)
    {
        $response = $user->userSetting($this->request->input('id'), $this->request->input('parentId'));
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/update-email-setting",
     *     summary="Update the authenticated user's email settings",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"emails", "chk"},
     *             @OA\Property(
     *                 property="emails",
     *                 type="object",
     *                 additionalProperties=@OA\Property(
     *                     type="array",
     *                     @OA\Items(type="string")
     *                 ),
     *                 example={
     *                     "1": {"noreply@example.com"},
     *                     "2": {"alerts@example.com"}
     *                 }
     *             ),
     *             @OA\Property(
     *                 property="chk",
     *                 type="object",
     *                 additionalProperties=@OA\Property(
     *                     type="array",
     *                     @OA\Items(type="integer", example=1)
     *                 ),
     *                 example={
     *                     "1": {1},
     *                     "2": {0}
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Email settings updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Email Setting Updated Successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Email settings not found or failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Email Setting doesn't exist.")
     *         )
     *     )
     * )
     */

    public function updateEmailSetting(User $user)
    {
        //return $this->request->input('emails');

        $this->validate($this->request, [
            'emails' => 'array',
        ]);

        $user = User::find($this->request->auth->id);
        $response = $user->updateEmailSetting($this->request->input('emails'), $this->request->input('chk'));

        return response()->json($response);
    }


    public function hangupConferences(Request $request)
    {
        try {
            $extension = $this->request->input('extension');
            $data = User::where('extension', $extension)->get();
            $server = AsteriskServer::find($data[0]->asterisk_server_id);
            if (!empty($server->host)) {
                $data["server"] = $server->host;
            } else {
                $data["server"] = null;
            }

            //return $data["server"];

            if ($server) {
                #hangupConferences
                $data["hangupConferences"] = $server->hangupConferences($request->auth->parent_id, $extension);
                //Log::info("authentication", $data);
                return $this->successResponse("Hangup Conference execute successful", []);
            }
        } catch (\Throwable $exception) {
            return $this->failResponse($exception->getMessage(), [], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Post(
     *      path="/check-loggedin-user",
     *      summary="check loggedin user",
     *      tags={"ipWhiteListLoggedInUser"},
     *      security={{"Bearer":{}}},
     *      @OA\Response(
     *          response="200",
     *          description="ip whitelisting data"
     *      )
     * )
     */


    function ipWhiteListLoggedInUser(Request $request)
    {
        //dd($request);
        $clientIp  = $request->ip();
        $parentId  = $request->auth->parent_id;
        $userId    = $request->auth->id;
        $userAgent = $request->userAgent();

        $user = User::findOrFail($userId);
        $loginUser = $user->toArray();
        $server = AsteriskServer::find($loginUser["asterisk_server_id"]);

        if ($server) {
            try {
                $response = [
                    "id" => $loginUser["id"],
                    "first_name" => $loginUser["first_name"],
                    "last_name" => $loginUser["last_name"],
                    "mobile" => $loginUser["mobile"],
                    "email" => $loginUser["email"],
                    "ip" => $clientIp,
                    "did" => $loginUser["cli"]
                ];

                //echo "<pre>";print_r($response);die;
                #whitelist the IP on the server
                $requestWhiteList = $server->whiteListIp($clientIp, $userId, $parentId);
                $log = new LoginLog();
                $log->user_id = $userId;
                $log->client_id = $parentId;
                $log->ip = $clientIp;
                $log->user_agent = $userAgent;
                $log->save();
                return $this->successResponse("IP Whitelisted For Loggedin User", $response);
            } catch (\Throwable $exception) {
                Log::warning("Authentication failed to whiteListIp", [
                    "clientIp" => $clientIp,
                    "message" => $exception->getMessage(),
                    "file" => $exception->getFile(),
                    "line" => $exception->getLine(),
                    "code" => $exception->getCode()
                ]);

                return $this->failResponse($exception->getMessage(), [], $exception, $exception->getCode());
            }
        }
    }


    /**
     * @OA\Post(
     *     path="/user-token-data",
     *     summary="devicetoken and device type",
     *     tags={"user device token and type"},
     *      @OA\Parameter(
     *          name="deviceToken",
     *          description="deviceToken",
     *          required=true,
     *          in="query",
     *         @OA\Schema(
     *           type="string"
     *         )
     *      ),
     *      @OA\Parameter(
     *          name="deviceType",
     *          description="deviceType",
     *          required=true,
     *          in="query",
     *         @OA\Schema(
     *           type="string"
     *         )
     *      ),
     *      security={{"Bearer":{}}},
     *      @OA\Response(
     *          response="200",
     *          description="Login successful"
     *      ),
     *      @OA\Response(
     *          response="401",
     *          description="Invalid email or password"
     *      )
     * )
     */

    function userTokenData(Request $request)
    {
        //echo "<pre>";print_r($request->all());die;
        $clientId       = $request->auth->parent_id;
        $userId         = $request->auth->id;
        $deviceToken    = $request->deviceToken;
        $deviceType     = $request->deviceType;
        $push_token     = $request->push_token;

        try {
            $userData = UserToken::on('mysql_' . $request->auth->parent_id)->where('userId', $request->auth->id)->get()->toArray();

            if (!empty($userData)) {
                if ($deviceToken == $userData[0]['deviceToken']) {
                    return $this->successResponse("User Token Already Exist", []);
                } else {

                    $user = UserToken::on("mysql_" . $request->auth->parent_id)->findOrFail($userId);
                    //echo "<pre>";print_r($user);die;
                    if ($request->has("deviceToken"))
                        $user->deviceToken = $request->input("deviceToken");
                    if ($request->has("deviceType"))
                        $user->deviceType = $request->input("deviceType");
                    $user->saveOrFail();
                    return $this->successResponse("User Token Updated Successfully", $user->toArray());
                }
            } else {
                $userToken = new userToken();
                $userToken->setConnection("mysql_$clientId");
                $userToken->userId = $userId;
                $userToken->deviceToken = $deviceToken;
                $userToken->deviceType = $deviceType;
                $userToken->push_token = $push_token;
                $userToken->save();
                return $this->successResponse("User Token Added Successfully", $userToken->toArray());
            }
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to Save user Token ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


    /**
     * @OA\Post(
     *      path="/delete-usertoken",
     *      summary="Delete Usertoken",
     *      tags={"Usertoken"},
     *      security={{"Bearer":{}}},
     *      @OA\Response(
     *          response="200",
     *          description="usertoken data"
     *      )
     * )
     */
    public function deleteUserToken(Request $request)
    {
        $clientId       = $request->auth->parent_id;
        $userId         = $request->auth->id;

        try {
            //$token = UserToken::on("mysql_" . $request->auth->parent_id)->findOrFail($userId);
            $token = UserToken::on("mysql_" . $request->auth->parent_id)->where(array('userId' => $userId))->get();
            //$deleted = $token->delete();
            $deleted = UserToken::on("mysql_" . $request->auth->parent_id)->where(array('userId' => $userId))->delete();
            if ($deleted) {
                return $this->successResponse("userToken List deleted Successfully", $token->toArray());
            } else {
                return $this->failResponse("Failed to delete the token ", [
                    "Unkown"
                ]);
            }
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to delete user Token ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


    /**
     * @OA\Post(
     *      path="/get-extension-by-parentid",
     *      summary="get extenson by parentid",
     *      tags={"getextensionByParentId"},
     *      security={{"Bearer":{}}},
     *      @OA\Response(
     *          response="200",
     *          description="extension data"
     *      )
     * )
     */

    function getextensionByParentId(Request $request)
    {
        $extension = $request->auth->extension;
        $clientId       = $request->auth->parent_id;
        try {
            $userArray = User::where('parent_id', $clientId)->where('extension', '!=', $extension)->get();
            return $this->successResponse("userToken List deleted Successfully", $userArray->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to delete user Token ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/check-forgot-password-link/{token}",
     *     summary="Check validity of forgot password link",
     *     description="Validates the reset password token. The token must be created within the last 24 hours and not used.",
     *     tags={"User"},
     *  security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="token",
     *         in="path",
     *         required=true,
     *         description="The forgot password token ID",
     *         @OA\Schema(type="string", example="abc123token")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Token check result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Forgot Password Link is valid"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Token not found or invalid",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Link Not Found"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch the Link"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    function checkForgotPasswordLink(Request $request, $token)
    {
        try {
            $checkForgotPasswordLink = ForgotPasswordLink::where('id', '=', $request->token)->where('status', 1)
                ->where('created_at', '>', Carbon::now()->subHours(24))->first();
            if ($checkForgotPasswordLink) {
                return $this->successResponse("Forgot Password Link is valid", [$checkForgotPasswordLink]);
            } else {
                return $this->failResponse("Forgot Password Link is Invalid", [], null, 200);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Link Not Found", [
                "Invalid Link id $token"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the Link ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\post(
     *     path="/update-timezone",
     *     summary="Update user's timezone",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"timezone"},
     *             @OA\Property(
     *                 property="timezone",
     *                 type="string",
     *                 example="America/New_York",
     *                 description="IANA timezone string"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timezone successfully updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Timezone Update"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 example={
     *                     "id": 1,
     *                     "timezone": "America/New_York"
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found or error updating timezone",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="timezone Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function userUpdateTimezone(Request $request)
    {
        $this->validate($request, ['timezone' => 'string',]);
        try {
            $timezone = User::findOrFail($request->auth->id);
            if ($request->has("timezone"))
                $timezone->timezone = $request->input("timezone");
            $timezone->saveOrFail();

            return $this->successResponse("Timezone Update", $timezone->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("timezone Not Found", ["Invalid timezone id "], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update timezone", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/add-voice-mail-drop",
     *     summary="Add a new Voice Mail Drop",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"ivr_id", "ann_id", "ivr_desc", "language", "voice_name", "speech_text", "prompt_option"},
     *             @OA\Property(property="ivr_id", type="integer", example=101),
     *             @OA\Property(property="ann_id", type="integer", example=202),
     *             @OA\Property(property="ivr_desc", type="string", example="Main Menu Voice Drop"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Joanna"),
     *             @OA\Property(property="speech_text", type="string", example="Hello, thank you for calling."),
     *             @OA\Property(property="prompt_option", type="string", example="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice Mail Drop added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ivr_id", type="integer", example=101),
     *                 @OA\Property(property="ann_id", type="integer", example=202),
     *                 @OA\Property(property="ivr_desc", type="string", example="Main Menu Voice Drop"),
     *                 @OA\Property(property="language", type="string", example="en"),
     *                 @OA\Property(property="voice_name", type="string", example="Joanna"),
     *                 @OA\Property(property="speech_text", type="string", example="Hello, thank you for calling."),
     *                 @OA\Property(property="prompt_option", type="string", example="1"),
     *                 @OA\Property(property="user_id", type="integer", example=10),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-28T10:15:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-28T10:15:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save Voice Mail Drop setting",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to save Voice Mail Drop setting"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function addVoiceMailDrop(Request $request)
    {

        try {
            $input = $request->all();
            $smtp = new VoiceMailDrop();
            $smtp->setConnection("mysql_" . $request->auth->parent_id);
            if (!empty($input["ivr_id"])) $smtp->ivr_id = $input["ivr_id"];
            if (!empty($input["ann_id"])) $smtp->ann_id = $input["ann_id"];
            if (!empty($input["ivr_desc"])) $smtp->ivr_desc = $input["ivr_desc"];
            if (!empty($input["language"])) $smtp->language = $input["language"];
            if (!empty($input["voice_name"])) $smtp->voice_name = $input["voice_name"];
            if (!empty($input["speech_text"])) $smtp->speech_text = $input["speech_text"];
            if (!empty($input["prompt_option"])) $smtp->prompt_option = $input["prompt_option"];
            $smtp->user_id = $request->auth->id;

            $smtp->saveOrFail();
            return $this->successResponse("Added Successfully", $smtp->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save Voice Mail Drop setting", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/add-voice-ai",
     *     summary="Add a new AI-based voice prompt",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"speech_text", "language", "voice_name", "ivr_desc", "prompt_option"},
     *             @OA\Property(property="speech_text", type="string", example="Thank you for calling, how may I help you?"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Amy"),
     *             @OA\Property(property="file_name", type="string", example="welcome_prompt.mp3"),
     *             @OA\Property(property="ivr_desc", type="string", example="Welcome AI Voice"),
     *             @OA\Property(property="prompt_option", type="string", example="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice AI prompt added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=12),
     *                 @OA\Property(property="user_id", type="integer", example=99),
     *                 @OA\Property(property="extension", type="string", example="1001"),
     *                 @OA\Property(property="speech_text", type="string", example="Thank you for calling."),
     *                 @OA\Property(property="language", type="string", example="en"),
     *                 @OA\Property(property="voice_name", type="string", example="Amy"),
     *                 @OA\Property(property="file_name", type="string", example="-"),
     *                 @OA\Property(property="ivr_desc", type="string", example="Welcome AI Voice"),
     *                 @OA\Property(property="prompt_option", type="string", example="1"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-28T10:15:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-28T10:15:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save Voice Mail Drop setting",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to save Voice Mail Drop setting"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function addVoiceAi(Request $request)
    {

        try {
            $input = $request->all();
            $smtp = new VoiceAi();
            $smtp->setConnection("master");
            $smtp->user_id = $request->auth->id;
            $smtp->extension = $request->auth->extension;

            if (!empty($input["speech_text"])) $smtp->speech_text = $input["speech_text"];
            if (!empty($input["language"])) $smtp->language = $input["language"];
            if (!empty($input["voice_name"])) $smtp->voice_name = $input["voice_name"];
            if (!empty($input["file_name"])) $smtp->file_name = '-';
            if (!empty($input["ivr_desc"])) $smtp->ivr_desc = $input["ivr_desc"];
            if (!empty($input["prompt_option"])) $smtp->prompt_option = $input["prompt_option"];

            $smtp->saveOrFail();
            return $this->successResponse("Added Successfully", $smtp->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save Voice Mail Drop setting", [$exception->getMessage()], $exception, 500);
        }
    }
    /**
     * @OA\Get(
     *     path="/view-voicemail",
     *     summary="View all voicemail drops for the authenticated user",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     * *      @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of voicemail drops",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Voice Mail Drop"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="ivr_id", type="integer", example=123),
     *                     @OA\Property(property="ann_id", type="integer", example=456),
     *                     @OA\Property(property="ivr_desc", type="string", example="Welcome voicemail"),
     *                     @OA\Property(property="language", type="string", example="en"),
     *                     @OA\Property(property="voice_name", type="string", example="Joanna"),
     *                     @OA\Property(property="speech_text", type="string", example="Please leave a message."),
     *                     @OA\Property(property="prompt_option", type="string", example="1"),
     *                     @OA\Property(property="user_id", type="integer", example=99),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-28T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-28T10:05:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function viewVoiceMailDrop(Request $request)
    {
        $query = VoiceMailDrop::on("mysql_" . $request->auth->parent_id)->where('user_id', $request->auth->id);

        // Apply search filter
        $search = $request->input('search', '');
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('ivr_desc', 'LIKE', "%{$search}%")
                  ->orWhere('language', 'LIKE', "%{$search}%")
                  ->orWhere('voice_name', 'LIKE', "%{$search}%");
            });
        }

        $VoiceMailDrop = $query->get()->all();
        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($VoiceMailDrop);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $VoiceMailDrop = array_slice($VoiceMailDrop, $start, $limit, false);

            return $this->successResponse("Voice Mail Drop", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $VoiceMailDrop
            ]);
        }
        return $this->successResponse("Voice Mail Drop", $VoiceMailDrop);
    }
    public function viewVoiceMailDrop_old_code(Request $request)
    {
        $VoiceMailDrop = VoiceMailDrop::on("mysql_" . $request->auth->parent_id)->where('user_id', $request->auth->id)->get()->all();
        return $this->successResponse("Voice Mail Drop", $VoiceMailDrop);
    }
    /**
     * @OA\Get(
     *     path="/view-voice-ai",
     *     summary="View all Voice AI records for the authenticated user",
     *     tags={"User"},
     *  security={{"Bearer":{}}},
     *       @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of Voice AI records",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Voice AI Drop"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="user_id", type="integer", example=101),
     *                     @OA\Property(property="extension", type="string", example="1001"),
     *                     @OA\Property(property="speech_text", type="string", example="How can I assist you today?"),
     *                     @OA\Property(property="language", type="string", example="en"),
     *                     @OA\Property(property="voice_name", type="string", example="Amy"),
     *                     @OA\Property(property="file_name", type="string", example="-"),
     *                     @OA\Property(property="ivr_desc", type="string", example="Main AI Voice Prompt"),
     *                     @OA\Property(property="prompt_option", type="string", example="1"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-28T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-28T10:15:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */

    public function viewVoiceAi(Request $request)
    {
        $VoiceAi = VoiceAi::on("master")->where('user_id', $request->auth->id)->get()->all();


        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($VoiceAi);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $VoiceAi = array_slice($VoiceAi, $start, $limit, false);

            return $this->successResponse("Voice AI Drop", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $VoiceAi
            ]);
        }
        return $this->successResponse("Voice AI Drop", $VoiceAi);
    }
    public function viewVoiceAi_old_code(Request $request)
    {
        $VoiceAi = VoiceAi::on("master")->where('user_id', $request->auth->id)->get()->all();
        return $this->successResponse("Voice AI Drop", $VoiceAi);
    }

    /* public function updateVoicemailDrop(User $user)
    {
        $this->validate($this->request, [
            'ann_id' => 'string',
            'ivr_id'   => 'string',
            'ivr_desc'   => 'string',
            
            'id'        => 'required|numeric'
        ]);
        $response = $user->updateVoicemailDrop($this->request->input('auto_id'),$this->request->input('voicemail_id'),$this->request->input('parentId'));
        return response()->json($response);
    }*/


    public function updateVoicemailDrop(Request $request)
    {

        try {
            $input = $request->all();
            $VoiceMailDrop = VoiceMailDrop::on('mysql_' . $request->auth->parent_id)->findOrFail($input["auto_id"]);
            if (!empty($input["ivr_id"])) $VoiceMailDrop->ivr_id = $input["ivr_id"];
            if (!empty($input["ann_id"])) $VoiceMailDrop->ann_id = $input["ann_id"];
            if (!empty($input["ivr_desc"])) $VoiceMailDrop->ivr_desc = $input["ivr_desc"];
            if (!empty($input["language"])) $VoiceMailDrop->language = $input["language"];
            if (!empty($input["voice_name"])) $VoiceMailDrop->voice_name = $input["voice_name"];
            if (!empty($input["speech_text"])) $VoiceMailDrop->speech_text = $input["speech_text"];
            $VoiceMailDrop->prompt_option = $input["prompt_option"];


            $VoiceMailDrop->user_id = $request->auth->id;

            //  return $VoiceMailDrop;

            $VoiceMailDrop->saveOrFail();
            return $this->successResponse("Updated Successfully", $VoiceMailDrop->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save Voice Mail Drop setting", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/forgot-password",
     *     summary="Send a password reset link to user's email",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password reset email sent",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Password reset link has been sent to your mail. Please check your inbox for further instructions.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    public function forgotPassword(Request $request)
    {
        $email = $request->input('email');
        $user  = User::where('email', $email)->first();

        // Null check BEFORE accessing user properties
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        Log::info('email get', ['user' => $user]);

        $base_parent_id = $user->base_parent_id;
        $client  = Client::where('id', $base_parent_id)->first();
        $client_id = $client ? $client->id : $base_parent_id;
        Log::info('client get', ['client' => $client]);

        //Log::debug('user',['user'=>$user]);
        $firstName = $user->first_name;
        $lastName = $user->last_name;


        $token = Str::random(60);
        PasswordReset::create([
            'email' => $email,
            'token' => $token,
        ]);
        // Log::debug('token',['token'=>$token]);

        // Resolve the frontend base URL from the request origin (supports multiple domains)
        $origin = $request->header('Origin')
            ?: $request->header('Referer')
            ?: env('PORTAL_NAME', '');
        $frontendBase = rtrim(parse_url($origin, PHP_URL_SCHEME) . '://' . parse_url($origin, PHP_URL_HOST), '/');
        if (empty(parse_url($origin, PHP_URL_HOST))) {
            $frontendBase = rtrim(env('PORTAL_NAME', ''), '/');
        }

        // Send reset password email with the token
        $this->sendResetEmail($email, $token, $firstName, $lastName, $client_id, $frontendBase);

        return response()->json(['message' => 'Password reset link has been sent to your mail.Please check your inbox for further instructions.']);
    }


    protected function sendResetEmailold($email, $token, $firstName, $lastName)
    {
        $expiresAt = Carbon::now()->addMinutes(30); // Expiration time set to 30 minutes from now

        $resetLink = env('PORTAL_NAME') . '/verify-token/' . $token . '?expires=' . $expiresAt->timestamp;
        // $resetLink= ' http://127.0.0.1:8090/verify-token/' . $token . '?expires=' . $expiresAt->timestamp;
        $data = [
            'resetLink' => $resetLink,
            'subject' => 'Reset Your Password',
            'firstName' => $firstName,
            'lastName' => $lastName,

        ];
        Mail::send('emails.forgot-password', $data, function ($message) use ($email) {
            $message->to($email)->subject('Reset Your Password');
        });
    }
    protected function sendResetEmail($email, $token, $firstName, $lastName, $clientId, $frontendBase = '')
    {
        $base = $frontendBase ?: rtrim(env('PORTAL_NAME', ''), '/');
        $resetLink = $base . '/reset-password?token=' . $token . '&email=' . urlencode($email);

        SystemMailerService::send('forgot-password', $email, [
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'resetLink' => $resetLink,
        ]);
    }
    // protected function sendResetEmail($email, $token, $firstName, $lastName,$client_id)
    // {

    //     // Use SMTP settings for sending email
    //     $smtpSetting = new SmtpSetting;
    //     $smtpSetting->mail_driver = "SMTP";
    //     $smtpSetting->mail_host = env("PORTAL_MAIL_HOST");
    //     $smtpSetting->mail_port = env("PORTAL_MAIL_PORT");
    //     $smtpSetting->mail_username = env("PORTAL_MAIL_USERNAME");
    //     $smtpSetting->mail_password = env("PORTAL_MAIL_PASSWORD");
    //     $smtpSetting->from_name = env("PORTAL_MAIL_SENDER_NAME");
    //     $smtpSetting->from_email = env("PORTAL_MAIL_SENDER_EMAIL");
    //     $smtpSetting->mail_encryption = env("PORTAL_MAIL_ENCRYPTION");

    //     $from = [
    //         "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
    //         "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
    //     ];
    //     $expiresAt = Carbon::now()->addMinutes(30);

    //     $resetLink = 'http://127.0.0.1:8090/verify-token/' . $token . '?expires=' . $expiresAt->timestamp;

    //     $data = [
    //         'resetLink' => $resetLink,
    //         'subject' => 'Reset Your Password',
    //         'firstName' => $firstName,
    //         'lastName' => $lastName,
    //     ];

    //     $mailable = new SystemNotificationMail($from,'emails.forgot-password',$data['subject'], $data);

    //     // Mailable instance
    //     Log::info('reached',['mailable'=>$mailable]);

    //     // MailService instance
    //     $mailService = new MailService($client_id,$mailable, $smtpSetting);
    //     // Log::info('reached',['mailService'=>$mailService]);

    //     // Send email
    //     $emails = $mailService->sendEmail($email);

    //     // Log::debug("SendOtpEmailVerification.sendEmailOtp.responseEmail", [$emails]);

    //     Log::info("email otp", [
    //         "result" => $emails,
    //     ]);
    // }


    /**
     * @OA\Get(
     *     path="/verify-token/{token}",
     *     summary="Verify password reset token",
     *     description="Checks if the provided password reset token is valid.",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="token",
     *         in="path",
     *         required=true,
     *         description="Password reset token",
     *         @OA\Schema(type="string", example="abcdef123456")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Reset token is valid",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Reset token verified")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Reset token is invalid",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid reset token")
     *         )
     *     )
     * )
     */


    public function verifyResetToken(Request $request, $token)
    {
        $passwordReset = PasswordReset::where('token', $token)->first();

        if (!$passwordReset) {
            return response()->json(['message' => 'Invalid reset token'], 404);
        }

        // Enforce 30-minute expiry on the token
        $createdAt  = Carbon::parse($passwordReset->created_at);
        $expiresAt  = $createdAt->addMinutes(30);
        if (Carbon::now()->gt($expiresAt)) {
            return response()->json(['message' => 'Reset link has expired. Please request a new one.'], 410);
        }

        return response()->json(['message' => 'Reset token verified', 'email' => $passwordReset->email]);
    }

    /**
     * @OA\Get(
     *     path="/verify-token-email/{token}/{expire}",
     *     summary="Verify email using token and expiration",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="token",
     *         in="path",
     *         required=true,
     *         description="Verification token",
     *         @OA\Schema(type="string", example="12345")
     *     ),
     *     @OA\Parameter(
     *         name="expire",
     *         in="path",
     *         required=true,
     *         description="Unix timestamp representing expiry time",
     *         @OA\Schema(type="integer", example=1730000000)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Verification successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Verification successful.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid or expired link"
     *     ),
     *     @OA\Response(
     *         response=410,
     *         description="Link has expired"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function verifyEmailToken(Request $request, $token, $expire)
    {
        $expires = $expire;
        $expiresAt = Carbon::createFromTimestamp($expires);

        if (!$expiresAt->isFuture()) {
            return response()->json(['message' => 'link has been expired.Please try again', 'success' => 'false'], 404);
        }

        $passwordReset = WebEmailVerification::where('id', $token)->where('status', 1)->first();



        if (!$passwordReset) {
            return response()->json(['message' => 'link has been expired or invalid url. Please try again', 'success' => 'false'], 404);
        }

        $passwordReset->status = 4;
        $passwordReset->saveOrFail();

        //return $passwordReset;


        $webleads = new WebLeads();
        $webleads['first_name'] = $passwordReset->first_name;
        $webleads['last_name'] = $passwordReset->last_name;
        $mobileData = WebPhoneVerification::findOrFail($passwordReset->mobile_uuid);


        $webleads['country_code'] = $mobileData->country_code;
        $webleads['mobile'] = $mobileData->phone_number;
        $webleads['email'] = $passwordReset->email;
        $webleads['mobile_otp'] = $passwordReset->mobile_uuid;
        $webleads['email_otp'] = $passwordReset->id;

        // return $webleads;

        $webleads->saveOrFail();





        // Token is valid and matches the user's email
        return response()->json(['message' => 'Reset token verified', 'success' => 'true']);
    }

    /**
     * @OA\Post(
     *     path="/resetPasswordUser",
     *     summary="Reset user password using a valid token",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token", "password", "password_confirmation"},
     *             @OA\Property(property="token", type="string", example="randomlyGeneratedToken123"),
     *             @OA\Property(property="password", type="string", example="NewStrongPassword1!"),
     *             @OA\Property(property="password_confirmation", type="string", example="NewStrongPassword1!")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Password updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid or expired token"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function resetPasswordUser(Request $request)
    {
        $token = $request->input('token');
        $emailInput = $request->input('email');
        $newPassword = $request->input('password');
        $confirmation = $request->input('password_confirmation');

        if (!$token || !$newPassword) {
            return response()->json(['message' => 'Token and password are required.'], 422);
        }

        if ($newPassword !== $confirmation) {
            return response()->json(['message' => 'Passwords do not match.'], 422);
        }

        if (strlen($newPassword) < 8) {
            return response()->json(['message' => 'Password must be at least 8 characters.'], 422);
        }

        $passwordReset = PasswordReset::where('token', $token)->first();

        if (!$passwordReset) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 404);
        }

        // 30-minute expiry check
        if (Carbon::parse($passwordReset->created_at)->addMinutes(30)->isPast()) {
            $passwordReset->delete();
            return response()->json(['message' => 'Reset link has expired. Please request a new one.'], 422);
        }

        $email = $passwordReset->email;

        // Verify email matches the token record (when provided)
        if ($emailInput && strtolower($emailInput) !== strtolower($email)) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 404);
        }

        $user = User::where('email', $email)->first();
        $user->password = Hash::make($newPassword);
        $user->save();
        // Update the password in the user_extensions table
        $extension = $user->extension;
        $altExtension = $user->alt_extension;

        DB::connection('master')->table('user_extensions')
            ->where('name', $extension)
            ->update(['secret' => $newPassword]);

        DB::connection('master')->table('user_extensions')
            ->where('name', $altExtension)
            ->update(['secret' => $newPassword]);


        // Password updated successfully
        return response()->json(['message' => 'Password updated successfully']);
    }


    /**
     * @OA\Post(
     *     path="/forgot-password-mobile",
     *     summary="Send OTP to user's mobile for password reset",
     *     description="Sends a One-Time Password (OTP) to the user's mobile number if the mobile is registered.",
     *     tags={"User"},
     *  security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mobile", "country_code"},
     *             @OA\Property(
     *                 property="mobile",
     *                 type="string",
     *                 example="9876543210",
     *                 description="The user's mobile number"
     *             ),
     *             @OA\Property(
     *                 property="country_code",
     *                 type="string",
     *                 example="+91",
     *                 description="Country code of the mobile number"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="OTP sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="OTP has been sent to your mobile number."),
     *             @OA\Property(property="otp_id", type="string", example="550e8400-e29b-41d4-a716-446655440000"),
     *             @OA\Property(property="mobile", type="string", example="9876543210")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     )
     * )
     */

    public function forgotPasswordMobile(Request $request)
    {
        $mobile = $request->input('mobile');
        $user = User::where('mobile', $mobile)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $country_code = $request->input('country_code');
        $user_id = $user->id;
        $base_parent_id = $user->base_parent_id;
        $client = Client::where('id', $base_parent_id)->first();

        $otp_value = mt_rand(100000, 999999);
        $otp_id = Str::uuid()->toString();
        OtpVerification::create([
            'id' => $otp_id,
            'user_id' => $user_id,
            'country_code' => $country_code,
            'phone_number' => $mobile,
            'code' => $otp_value,
            'expiry' => (new \DateTime())->modify("+15 minutes"),
        ]);

        // $otp_id=$otp->id;

        $to = $country_code . $mobile;

        $data_array = array();
        $data_array['to'] = $to;
        $data_array['text'] = "Your Verification OTP for " . env('SITE_NAME') . " is " . $otp_value;
        $json_data_to_send = json_encode($data_array);


        if ($client->sms_plateform == 'plivo') {
            $data_array['from'] = env('PLIVO_SMS_NUMBER');
            $plivo_user = env('PLIVO_USER');
            $plivo_pass = env('PLIVO_PASS');

            $client = new RestClient($plivo_user, $plivo_pass);
            $result = $client->messages->create([
                "src" => $data_array['from'],
                "dst" => $data_array['to'],
                "text"  => $data_array['text'],
                "url" => ""
            ]);
        } else
        if ($client->sms_plateform == 'didforsale') {
            $data_array['from'] = env('SMS_NUMBER');
            $api = config('sms.sms_api.value');
            $access = config('sms.sms_access.value');
            $sms_url = config('sms.sms_access_url.value');

            $json_data_to_send = json_encode($data_array);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $sms_url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data_to_send);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode("$api:$access")));

            $result = curl_exec($ch);
            $res = json_decode($result);
        }

        // Send reset password email with the token
        $response = [
            'message' => 'OTP has been sent to your mobile number.',
            'otp_id' => $otp_id,
            'mobile' => $mobile
        ];

        // Return the JSON response
        return response()->json($response);
    }



    /**
     * @OA\Post(
     *     path="/verify-token-mobile/{otp_id}",
     *     summary="Verify OTP for password reset via mobile",
     *     description="Verifies the OTP provided by the user for password reset using mobile.",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="otp_id",
     *         in="path",
     *         required=true,
     *         description="The OTP verification ID",
     *         @OA\Schema(type="string", example="550e8400-e29b-41d4-a716-446655440000")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"otp"},
     *             @OA\Property(
     *                 property="otp",
     *                 type="string",
     *                 example="123456",
     *                 description="The OTP code received by the user"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="OTP verified successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Reset token verified")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid OTP",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid Otp")
     *         )
     *     )
     * )
     */


    public function verifyResetTokenMobile(Request $request, $otp_id)
    {
        $otp = $request->input('otp');

        $OtpVerification = OtpVerification::where('code', $otp)->first();

        if (!$OtpVerification) {
            return response()->json(['message' => 'Invalid  Otp'], 404);
        }

        // Token is valid and matches the user's email
        return response()->json(['message' => 'Reset token verified']);
    }
    /**
     * @OA\Post(
     *     path="/resetPasswordUserMobile",
     *     summary="Reset user password via mobile OTP",
     *     description="Resets the user's password using a valid OTP token sent to their mobile number.",
     *     tags={"User"},
     * security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token", "password"},
     *             @OA\Property(
     *                 property="token",
     *                 type="string",
     *                 example="550e8400-e29b-41d4-a716-446655440000",
     *                 description="OTP verification token ID"
     *             ),
     *             @OA\Property(
     *                 property="password",
     *                 type="string",
     *                 format="password",
     *                 example="NewStrongPassword123!",
     *                 description="New password for the user"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password reset successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Password updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid or expired OTP token",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid token or user not found")
     *         )
     *     )
     * )
     */

    public function resetPasswordUserMobile(Request $request)
    {
        $token = $request->input('token');
        $OtpVerification = OtpVerification::where('id', $token)
            ->first();
        $phone_number =  $OtpVerification->phone_number;
        $newPassword = $request->input('password');

        $user = User::where('mobile', $phone_number)->first();
        $user->password = Hash::make($newPassword);
        $user->save();
        // Update the password in the user_extensions table
        $extension = $user->extension;
        $altExtension = $user->alt_extension;

        DB::connection('master')->table('user_extensions')
            ->where('name', $extension)
            ->update(['secret' => $newPassword]);

        DB::connection('master')->table('user_extensions')
            ->where('name', $altExtension)
            ->update(['secret' => $newPassword]);


        // Password updated successfully
        return response()->json(['message' => 'Password updated successfully']);
    }


    /**
     * @OA\Post(
     *     path="/change-dialer-mode-extension",
     *     summary="Change the dialer mode for the authenticated user",
     *     tags={"User"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"dialer_mode"},
     *             @OA\Property(
     *                 property="dialer_mode",
     *                 type="string",
     *                 example="predictive",
     *                 description="New dialer mode (e.g., predictive, progressive, manual)"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dialer mode updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Dialer Mode updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No query results for model [App\\Models\\User] ...")
     *         )
     *     )
     * )
     */

    public function changeDialerModeExtension(Request $request)
    {
        $clientId       = $request->auth->parent_id;
        $userId         = $request->auth->id;

        $user = User::findOrFail($userId);
        $user->dialer_mode = $request->dialer_mode;
        $user->saveOrFail();

        return $this->successResponse('Dialer Mode updated successfully', []);


        return response()->json(['message' => 'Dialer Mode updated successfully']);
    }
    public function userPermission(int $userId)
    {
        try {
            /** @var User $user */
            return Permission::where('user_id', '=', $userId)->get()->all();

            return response()->json($permissions);
        } catch (ModelNotFoundException $modelNotFoundException) {
            throw new NotFoundHttpException("Resource with userId $userId not found");
        }
    }

    /**
     * Upload / replace profile picture for the authenticated user.
     * POST /profile/upload-avatar
     */
    public function uploadAvatar()
    {
        $this->validate($this->request, [
            'avatar' => 'required|file|image|mimes:jpeg,jpg,png,gif,webp|max:4096',
        ]);

        $userId = $this->request->auth->id;

        try {
            $file     = $this->request->file('avatar');
            $ext      = $file->getClientOriginalExtension();
            $path     = "avatars/user_{$userId}_" . time() . '.' . $ext;

            Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
            $url = Storage::disk('public')->url($path);

            DB::connection('master')->table('users')
                ->where('id', $userId)
                ->update(['profile_pic' => $url]);

            return response()->json([
                'success' => true,
                'message' => 'Profile picture updated.',
                'data'    => ['profile_pic' => $url],
            ]);
        } catch (\Exception $e) {
            Log::error('uploadAvatar error', ['msg' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Upload failed.'], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Model\Role;

use App\Model\Extension;
use App\Model\Client\ExtensionLive;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Model\User;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class ExtensionController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */

    private $request;

    public function __construct(Request $request, Extension $extension)
    {
        $this->request = $request;
        $this->model = $extension;
    }

    /**
     * @OA\Get(
     *      path="/extension",
     *      summary="List extensions",
     *      tags={"Extensions"},
     *      security={{"Bearer":{}}},
     *      @OA\Parameter(
     *          name="start",
     *          in="query",
     *          description="Start index for pagination",
     *          required=false,
     *          @OA\Schema(type="integer", default=0)
     *      ),
     *      @OA\Parameter(
     *          name="limit",
     *          in="query",
     *          description="Limit number of records returned",
     *          required=false,
     *          @OA\Schema(type="integer", default=10)
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="extension data"
     *      )
     * )
     */

    public function list(Request $request)
    {
        $response = $this->model->extensionDetail($this->request);
        foreach ($response["data"] as $key => $extension)
            unset($response["data"][$key]->password);
        return response()->json($response);
    }


    public function show(Request $request, int $id)
    {
        try {
            $response = $this->model->extensionDetail($this->request, $id);
            unset($response["data"]->password);
            return response()->json($response);
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Extension with id $id not found", [], $exception, 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/extension",
     *     summary="Retrieve extension details by extension_id",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="extension_id",
     *         in="query",
     *         description="Numeric ID of the extension",
     *         required=false,
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Extension details retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Extension details retrieved successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function getExtension()
    {
        $this->validate($this->request, [
            'extension_id' => 'numeric'
        ]);
        $response = $this->model->extensionDetail($this->request, $this->request->extension_id);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/extension-list",
     *     summary="Retrieve extension-list by extension_id",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             type="object",
     *    * @OA\Property(
     * property="extension_id",
     * type="integer",
     * example=1,
     * description="ID of the extension to fetch (optional, for single detail)"
     * ),
     *             @OA\Property(
     *                 property="start",
     *                 type="integer",
     *                 default=0,
     *                 description="Start index for pagination"
     *             ),
     *             @OA\Property(
     *                 property="limit",
     *                 type="integer",
     *                 default=10,
     *                 description="Limit number of records returned"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="extension-list details retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Extension details retrieved successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function getExtensionList()
    {
        $this->validate($this->request, [
            'extension_id' => 'numeric'
        ]);
        $response = $this->model->extensionDetailList($this->request, $this->request->extension_id);
        return response()->json($response);
    }



    public function getExtensionListCRM(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $users_all = User::join('roles', 'users.role', '=', 'roles.id')->where('users.parent_id', $clientId)->orderBy('users.id', 'DESC')
            ->get(['users.*', 'roles.name as role_name', 'roles.level'])->all();
        //$users = User::join('roles', 'users.role', '=', 'roles.id')->where('users.parent_id',$clientId)->where('users.is_deleted','0')->orderBy('users.id','DESC')
        //->get(['users.*', 'roles.name as role_name','roles.level'])->all();

        $users_admin = User::join('roles', 'users.role', '=', 'roles.id')->where('users.user_level', '9')->orWhere('users.user_level', '11')->orderBy('users.id', 'DESC')
            ->get(['users.*', 'roles.name as role_name', 'roles.level'])->all();

        $users = array_merge($users_all, $users_admin);
        return $this->successResponse("Users List", $users);
    }
    /**
     * @OA\Get(
     *     path="/users-list-new",
     *     summary="Retrieve Users List",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Users List details retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Extension details retrieved successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function getExtensionListCRMNew(Request $request)
    {
        $clientId = $request->auth->parent_id;

        // Get users with roles
        $users_all = User::join('roles', 'users.role', '=', 'roles.id')
            ->where('users.parent_id', $clientId)
            ->orderBy('users.id', 'DESC')
            ->get(['users.*', 'roles.name as role_name', 'roles.level']);

        // Get admin-level users
        $users_admin = User::join('roles', 'users.role', '=', 'roles.id')
            ->where(function ($query) {
                $query->where('users.user_level', '9')
                    ->orWhere('users.user_level', '11');
            })
            ->orderBy('users.id', 'DESC')
            ->get(['users.*', 'roles.name as role_name', 'roles.level']);

        // Combine both groups
        $all_users = $users_all->merge($users_admin);

        // Append 'secret' from user_extensions where extension = username
        $users_with_secret = $all_users->map(function ($user) {
            $userExtension = \DB::table('user_extensions')
                ->where('username', $user->extension)
                ->first();

            $user->secret = $userExtension ? $userExtension->secret : null;
            return $user;
        });

        return $this->successResponse("Users List", $users_with_secret->toArray());
    }

    /*
     * Add extension
     * @return json
     */



    public function addExtension()
    {
        $this->validate($this->request, [
            'first_name' => 'required|string|max:255',
            'last_name' => 'string|max:255',
            'email' => 'required|email',
            'phone_number' => 'numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'password' => 'required|string|max:255',
            'follow_me' => 'numeric',
            'call_forward' => 'numeric',
            'voicemail' => 'numeric',
            'vm_pin' => 'numeric',
            'voicemail_send_to_email' => 'numeric',
            'group_id' => 'required|array',
            'id' => 'required|numeric'
        ]);
        $response = $this->model->addExtension($this->request);
        return response()->json($response);
    }

    /*
     * Edit Extension
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/edit-extension",
     *     summary="Delete extension",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"extension_id"},
     *             @OA\Property(property="extension_id", type="integer", example=101),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john.doe@example.com"),
     *             @OA\Property(property="mobile", type="string", example="9876543210"),
     *             @OA\Property(property="password", type="string", example="securePass123"),
     *             @OA\Property(property="follow_me", type="integer", example=1),
     *             @OA\Property(property="call_forward", type="integer", example=1),
     *             @OA\Property(property="voicemail", type="integer", example=0),
     *             @OA\Property(property="vm_pin", type="integer", example=1234),
     *             @OA\Property(property="voicemail_send_to_email", type="integer", example=1),
     *             @OA\Property(property="is_deleted", type="integer", example=0),
     *             @OA\Property(
     *                 property="group_id",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={1, 2, 3}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Extension updated response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Extension updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or missing parameters"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid token"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function editExtension()
    {
        $this->validate($this->request, [
            'first_name' => 'string|max:255',
            'last_name' => 'string|max:255',
            'email' => 'email',
            'mobile' => 'numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'password' => 'string|max:255',
            'follow_me' => 'numeric',
            'call_forward' => 'numeric',
            'voicemail' => 'numeric',
            'vm_pin' => 'numeric',
            'is_deleted' => 'numeric',
            'voicemail_send_to_email' => 'numeric',
            'group_id' => 'array',
            'extension_id' => 'required|numeric'
        ]);
        $response = $this->model->editExtension($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/edit-extension-save",
     *     summary="Update a Extension",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Data to update the extension",
     *         @OA\JsonContent(
     *             required={"extension_id", "first_name", "last_name"},
     *             @OA\Property(property="extension_id", type="integer", example=123),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="mobile", type="string", example="9876543210"),
     *             @OA\Property(property="country_code", type="string", example="+91"),
     *             @OA\Property(property="follow_me", type="boolean", example=1),
     *             @OA\Property(property="call_forward", type="boolean", example=0),
     *             @OA\Property(property="voicemail", type="boolean", example=1),
     *             @OA\Property(property="vm_pin", type="string", example="1234"),
     *             @OA\Property(property="voicemail_send_to_email", type="boolean", example=1),
     *             @OA\Property(property="twinning", type="boolean", example=0),
     *             @OA\Property(property="cli_setting", type="string", example="default"),
     *             @OA\Property(property="cli", type="string", example="1001"),
     *             @OA\Property(property="cnam", type="string", example="John D."),
     *             @OA\Property(property="extension_type", type="string", example="user"),
     *             @OA\Property(property="sms_setting_id", type="integer", example=1),
     *             @OA\Property(property="receive_sms_on_email", type="boolean", example=1),
     *             @OA\Property(property="receive_sms_on_mobile", type="boolean", example=1),
     *             @OA\Property(property="ip_filtering", type="boolean", example=1),
     *             @OA\Property(property="enable_2fa", type="boolean", example=1),
     *             @OA\Property(property="voip_configuration_id", type="integer", example=10),
     *             @OA\Property(property="app_status", type="boolean", example=true),
     *             @OA\Property(property="timezone", type="string", example="Asia/Kolkata"),
     *             @OA\Property(
     *                 property="group_id",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={1, 2, 3}
     *             ),
     *             @OA\Property(property="password", type="string", example="securepassword123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Extension updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Extension updated successfully"),
     *             @OA\Property(property="data", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing required mobile number when forwarding/twinning enabled",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Enter mobile number")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="No changes detected or invalid input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Nothing to update.")
     *         )
     *     )
     * )
     */


    public function editExtensionSave()
    {
        $response = $this->model->editExtensionSave($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/check-extension",
     *     summary="Check if extension is already taken",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload to check if extension exists",
     *         @OA\JsonContent(
     *             required={"extension"},
     *             @OA\Property(
     *                 property="extension",
     *                 type="integer",
     *                 description="Extension number to check",
     *                 example=1001
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Extension availability response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Extension is Available.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=409,
     *         description="Extension already exists",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Extension Already Exists.")
     *         )
     *     )
     * )
     */

    public function checkExtension()
    {
        $this->validate($this->request, [
            'extension' => 'numeric',
            //'id'          => 'required|numeric'
        ]);
        $response = $this->model->checkExtension($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/check-alt-extension",
     *     summary="Check 'alt_extension is already taken",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload to check if alt_extension exists",
     *         @OA\JsonContent(
     *             required={"alt_extension"},
     *             @OA\Property(
     *                 property="alt_extension",
     *                 type="integer",
     *                 description="Alt Extensionnumber to check",
     *                 example=1001
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Alt_extension availability response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Alt_extension is Available.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=409,
     *         description="Alt_extension already exists",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Extension Already Exists.")
     *         )
     *     )
     * )
     */
    public function checkAltExtension()
    {
        $this->validate($this->request, [
            'alt_extension' => 'numeric',
        ]);
        $response = $this->model->checkAltExtension($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/update-email",
     *     summary="Update user's email",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Email update payload",
     *         @OA\JsonContent(
     *             required={"email", "user_id"},
     *             @OA\Property(
     *                 property="email",
     *                 type="string",
     *                 format="email",
     *                 description="New email to be updated",
     *                 example="john.doe@example.com"
     *             ),
     *             @OA\Property(
     *                 property="user_id",
     *                 type="integer",
     *                 description="User ID whose email will be updated",
     *                 example=42
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Email updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Email Change Successfully.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=409,
     *         description="Email already exists",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Email Already Exists.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Something went wrong",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Something Went wrong.")
     *         )
     *     )
     * )
     */

    public function updateEmail()
    {
        $this->validate($this->request, [
            'email' => 'required|email',
        ]);
        $response = $this->model->updateEmail($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/check-email",
     *     summary="Check if email exists or not",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload containing the email to check",
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(
     *                 property="email",
     *                 type="string",
     *                 format="email",
     *                 description="Email address to check",
     *                 example="example@gmail.com"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Email check result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Email is Available")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=409,
     *         description="Email already exists",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Email Already Exists.")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Unauthenticated")
     *         )
     *     )
     * )
     */


    public function checkEmail()
    {
        $this->validate($this->request, [
            'email' => 'required|email',
        ]);
        $response = $this->model->checkEmail($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/client_ip_list",
     *     summary="Get client IP list",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of client IPs",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Client IP list fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="ip_address", type="string", example="192.168.1.1"),
     *                     @OA\Property(property="client_name", type="string", example="Client A"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-15T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Unauthenticated")
     *         )
     *     )
     * )
     */

    function clientIpList()
    {
        $response = $this->model->clientIpList($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Put(
     *     path="/user",
     *     summary="Create a new user extension",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"extension", "first_name", "last_name", "email"},
     *             @OA\Property(property="extension", type="string", example="1010"),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="mobile", type="string", example="+1234567890"),
     *             @OA\Property(property="country_code", type="string", example="+91"),
     *             @OA\Property(property="follow_me", type="string", example="0"),
     *             @OA\Property(property="call_forward", type="string", example="0"),
     *             @OA\Property(property="voicemail", type="boolean", example=true),
     *             @OA\Property(property="vm_pin", type="string", example="1234"),
     *             @OA\Property(property="voicemail_send_to_email", type="boolean", example=true),
     *             @OA\Property(property="twinning", type="boolean", example="0"),
     *             @OA\Property(property="asterisk_server_id", type="integer", example=1),
     *             @OA\Property(property="timezone", type="string", example="Asia/Kolkata"),
     *             @OA\Property(property="cli_setting", type="string", example="0"),
     *             @OA\Property(property="cli", type="string", example="CLI123"),
     *             @OA\Property(property="cnam", type="string", example="John Doe"),
     *             @OA\Property(property="password", type="string", example="securepass"),
     *             @OA\Property(property="extension_type", type="string", example="SIP"),
     *             @OA\Property(property="sms_setting_id", type="integer", example=3),
     *             @OA\Property(property="receive_sms_on_email", type="boolean", example=true),
     *             @OA\Property(property="receive_sms_on_mobile", type="boolean", example=false),
     *             @OA\Property(property="ip_filtering", type="boolean", example=true),
     *             @OA\Property(property="enable_2fa", type="boolean", example=true),
     *             @OA\Property(property="voip_configuration_id", type="integer", example=2),
     *             @OA\Property(property="app_status", type="string", example="active"),
     *             @OA\Property(property="package_id", type="integer", example=5),
     *             @OA\Property(
     *                 property="group_id",
     *                 type="array",
     *                 @OA\Items(type="integer", example=1)
     *             )
     *         )
     *     ),
     * @OA\Response(
     *         response=200,
     *         description="Extension created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Extension created successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    function saveNewExtension()
    {
// 🔑 Step 0: Check for X-application-Token
$hasAppToken = $this->request->hasHeader('X-application-Token');

if ($hasAppToken) {

    // 🔐 Step 1: Validate Tokens
    $tokenResponse = $this->validateRequestTokens($this->request);
    if ($tokenResponse !== null) {
        return $tokenResponse;
    }

    // 📋 Step 2: Validate Body
    $validationResponse = $this->validateSubUserRequest($this->request);
    if ($validationResponse !== null) {
        return $validationResponse;
    }
    if ($validationResponse instanceof \Illuminate\Http\JsonResponse) {
        return $validationResponse; // stop execution
    }

    // 👤 Step 3: Merge subuser info
    $this->request->merge([
        'user_type' => 'subuser',
        'easify_user_uuid' => (string) Str::uuid(),
        'owner_id' => $this->request->input('main_user_id')
    ]);
       // DEBUG LOG
       Log::info('Merged subuser fields:', [
        'user_type' => $this->request->input('user_type'),
        'easify_user_uuid' => $this->request->input('easify_user_uuid'),
        'owner_id' => $this->request->input('owner_id'),
        'main_user_id' => $this->request->input('main_user_id'),
    ]);
}
        $call_forward = $this->request->call_forward;
        $twinning = $this->request->twinning;
        $follow_me = $this->request->follow_me;
        $country_code = $this->request->country_code;

        $rules = [
            'first_name' => 'required|string|max:255',
            'last_name' => 'string|min:1|max:255',
            'email' => 'required|email|unique:master.users',
            'password' => 'required|string|min:4',
            'profile_pic' => ["sometimes", "required", "string", "min:1", "regex:/^.+\.(jpg|png)$/"],
            'extension' => 'required|int|min:1000|max:9999',
            'rpm' => 'sometimes|required|string|min:1|max:100',
            'vm_pin' => 'sometimes|required|int',
            'voicemail' => 'sometimes|required|int',
            'voicemail_greeting' => 'sometimes|required|string|min:1|max:255',
            'asterisk_server_id' => 'required|int',
            'voicemail_send_to_email' => 'sometimes|required|int',
            'follow_me' => 'sometimes|required|int',
            'call_forward' => 'sometimes|required|int',
            'dialpad' => 'sometimes|required|string|min:1|max:100',
            'agent_voice_id' => 'sometimes|required|string|min:1|max:255',
            'cli_setting' => 'sometimes|required|int',
            'cli' => 'required_if:cli_setting,1|min:1|string|max:14',
            'local_ip' => 'sometimes|required|ip',
            'public_ip' => 'sometimes|required|ip',
            'phone_status' => 'sometimes|required|string|min:1|max:255',
            'status' => 'sometimes|required|int',
            'is_deleted' => 'sometimes|required|int',
            'allowed_ip' => 'sometimes|required|ip',
            'twinning' => 'sometimes|required|string|min:1|max:3',
            'directory_name' => 'sometimes|required|string|min:1|max:50',
            'extension_type' => 'sometimes|required|string|min:1|max:3',
            'vm_drop' => 'sometimes|required|int',
            'vm_drop_location' => 'required_if:vm_drop,1|min:1|string|max:255',
            'timezone' => 'required',
            'mobile' => Rule::requiredIf(function () use ($call_forward, $twinning, $follow_me) {
                return ($call_forward == 1 || $twinning == 1 || $follow_me == 1);
            }),
        ];

        $messages = [
            'email.required' => 'The email field is mandatory.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email is already registered. Please use another.',
            'extension.required' => "Extention already assigned"
        ];

        try {
            Validator::make($this->request->all(), $rules, $messages)->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        }

        Log::info('Request data:', $this->request->all());
        $response = $this->model->newExtensionSave($this->request);

        return response()->json([
            'success' => true,
            'data' => $response
        ]);
    }
    function saveNewExtension_old()
    {
        $call_forward = $this->request->call_forward;
        $twinning = $this->request->twinning;
        $follow_me = $this->request->follow_me;
        $country_code = $this->request->country_code;
        $this->validate($this->request, [
            'first_name' => 'required|string|max:255',
            'last_name' => 'string|min:1|max:255',
            'email' => 'required|email|unique:master.users',
            'password' => 'required|string|min:4',
            'profile_pic' => ["sometimes", "required", "string", "min:1", "regex:/^.+\.(jpg|png)$/"],
            'extension' => 'required|int|min:1000|max:9999',
            'rpm' => 'sometimes|required|string|min:1|max:100',
            'vm_pin' => 'sometimes|required|int',
            'voicemail' => 'sometimes|required|int',
            'voicemail_greeting' => 'sometimes|required|string|min:1|max:255',
            'asterisk_server_id' => 'required|int',
            'voicemail_send_to_email' => 'sometimes|required|int',
            'follow_me' => 'sometimes|required|int',
            'call_forward' => 'sometimes|required|int',
            'dialpad' => 'sometimes|required|string|min:1|max:100',
            'agent_voice_id' => 'sometimes|required|string|min:1|max:255',
            'cli_setting' => 'sometimes|required|int',
            'cli' => 'required_if:cli_setting,1|min:1|string|max:14',
            'local_ip' => 'sometimes|required|ip',
            'public_ip' => 'sometimes|required|ip',
            'phone_status' => 'sometimes|required|string|min:1|max:255',
            'status' => 'sometimes|required|int',
            'is_deleted' => 'sometimes|required|int',
            'allowed_ip' => 'sometimes|required|ip',
            'twinning' => 'sometimes|required|string|min:1|max:3',
            'directory_name' => 'sometimes|required|string|min:1|max:50',
            'extension_type' => 'sometimes|required|string|min:1|max:3',
            'vm_drop' => 'sometimes|required|int',
            'vm_drop_location' => 'required_if:vm_drop,1|min:1|string|max:255',
            'timezone' => 'required',
            'mobile' => Rule::requiredIf(function () use ($call_forward, $twinning, $follow_me) {
                return ($call_forward == 1 || $twinning == 1 || $follow_me == 1);
            })
        ]);
        Log::info('Request data:', $this->request->all());
        $response = $this->model->newExtensionSave($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/user-count",
     *     summary="Get user-count",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *       @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"parentId"},
     *             @OA\Property(property="parentId", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="user-count list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Client Extension list fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="ip_address", type="string", example="192.168.1.1"),
     *                     @OA\Property(property="client_name", type="string", example="Client A"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-15T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Unauthenticated")
     *         )
     *     )
     * )
     */
    public function getExtensionCount()
    {
        $response = $this->model->getExtensionCount($this->request);
        return response()->json($response);
    }

    /**
     * Get client extension
     * Used in ivr mesu add / edit page
     * @return type
     */

    /**
     * @OA\Post(
     *     path="/get-client-extension",
     *     summary="Get client extension",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Client Extension list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Client Extension list fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="ip_address", type="string", example="192.168.1.1"),
     *                     @OA\Property(property="client_name", type="string", example="Client A"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-15T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Unauthenticated")
     *         )
     *     )
     * )
     */
    public function getClientExtensions()
    {
        $response = $this->model->getClientExtensions($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/check-extension-live",
     *     summary="Get Extension Live detail",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload containing the extension",
     *         @OA\JsonContent(
     *             required={"extension"},
     *             @OA\Property(
     *                 property="extension",
     *                 type="integer",
     *                 description="The extension number to check",
     *                 example=1001
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Extension Live info",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Extension Live info"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="extension", type="string", example="1001"),
     *                 @OA\Property(property="status", type="string", example="online"),
     *                 @OA\Property(property="ip_address", type="string", example="192.168.1.100"),
     *                 @OA\Property(property="user_agent", type="string", example="Zoiper/5.3.2"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-15T12:45:00Z")
     *             ))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="No Extension Live Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Extension Live Found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */

    public function getExtensionLive(Request $request)
    {
        $extension_live = ExtensionLive::on("mysql_" . $request->auth->parent_id)->where('extension', $request->extension)->get()->all();
        if (empty($extension_live))
            return $this->failResponse("No Extension Live Found");

        return $this->successResponse("Extension Live info", $extension_live);
    }

    /**
     * @OA\Get(
     *     path="/role",
     *     summary="Retrieve a combined list of Extension Role",
     *     tags={"Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Extension Role list retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Users List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *                     @OA\Property(property="role_name", type="string", example="Admin"),
     *                     @OA\Property(property="level", type="integer", example=9)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */

    public function roles(Request $request)
    {
        // Fetch all roles from the database
        $roles = Role::all();

        // Convert the collection to an array
        $rolesArray = $roles->toArray();

        // Return the success response with the roles array
        return $this->successResponse("Role", $rolesArray);
    }
 

    private function validateRequestTokens(Request $request)
    {
        $appToken = $request->header('X-Application-Token');
        $userToken = $request->header('X-Easify-User-Token');
    
        /**
         * ✅ Validate Application Token
         */
        if (!$appToken || $appToken !== config('services.phonify.app_token')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid application token.'
            ], 401);
        }
    
        /**
         * ✅ Validate Main User Token
         */
        if (!$userToken) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }
    
        $mainUser = \DB::table('master.users')
            ->where('easify_user_uuid', $userToken)
            ->first();
    
        if (!$mainUser) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }
    
        // Attach main user to request for later use
        $request->merge(['main_user_id' => $mainUser->id]);
       // 🔑 Attach owner id for later use

        return null;
    }
    

    
    public function validateSubUserRequest(Request $request)
    {
        $rules = [
            'email' => 'required|email|max:255|unique:master.users,email',
            'password' => 'required|string|min:8',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20|regex:/^\+\d{8,20}$/',
        ];
        
        $messages = [
            'email.unique' => 'Email Already in Use',
            'phone.regex' => 'Invalid Phone Number',
        ];
        
        try {
            Validator::make($request->all(), $rules, $messages)->validate();
        } catch (ValidationException $e) {
            // Get the first error message to return in 'message'
            $firstError = collect($e->errors())->flatten()->first();
        
            return response()->json([
                'success' => false,
                'message' => $firstError,
            ], 422);
        }
        if ($request->has('main_user_id')) {

            $maxUsers =  5; // or from plan table
    
            $currentCount = \DB::table('master.users')
                ->where('owner_id', $request->main_user_id)
                ->where('user_type', 'subuser')
                ->count();
    
                if ($currentCount >= $maxUsers) {
                    throw new \Illuminate\Validation\ValidationException(
                        Validator::make([], []),
                        response()->json([
                            'success' => false,
                            'message' => 'You have reached the maximum number of users. Please upgrade your plan to add more users.'
                        ], 422)
                    );
                }
                
        }
        if ($request->only_validate === true) {
            return response()->json([
                'success' => true,
                'message' => 'User creation validated successfully',
                'data' => [],
            ], 200);
        }
    
        return null;
    }
    
    
}



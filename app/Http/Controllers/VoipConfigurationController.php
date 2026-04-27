<?php

namespace App\Http\Controllers;

use App\Model\Master\VoipConfiguration;
use App\Services\PjsipRealtimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoipConfigurationController extends Controller
{

    /**
     * @OA\get(
     *     path="/voip-configurations",
     *     summary="voip configurations list",
     *     security={{"Bearer": {}}},
     *     description="show voip-configurations list.",
     *     tags={"VoipConfiguration"},
     *     security={{"Bearer": {}}},
     * *      @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         description="Search term to filter configurations by name, host, username, or prefix",
     *         @OA\Schema(type="string")
     *     ),
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
     *         description="voip-configurations list  retrived successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="voip-configurations list  retrived successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request, voip-configurations list not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Api are not added, Required fields are missing")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error while trying to add the API",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Api are not added successfully.")
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {

        // Start with the base query for the current user's parent_id
        $query = VoipConfiguration::on("master")
            ->where('parent_id', $request->auth->parent_id);

        // Handle search
        if ($request->has('search') && !empty($request->input('search'))) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('host', 'LIKE', "%{$search}%")
                    ->orWhere('username', 'LIKE', "%{$search}%")
                    ->orWhere('prefix', 'LIKE', "%{$search}%");
                // Do NOT include 'secret' in search for security reasons
            });
        }

        // Fetch all results
        $voip_configurations = $query->get()->all();

        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($voip_configurations);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $voip_configurations = array_slice($voip_configurations, $start, $limit, false);

            return $this->successResponse("Voip Configuration Lists", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $voip_configurations
            ]);
        }
        return $this->successResponse("Voip Configuration Lists", $voip_configurations);
    }

    public function index_old(Request $request)
    {
        /*if($request->auth->level > 9)
        {
            $voip_configurations = VoipConfiguration::on("master")->get()->all();
        }
        else
        {*/
        $voip_configurations = VoipConfiguration::on("master")->where('parent_id', $request->auth->parent_id)->get()->all();
        //}
        return $this->successResponse("Voip Configuration Lists", $voip_configurations);
    }

    /**
     * @OA\Put(
     *     path="/voip-configuration",
     *     summary="Create a new VoIP Configuration and User Extension",
     *     description="Stores a new VoIP configuration.",
     *     tags={"VoipConfiguration"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "host"},
     *             @OA\Property(property="name", type="string", example="Trunk One"),
     *             @OA\Property(property="host", type="string", example="192.168.1.100"),
     *             @OA\Property(property="username", type="string", example="trunk_one_user"),
     *             @OA\Property(property="secret", type="string", example="securepass"),
     *             @OA\Property(property="prefix", type="string", example="9")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="VoIP configuration created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voip Configuration created successfully !"),
     *             description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(
     *                     property="name",
     *                     type="array",
     *                     @OA\Items(type="string", example="The name field is required.")
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|string|max:255|unique:' . 'master' . '.user_extensions',
            "host"      => "required|string",
            //"username"  => "required|string",
            //"secret"  => "required|string",
            //"prefix"    => "required",
        ]);

        $dt['disallow'] = 'all';
        $dt['allow'] = 'ulaw;alaw;gsm;g729';
        $dt['context'] = 'trunkinbound-airespring';

        $name = str_replace(" ", "-", $request->name);

        $dt['host'] = $request->host;
        $dt['name'] = $name;
        $dt['nat'] = 'force_rport,comedia';
        if (!empty($request->username))
            $dt['username'] = $request->username;
        else
            $dt['username'] = $name;
        if (!empty($request->secret))
            $dt['secret'] = $request->secret;
        else
            $dt['secret'] = '';
        $dt['fullname'] = $name;

        //$dt['defaultuser'] = $request->username;
        //$dt['fromuser'] = $request->username;

        $insertData = "INSERT INTO user_extensions SET  disallow=:disallow, allow=:allow, context= :context, username=:username, host=:host, name= :name, nat= :nat , secret= :secret, fullname= :fullname";
        $record_ustextSav = DB::connection('master')->select($insertData, $dt);

        // Sync PJSIP realtime after VoIP extension create
        PjsipRealtimeService::syncExtension($dt['username'], $dt['secret'], $dt['context'], $dt['fullname']);

        $lastInsertId = DB::connection('master')->selectOne("SELECT * FROM user_extensions ORDER BY id DESC");
        $lastId = $lastInsertId->id;

        $voip_configuration = new VoipConfiguration();
        $voip_configuration->setConnection("master");
        $voip_configuration->name = $name . '_' . $request->auth->parent_id . '_' . $lastId;
        $voip_configuration->host = $request->host;
        $voip_configuration->username = $dt['username'];
        $voip_configuration->secret = $request->secret;
        $voip_configuration->prefix = $request->prefix;
        $voip_configuration->context = 'trunkinbound-airespring';
        $voip_configuration->parent_id = $request->auth->parent_id;
        $voip_configuration->trunk_id = $request->auth->parent_id . '_' . $lastId;
        $voip_configuration->user_extension_id = $lastId;
        $voip_configuration->nat = 'force_rport,comedia';
        $voip_configuration->save();
        return $this->successResponse("Voip Configuration created successfully !", $voip_configuration->toArray());
    }

    /**
     * @OA\Get(
     *     path="/voip-configuration/{id}",
     *     summary="Get VoIP configuration details",
     *     description="Fetches VoIP configuration information by its ID.",
     *     operationId="getVoipConfigurationById",
     *     tags={"VoipConfiguration"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="VoIP Configuration ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="VoIP configuration retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voip configuration info"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=55),
     *                     @OA\Property(property="name", type="string", example="Trunk-One_101_55"),
     *                     @OA\Property(property="host", type="string", example="192.168.1.100"),
     *                     @OA\Property(property="username", type="string", example="trunk_one_user"),
     *                     @OA\Property(property="prefix", type="string", example="9"),
     *                     @OA\Property(property="context", type="string", example="trunkinbound-airespring"),
     *                     @OA\Property(property="parent_id", type="integer", example=101),
     *                     @OA\Property(property="trunk_id", type="string", example="101_55"),
     *                     @OA\Property(property="user_extension_id", type="integer", example=55),
     *                     @OA\Property(property="nat", type="string", example="force_rport,comedia")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="VoIP configuration not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No Voip configuration with id 55")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch Voip configuration info")
     *         )
     *     )
     * )
     */

    public function show(Request $request, int $id)
    {
        try {
            $voip_configuration = VoipConfiguration::on("master")->where('id', $id)->get()->all();
            return $this->successResponse("Voip configuration info", $voip_configuration);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Voip configuration with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Voip configuration info", [], $exception);
        }
    }


    /**
     * @OA\Post(
     *     path="/voip-configuration/{id}",
     *     summary="Update VoIP Configuration",
     *     description="Updates the VoIP configuration and related user extension by ID.",
     *     operationId="updateVoipConfiguration",
     *     tags={"VoipConfiguration"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="VoIP Configuration ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "host", "user_extension_id"},
     *             @OA\Property(property="name", type="string", example="name Updated"),
     *             @OA\Property(property="host", type="string", example="192.168.1.101"),
     *             @OA\Property(property="username", type="string", example="updated_user"),
     *             @OA\Property(property="secret", type="string", example="newpass123"),
     *             @OA\Property(property="prefix", type="string", example="9"),
     *             @OA\Property(property="user_extension_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="VoIP configuration updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voip Configuration updated")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="VoIP configuration not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No Voip Configuration with id 55")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(
     *                     property="name",
     *                     type="array",
     *                     @OA\Items(type="string", example="The name field is required.")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to update Voip Configuration")
     *         )
     *     )
     * )
     */

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'name' => 'required|string',
            "host"      => "required|string",
            //"username"  => "required|string",
            //"secret"  => "required|string",
            //"prefix"    => "required",
        ]);

        try {

            $name = str_replace(" ", "-", $request->name);


            // if (!empty($request->username))
            //     $dt['username'] = $name;
            // else
            //     $dt['username'] = $name;
            $dt['username']=$request->username;
            if (!empty($request->secret))
                $dt['secret'] = $request->secret;
            else
                $dt['secret'] = '';
            $dt['host'] = $request->host;
            $dt['name'] = $request->name;
            //$dt['defaultuser'] = $request->username;
            //$dt['fromuser'] = $request->username;
            $dt['id'] = $request->user_extension_id;

            $dt['fullname'] = $name;


            $insertData = "UPDATE user_extensions SET username= :username , host= :host,name=:name, secret=:secret,fullname=:fullname WHERE id= :id ";
            $record_ustext = DB::connection('master')->select($insertData, $dt);

            // Sync PJSIP realtime after VoIP extension update
            if (!empty($dt['secret'])) {
                PjsipRealtimeService::syncPassword($dt['username'], $dt['secret']);
            }

            $voip_configuration = VoipConfiguration::on("master")->findOrFail($id);
            $voip_configuration->name = $name . '_' . $request->auth->parent_id . '_' . $dt['id'];
            $voip_configuration->host = $request->host;
            $voip_configuration->username = $dt['username'];
            $voip_configuration->secret = $request->secret;
            $voip_configuration->prefix = $request->prefix;
            $voip_configuration->save();

            return $this->successResponse("Voip Configuration updated");
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Voip Configuration with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Voip Configuration", [], $exception);
        }
    }

    /**
     * @OA\Get(
     *     path="/delete-voip-configuration/{id}",
     *     summary="Delete VoIP Configuration",
     *     description="Deletes a VoIP configuration by its ID.",
     *     operationId="deleteVoipConfiguration",
     *     tags={"VoipConfiguration"},
     *      security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="VoIP Configuration ID",
     *         @OA\Schema(type="integer", example=55)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="VoIP configuration deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="VoipConfiguration info deleted"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="VoIP configuration not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No VoipConfiguration with id 55")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch VoipConfiguration info")
     *         )
     *     )
     * )
     */

    public function delete(Request $request, int $id)
    {
        try {
            $VoipConfiguration = VoipConfiguration::on("master")->findOrFail($id);
            $data = $VoipConfiguration->delete();
            return $this->successResponse("VoipConfiguration info deleted", [$data]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No VoipConfiguration with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch VoipConfiguration info", [], $exception);
        }
    }
}

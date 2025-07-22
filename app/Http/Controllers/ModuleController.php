<?php

namespace App\Http\Controllers;

use App\Model\Master\Module;
use App\Model\Master\PhoneCountry;

use App\Model\Master\CountryWisePackageRates;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class ModuleController extends Controller
{
    public function __construct(Request $request)
    {
        if ($request->auth->level < 9) {
            throw new UnauthorizedHttpException();
        }
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    /**
     * @OA\Get(
     *     path="/modules",
     *     summary="Get list of modules",
     *     tags={"Module"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of Modules retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Modules list"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Acme Corp"),
     *                     @OA\Property(property="email", type="string", example="contact@acme.com"),
     *                     @OA\Property(property="status", type="string", example="active")
     *                 )
     *             )
     *         )
     *     )
     * )
     */


    public function index()
    {
        $modules = Module::on("master")->where('is_active', 1)->get()->all();
        return $this->successResponse("Active Modules List", $modules);
    }

    public function rate(Request $request, string $key)
    {
        $rate = CountryWisePackageRates::on("master")->where('package_key', $key)->get()->all();
        return $this->successResponse("Active Rate List", $rate);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $this->validate($request, [
            "name" => "required|string|unique:master.modules",
            "components" => "required|array",
            "attributes" => "required|array",
            "is_active" => "required|int",
            "display_order" => "required|int",
        ]);

        Log::debug("saveModule", $request->all());
        try {
            $modules = new Module($request->all());
            $modules->key = str_replace(' ', '-', strtolower($request->name));
            $modules->saveOrFail();
            return $this->successResponse("Module request created", $modules->toArray());
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["Unable to save " . $request->all()], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save the module request", [$exception->getMessage()], $exception);
        }
    }

    public function createRate(Request $request)
    {
        /*return $request->all();
        $this->validate($request, [
            "package_name" => "required|string",
            "country_code" => "required",
            "call_rate_per_minute" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_six_by_six_sec" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_sms" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_did" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_fax" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_email" => "required|regex:/^\d*(\.\d{1,5})?$/",
        ]);

*/
        $count = count($request->country_code);
        Log::debug("saverate", $request->all());

        try {

            for ($i = 0; $i < $count; $i++) {
                //  return $request['country_code'][$i];
                $PhoneCountry = PhoneCountry::where('phone_code', $request['country_code'][$i])->get()->first();

                $country_name = $PhoneCountry['country_name'];

                //  return $this->successResponse("Packages Rate request created", [$country_name]);

                $check = CountryWisePackageRates::where('phone_code', $request['country_code'][$i])->where('package_key', $request['package_name'])->get()->first();
                if (!empty($check)) {
                    continue;
                }


                $package_rate = new CountryWisePackageRates();
                $package_rate->package_key = $request['package_name'];
                $package_rate->phone_code = $request['country_code'][$i];
                $package_rate->call_rate_per_minute = $request['call_rate_per_minute'][$i];
                $package_rate->rate_six_by_six_sec = $request['rate_six_by_six_sec'][$i];

                $package_rate->rate_per_sms = $request['rate_per_sms'][$i];
                $package_rate->rate_per_did = $request['rate_per_did'][$i];
                $package_rate->rate_per_fax = $request['rate_per_fax'][$i];
                $package_rate->rate_per_email = $request['rate_per_email'][$i];

                $package_rate->saveOrFail();

                $last_id = $package_rate->id;

                $update = CountryWisePackageRates::findOrFail($last_id);
                $update->title_name = $country_name;
                $update->saveOrFail();
            }
            return $this->successResponse("Packages Rate request created", $package_rate->toArray());
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["Unable to save " . $request->all()], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save the package request", [$exception->getMessage()], $exception);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  Module  $module
     * @return \Illuminate\Http\Response
     */


    /**
     * @OA\Get(
     *     path="/module/{key}",
     *     summary="Get module detail",
     *     tags={"Module"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         required=true,
     *         description="The key (ID) of the module",
     *         @OA\Schema(type="string", example="billing")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Module info retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Module info"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="User Management"),
     *                 @OA\Property(property="is_active", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid request"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="No Module with key 1"))
     *         )
     *     )
     * )
     */


    public function show(string $key)
    {
        try {
            $module_details = Module::findOrFail($key);
            $data = $module_details->toArray();
            return $this->successResponse("Module info", $data);
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["No Module with key $key"], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Module info", [], $exception);
        }
    }


    public function showRate(int $id)
    {
        try {
            $rate_details = CountryWisePackageRates::findOrFail($id);
            $data = $rate_details->toArray();
            return $this->successResponse("Rate info", $data);
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["No Country Rate with key $id"], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Module info", [], $exception);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  Module  $module
     * @return \Illuminate\Http\Response
     */
    public function edit(Module $module)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  Module  $module
     * @return \Illuminate\Http\Response
     */

    /**
     * @OA\Post(
     *     path="/module/{key}",
     *     summary="Update a module",
     *     tags={"Module"},
     *     security={{"Bearer":{}}},
     *     operationId="updateModule",
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         required=true,
     *         description="The key (ID) of the module to be updated",
     *         @OA\Schema(type="string", example="billing")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "components", "attributes", "is_active", "display_order"},
     *             @OA\Property(property="name", type="string", example="Updated Module"),
     *             @OA\Property(property="components", type="array", @OA\Items(type="string"), example={"component1", "component2"}),
     *             @OA\Property(property="attributes", type="array", @OA\Items(type="string"), example={"attr1", "attr2"}),
     *             @OA\Property(property="is_active", type="integer", example=1),
     *             @OA\Property(property="display_order", type="integer", example=5)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Module updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Module updated"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Updated Module"),
     *                 @OA\Property(property="components", type="array", @OA\Items(type="string")),
     *                 @OA\Property(property="attributes", type="array", @OA\Items(type="string")),
     *                 @OA\Property(property="is_active", type="integer", example=1),
     *                 @OA\Property(property="display_order", type="integer", example=5)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request or module not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid request"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="No Module with key 1"))
     *         )
     *     )
     * )
     */

    public function update(Request $request, string $key)
    {
        $this->validate($request, [
            "name" => "required|string",
            "components" => "required|array",
            "attributes" => "required|array",
            "is_active" => "required|int",
            "display_order" => "required|int",
        ]);
        $input = $request->all();

        Log::debug("updateModules", $request->all());

        try {
            $module_details = Module::findOrFail($key);
            $module_details->update($input);
            $data = $module_details->toArray();
            return $this->successResponse("Module updated", $data);
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["No Module with key $key"], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update module", [], $exception);
        }
    }

    public function updateRate(Request $request, int $id)
    {
        $this->validate($request, [
            "package_name" => "required|string",
            "call_rate_per_minute" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_six_by_six_sec" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_sms" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_did" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_fax" => "required|regex:/^\d*(\.\d{1,5})?$/",
            "rate_per_email" => "required|regex:/^\d*(\.\d{1,5})?$/",
        ]);
        $input = $request->all();

        Log::debug("updateRates", $request->all());

        try {
            $rate_details = CountryWisePackageRates::findOrFail($id);
            $rate_details->update($input);
            $data = $rate_details->toArray();
            return $this->successResponse("Country Rate updated", $data);
        } catch (ModelNotFoundException $modelNotFoundException) {
            return $this->failResponse("Invalid request", ["No Module with key $id"], $modelNotFoundException, 400);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update module", [], $exception);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Module  $module
     * @return \Illuminate\Http\Response
     */
    public function destroy(Module $module)
    {
        //
    }
}

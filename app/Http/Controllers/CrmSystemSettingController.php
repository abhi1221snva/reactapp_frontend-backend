<?php

namespace App\Http\Controllers;

use App\Model\Client\SystemSetting;
use App\Model\Master\DomainList;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CrmSystemSettingController extends Controller
{

    /**
     * @OA\Get(
     *     path="/crm-system-setting",
     *     summary="Get the list of groups for the authenticated client",
     *     description="Fetches all groups for a specific client based on the parent_id of the authenticated user.",
     *     tags={"Company"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Groups list retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Groups"),
     *             @OA\Property(property="data", type="array", 
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Admin Group"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-22T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-22T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch groups",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to list of groups")
     *         )
     *     )
     * )
     */

    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $group = [];
            $group = SystemSetting::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            return $this->successResponse("Groups", $group);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    /**
     * @OA\Post(
     *     path="/crm-system-setting",
     *     summary="Create a new Company for a client",
     *     description="Creates a Company for a client .",
     *     tags={"Company"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"company_name", "company_email", "company_phone", "company_address", "state", "city", "zipcode", "logo", "company_domain"},
     *             @OA\Property(property="company_name", type="string", example="My Company"),
     *             @OA\Property(property="company_email", type="string", example="contact@company.com"),
     *             @OA\Property(property="company_phone", type="string", example="1234567890"),
     *             @OA\Property(property="company_address", type="string", example="123 Main St, City, Country"),
     *             @OA\Property(property="state", type="string", example="State Name"),
     *             @OA\Property(property="city", type="string", example="City Name"),
     *             @OA\Property(property="zipcode", type="string", example="12345"),
     *             @OA\Property(property="logo", type="string", format="binary"),
     *             @OA\Property(property="company_domain", type="string", example="company.com")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Company added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="System setting Added Successfully"),
     *             @OA\Property(property="data", type="object", 
     *                 @OA\Property(property="company_name", type="string", example="My Company"),
     *                 @OA\Property(property="company_email", type="string", example="contact@company.com"),
     *                 @OA\Property(property="company_phone", type="string", example="1234567890"),
     *                 @OA\Property(property="company_address", type="string", example="123 Main St, City, Country"),
     *                 @OA\Property(property="state", type="string", example="State Name"),
     *                 @OA\Property(property="city", type="string", example="City Name"),
     *                 @OA\Property(property="zipcode", type="string", example="12345"),
     *                 @OA\Property(property="logo", type="string", example="logo.png"),
     *                 @OA\Property(property="company_domain", type="string", example="company.com")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create Company",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to create company setting")
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id;
        Log:
        info('reached', ['logo' => $request->logo]);
        try {
            $System = new SystemSetting();
            $System->setConnection("mysql_$clientId");
            $System->company_name = $request->company_name;
            $System->company_email = $request->company_email;
            $System->company_phone = $request->company_phone;
            $System->company_address = $request->company_address;
            $System->state = $request->state;
            $System->city = $request->city;
            $System->zipcode = $request->zipcode;
            $System->logo = $request->logo;
            $System->company_domain = $request->company_domain;
            $newDomain = $request->company_domain;
            // Check if a domain for the current client already exists
            $existingDomain = DomainList::where('client_id', $clientId)->first();

            if ($existingDomain) {
                // If the domain exists, update it
                $existingDomain->domain_name = $newDomain;
                $existingDomain->save();
                Log::info("Updated existing domain for client", ['client_id' => $clientId, 'domain' => $newDomain]);
            } else {
                // If the domain does not exist, insert a new record
                DomainList::create([
                    'client_id' => $clientId,
                    'domain_name' => $newDomain,
                ]);
                Log::info("Inserted new domain for client", ['client_id' => $clientId, 'domain' => $newDomain]);
            }
            $System->saveOrFail();


            return $this->successResponse("System setting Added Successfully", $System->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create System setting ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


    /**
     * @OA\Post(
     *     path="/update-system-setting/{id}",
     *     summary="Update a system setting for a client",
     *     description="Updates an existing Company system setting for a client, including company details and domain settings.",
     *     tags={"Company"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="The ID of the  system setting to be updated",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             required={},
     *             @OA\Property(property="company_name", type="string", example="Updated Company Name"),
     *             @OA\Property(property="company_email", type="string", example="newemail@company.com"),
     *             @OA\Property(property="company_phone", type="string", example="0987654321"),
     *             @OA\Property(property="company_address", type="string", example="456 New Address St, New City, Country"),
     *             @OA\Property(property="state", type="string", example="New State"),
     *             @OA\Property(property="city", type="string", example="New City"),
     *             @OA\Property(property="zipcode", type="string", example="67890"),
     *             @OA\Property(property="logo", type="string", format="binary"),
     *             @OA\Property(property="company_domain", type="string", example="updatedcompany.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="System setting updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Company details has been Updated"),
     *             @OA\Property(property="data", type="object", 
     *                 @OA\Property(property="company_name", type="string", example="Updated Company Name"),
     *                 @OA\Property(property="company_email", type="string", example="newemail@company.com"),
     *                 @OA\Property(property="company_phone", type="string", example="0987654321"),
     *                 @OA\Property(property="company_address", type="string", example="456 New Address St, New City, Country"),
     *                 @OA\Property(property="state", type="string", example="New State"),
     *                 @OA\Property(property="city", type="string", example="New City"),
     *                 @OA\Property(property="zipcode", type="string", example="67890"),
     *                 @OA\Property(property="logo", type="string", example="newlogo.png"),
     *                 @OA\Property(property="company_domain", type="string", example="updatedcompany.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="System setting not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Company details Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid System Setting id 1"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update system setting",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to update Company details."),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Error message"))
     *         )
     *     )
     * )
     */


    public function update(Request $request, $id)
    {
        Log::info('reached', [$request->all()]);
        $clientId = $request->auth->parent_id;



        try {
            $System = SystemSetting::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("company_name")) {
                $System->company_name = $request->input("company_name");
            }

            if ($request->has("company_address")) {
                $System->company_address = $request->input("company_address");
            }
            if ($request->has("company_email")) {
                $System->company_email = $request->input("company_email");
            }
            if ($request->has("company_phone")) {
                $System->company_phone = $request->input("company_phone");
            }
            if ($request->has("state")) {
                $System->state = $request->input("state");
            }
            if ($request->has("city")) {
                $System->city = $request->input("city");
            }
            if ($request->has("zipcode")) {
                $System->zipcode = $request->input("zipcode");
            }
            if ($request->has("logo")) {
                $System->logo = $request->input("logo");
            }

            // Handle company_domain explicitly
            $newDomain = $request->has("company_domain") ? $request->input("company_domain") : null;
            $System->company_domain = $newDomain;
            // Check if a domain for the current client already exists
            $existingDomain = DomainList::where('client_id', $clientId)->first();

            if ($existingDomain) {
                // If the domain exists, update it
                $existingDomain->domain_name = $newDomain;
                $existingDomain->save();
                Log::info("Updated existing domain for client", ['client_id' => $clientId, 'domain' => $newDomain]);
            } else {
                // If the domain does not exist, insert a new record
                DomainList::create([
                    'client_id' => $clientId,
                    'domain_name' => $newDomain,
                ]);
                Log::info("Inserted new domain for client", ['client_id' => $clientId, 'domain' => $newDomain]);
            }
            $System->saveOrFail();
            return $this->successResponse("Company details has been Updated", $System->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Company details Not Found", [
                "Invalid System Setting  id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Company details. ", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    /**
     * @OA\Get(
     *     path="/company-columns",
     *     summary="Get the list of company-columns.",
     *     description="Fetches all company columns.",
     *     tags={"Company"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="company columns list retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Groups"),
     *             description="extenstion data"
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch groups",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to list of groups")
     *         )
     *     )
     * )
     */
    public function companyColumns(Request $request)
    {
        $clientId = $request->auth->parent_id;

        $columns = Schema::connection('mysql_' . $clientId)->getColumnListing('crm_system_setting');

        return $this->successResponse("Company Columns", $columns);
    }
}

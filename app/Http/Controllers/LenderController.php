<?php

namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Client\Lender;
use App\Model\Client\CrmLenderAPis;


use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Master\LoginLog;
use App\Model\Master\Permission;

use App\Model\Master\UserExtensions;

use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use App\Services\RolesService;
use DB;

use Illuminate\Validation\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LenderController extends Controller
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
     *     path="/lenders",
     *     summary="Get a list of lenders",
     *     description="Fetches a list of lenders for the authenticated client's database.",
     *     tags={"Lender"},
     *     security={{"Bearer":{}}},
     *      @OA\Parameter(
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
     *         description="List of lenders retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="View List of Lenders"),
     *             @OA\Property(property="data", type="array", 
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="lender_name", type="string", example="HDFC Bank"),
     *                     @OA\Property(property="contact_person", type="string", example="John Doe"),
     *                     @OA\Property(property="email", type="string", example="contact@hdfc.com"),
     *                     @OA\Property(property="phone", type="string", example="9876543210"),
     *                     @OA\Property(property="address", type="string", example="Mumbai, India")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve lenders",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to View Lenders"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Database connection failed"))
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;

            // Support page/per_page (new frontend) and start/limit (legacy)
            if ($request->has('page') || $request->has('per_page')) {
                $page    = max(1, (int)$request->input('page', 1));
                $perPage = min((int)$request->input('per_page', 25), 200);
                $status  = $request->input('status');

                $query = Lender::on("mysql_$clientId");
                if ($status !== null) {
                    $query->where('status', $status);
                }

                $total   = $query->count();
                $lenders = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

                return $this->successResponse("View List of Lenders", [
                    'data'         => $lenders,
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'current_page' => $page,
                    'last_page'    => (int)ceil($total / $perPage),
                ]);
            }

            // Legacy start/limit pagination
            $lender = Lender::on("mysql_$clientId")->get()->all();
            if ($request->has('start') && $request->has('limit')) {
                $total_row = count($lender);
                $start = (int)$request->input('start');
                $limit = (int)$request->input('limit');
                $lender = array_slice($lender, $start, $limit, false);
                return $this->successResponse("View List of Lenders", [
                    'start' => $start,
                    'limit' => $limit,
                    'total' => $total_row,
                    'data'  => $lender,
                ]);
            }

            return $this->successResponse("View List of Lenders", $lender);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to View Lenders ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function index_old(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $lender = [];
            $lender = Lender::on("mysql_$clientId")->get()->all();
            return $this->successResponse("View List of Lenders", $lender);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to View Lenders ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $clientId = $request->auth->parent_id;

            /** @var Client $client */
            $client = Lender::on("mysql_$clientId")->findOrFail($id);
            $data = $client->toArray();
            return $this->successResponse("Lender info", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lender with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Lender info", [], $exception);
        }
    }

    /**
     * @OA\Put(
     *     path="/lender",
     *     summary="Create a new lender",
     *     description="Create a new lender with optional API credentials and various parameters.",
     *     tags={"Lender"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lender_name"},
     *             @OA\Property(property="lender_name", type="string", example="ABC Funding"),
     *             @OA\Property(property="email", type="string", format="email", example="abc@lender.com"),
     *             @OA\Property(property="secondary_email", type="string", example="support@lender.com"),
     *             @OA\Property(property="secondary_email2", type="string", example="backup@lender.com"),
     *             @OA\Property(property="secondary_email3", type="string", example="third@lender.com"),
     *             @OA\Property(property="secondary_email4", type="string", example="fourth@lender.com"),
     *             @OA\Property(property="contact_person", type="string", example="John Doe"),
     *             @OA\Property(property="phone", type="string", example="1234567890"),
     *             @OA\Property(property="address", type="string", example="123 Main Street"),
     *             @OA\Property(property="city", type="string", example="New York"),
     *             @OA\Property(property="state", type="string", example="NY"),
     *             @OA\Property(property="country", type="string", example="USA"),
     *             @OA\Property(property="lender_type", type="string", example="Type A"),
     *             @OA\Property(property="data_type", type="string", example="JSON"),
     *             @OA\Property(property="min_credit_score", type="integer", example=600),
     *             @OA\Property(property="max_negative_days", type="integer", example=5),
     *             @OA\Property(property="max_advance", type="number", format="float", example=100000.00),
     *             @OA\Property(property="nsfs", type="integer", example=3),
     *             @OA\Property(property="min_time_business", type="string", example="6 months"),
     *             @OA\Property(property="min_amount", type="number", format="float", example=5000.00),
     *             @OA\Property(property="min_deposits", type="integer", example=3),
     *             @OA\Property(property="max_position", type="array", @OA\Items(type="string"), example={"1", "2"}),
     *             @OA\Property(property="max_term", type="integer", example=12),
     *             @OA\Property(property="white_label", type="boolean", example=true),
     *             @OA\Property(property="consolidation", type="boolean", example=false),
     *             @OA\Property(property="reverse_consolidation", type="boolean", example=false),
     *             @OA\Property(property="sole_prop", type="boolean", example=true),
     *             @OA\Property(property="home_business", type="boolean", example=false),
     *             @OA\Property(property="non_profit", type="boolean", example=false),
     *             @OA\Property(property="daily", type="boolean", example=true),
     *             @OA\Property(property="coj_req", type="boolean", example=true),
     *             @OA\Property(property="not_business_type", type="string", example="Retail"),
     *             @OA\Property(property="bank_verify", type="boolean", example=true),
     *             @OA\Property(property="daily_balance", type="boolean", example=false),
     *             @OA\Property(property="industry", type="array", @OA\Items(type="string"), example={"Retail", "Healthcare"}),
     *             @OA\Property(property="guideline_state", type="array", @OA\Items(type="string"), example={"NY", "CA"}),
     *             @OA\Property(property="guideline_file", type="string", example="file.pdf"),
     *             @OA\Property(property="notes", type="string", example="Some notes about the lender."),
     *             @OA\Property(property="min_avg_revenue", type="number", format="float", example=10000),
     *             @OA\Property(property="min_monthly_deposit", type="integer", example=3),
     *             @OA\Property(property="max_mca_payoff_amount", type="number", format="float", example=20000.00),
     *             @OA\Property(property="loc", type="boolean", example=true),
     *             @OA\Property(property="ownership_percentage", type="integer", example=80),
     *             @OA\Property(property="factor_rate", type="number", format="float", example=1.25),
     *             @OA\Property(property="prohibited_industry", type="string", example="Adult, Gambling"),
     *             @OA\Property(property="restricted_industry_note", type="string", example="Some industries not allowed."),
     *             @OA\Property(property="restricted_state_note", type="string", example="Cannot fund in NV."),
     *             @OA\Property(property="api_status", type="string", example="1"),
     *             @OA\Property(property="lender_api_type", type="string", example="REST"),
     *             @OA\Property(property="username", type="string", example="apiuser"),
     *             @OA\Property(property="password", type="string", example="apipass"),
     *             @OA\Property(property="api_key", type="string", example="XYZ-123-KEY"),
     *             @OA\Property(property="url", type="string", example="https://api.lender.com"),
     *             @OA\Property(property="salesRepEmailAddress", type="string", example="sales@lender.com"),
     *             @OA\Property(property="partner_api_key", type="string", example="PARTNER-456"),
     *             @OA\Property(property="client_id", type="string", example="CLIENT123"),
     *             @OA\Property(property="auth_url", type="string", example="https://auth.lender.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Lender created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create lender",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string"),
     *             @OA\Property(property="line", type="integer"),
     *             @OA\Property(property="file", type="string")
     *         )
     *     )
     * )
     */
    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id;
        try {
            $this->validate($request, [
                'lender_name' => 'required|string|max:255',
            ]);
            $Lender = new Lender();
            $Lender->setConnection("mysql_$clientId");
            $Lender->lender_name = $request->lender_name;
            $Lender->email = $request->email;
            $Lender->secondary_email = $request->secondary_email;
            $Lender->secondary_email2 = $request->secondary_email2;

            $Lender->contact_person = $request->contact_person;

            $Lender->phone = $request->phone;
            $Lender->address = $request->address;
            $Lender->city = $request->city;
            $Lender->state = $request->state;
            $Lender->lender_type = $request->lender_type;
            $Lender->data_type = $request->data_type;
            $Lender->min_credit_score = $request->min_credit_score;
            $Lender->max_negative_days = $request->max_negative_days;
            $Lender->max_advance = $request->max_advance;
            $Lender->nsfs = $request->nsfs;
            $Lender->min_time_business = $request->min_time_business;
            $Lender->min_amount = $request->min_amount;
            $Lender->min_deposits = $request->min_deposits;
            $Lender->max_position = is_array($request->max_position) ? json_encode($request->max_position) : json_encode([]);
            $Lender->max_term = $request->max_term;
            $Lender->white_label = $request->white_label;
            $Lender->consolidation = $request->consolidation;
            $Lender->reverse_consolidation = $request->reverse_consolidation;

            $Lender->sole_prop = $request->sole_prop;
            $Lender->home_business = $request->home_business;
            $Lender->non_profit = $request->non_profit;
            $Lender->daily = $request->daily;
            $Lender->coj_req = $request->coj_req;
            $Lender->country = $request->country;
            $Lender->not_business_type = $request->not_business_type;
            $Lender->bank_verify = $request->bank_verify;
            $Lender->daily_balance = $request->daily_balance;
            // Convert the 'industry' array into a comma-separated string
            $Lender->industry = is_array($request->industry) ? json_encode($request->industry) : json_encode([]);
            $Lender->guideline_state = is_array($request->guideline_state) ? json_encode($request->guideline_state) : json_encode([]);


            $Lender->guideline_file = $request->guideline_file;
            $Lender->notes = $request->notes;
            $Lender->min_avg_revenue = $request->min_avg_revenue;
            $Lender->min_monthly_deposit = $request->min_monthly_deposit;
            $Lender->max_mca_payoff_amount = $request->max_mca_payoff_amount;
            $Lender->loc = $request->loc;
            $Lender->ownership_percentage = $request->ownership_percentage;
            $Lender->factor_rate = $request->factor_rate;
            $Lender->prohibited_industry = $request->prohibited_industry;
            $Lender->restricted_industry_note = $request->restricted_industry_note;
            $Lender->restricted_state_note = $request->restricted_state_note;
            $Lender->secondary_email3 = $request->secondary_email3;
            $Lender->secondary_email4 = $request->secondary_email4;

            $Lender->api_status = $request->api_status;
            if ($request->api_status == "1") {
                $Lender->lender_api_type = trim($request->lender_api_type);
            }

            $Lender->saveOrFail();

            if ($request->api_status == "1") {
                $crmLenderApi = new CrmLenderAPIs();
                $crmLenderApi->setConnection("mysql_$clientId");

                $crmLenderApi->crm_lender_id = $Lender->id; // Associate with Lender
                $crmLenderApi->username = trim($request->username);
                $crmLenderApi->password = trim($request->password);
                $crmLenderApi->api_key = trim($request->api_key);
                $crmLenderApi->url = trim($request->url);
                $crmLenderApi->type = trim($request->lender_api_type);
                $crmLenderApi->sales_rep_email = trim($request->salesRepEmailAddress);
                $crmLenderApi->partner_api_key = trim($request->partner_api_key);
                $crmLenderApi->client_id = trim($request->client_id);
                $crmLenderApi->auth_url = trim($request->auth_url);


                $crmLenderApi->save();
            }


            return $this->successResponse("Added Successfully", $Lender->toArray());
        } catch (\Throwable $e) {
            // Catch any error and return the error message along with the line number
            return response()->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
        }
    }


    /**
     * @OA\Post(
     *     path="/lender/{id}",
     *     summary="Update Lender Information",
     *     description="Update lender details and associated API info if applicable.",
     *     tags={"Lender"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the lender to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lender_name"},
     *             @OA\Property(property="lender_name", type="string", example="ABC Funding"),
     *             @OA\Property(property="email", type="string", format="email", example="abc@lender.com"),
     *             @OA\Property(property="contact_person", type="string", example="John Doe"),
     *             @OA\Property(property="phone", type="string", example="1234567890"),
     *             @OA\Property(property="city", type="string", example="New York"),
     *             @OA\Property(property="state", type="string", example="NY"),
     *             @OA\Property(property="min_credit_score", type="integer", example=600),
     *             @OA\Property(property="api_status", type="string", example="1"),
     *             @OA\Property(property="lender_api_type", type="string", example="REST"),
     *             @OA\Property(property="username", type="string", example="apiuser"),
     *             @OA\Property(property="password", type="string", example="apipass"),
     *             @OA\Property(property="api_key", type="string", example="XYZ-123-KEY"),
     *             @OA\Property(property="url", type="string", example="https://api.lender.com"),
     *             @OA\Property(property="salesRepEmailAddress", type="string", example="sales@lender.com"),
     *             @OA\Property(property="partner_api_key", type="string", example="PARTNER-456"),
     *             @OA\Property(property="client_id", type="string", example="CLIENT123"),
     *             @OA\Property(property="auth_url", type="string", example="https://auth.lender.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lender updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Lender Update"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lender not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid Lender id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Lender",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to update Lender")
     *         )
     *     )
     * )
     */



    public function update(Request $request, int $id)
    {
        Log::info('reached', [$request->all()]);
        $clientId = $request->auth->parent_id;
        $this->validate($request, [
            'lender_name' => 'required|string|max:255',
        ]);

        try {
            $user = Lender::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("lender_name"))
                $user->lender_name = $request->input("lender_name");
            if ($request->has("email"))
                $user->email = $request->input("email");

            if ($request->has("secondary_email"))
                $user->secondary_email = $request->input("secondary_email");
            if ($request->has("secondary_email2"))
                $user->secondary_email2 = $request->input("secondary_email2");
            if ($request->has("contact_person"))
                $user->contact_person = $request->input("contact_person");
            if ($request->has("phone"))
                $user->phone = $request->input("phone");
            if ($request->has("address"))
                $user->address = $request->input("address");
            if ($request->has("city"))
                $user->city = $request->input("city");
            if ($request->has("state"))
                $user->state = $request->input("state");
            if ($request->has("lender_type"))
                $user->lender_type = $request->input("lender_type");
            if ($request->has("data_type"))
                $user->data_type = $request->input("data_type");
            if ($request->has("min_credit_score"))
                $user->min_credit_score = $request->input("min_credit_score");
            if ($request->has("max_negative_days"))
                $user->max_negative_days = $request->input("max_negative_days");
            if ($request->has("max_advance"))
                $user->max_advance = $request->input("max_advance");
            if ($request->has("min_deposits"))
                $user->min_deposits = $request->input("min_deposits");
            if ($request->has("nsfs"))
                $user->state = $request->input("nsfs");
            if ($request->has("min_time_business"))
                $user->min_time_business = $request->input("min_time_business");
            if ($request->has("min_amount"))
                $user->min_amount = $request->input("min_amount");
            if ($request->has("max_position"))
                $user->max_position = $request->input("max_position");
            if ($request->has("max_term"))
                $user->max_term = $request->input("max_term");
            if ($request->has("white_label"))
                $user->white_label = $request->input("white_label");
            if ($request->has("consolidation"))
                $user->consolidation = $request->input("consolidation");
            if ($request->has("reverse_consolidation"))
                $user->reverse_consolidation = $request->input("reverse_consolidation");
            if ($request->has("sole_prop"))
                $user->sole_prop = $request->input("sole_prop");
            if ($request->has("home_business"))
                $user->home_business = $request->input("home_business");
            if ($request->has("non_profit"))
                $user->non_profit = $request->input("non_profit");
            if ($request->has("daily"))
                $user->daily = $request->input("daily");
            if ($request->has("coj_req"))
                $user->coj_req = $request->input("coj_req");
            if ($request->has("country"))
                $user->country = $request->input("country");
            if ($request->has("not_business_type"))
                $user->not_business_type = $request->input("not_business_type");
            if ($request->has("bank_verify"))
                $user->bank_verify = $request->input("bank_verify");
            if ($request->has("daily_balance"))
                $user->daily_balance = $request->input("daily_balance");
            if ($request->has("industry"))
                $user->industry = $request->input("industry");
            if ($request->has("guideline_state"))
                $user->guideline_state = $request->input("guideline_state");
            if ($request->has("guideline_file"))
                $user->guideline_file = $request->input("guideline_file");
            if ($request->has("notes"))
                $user->notes = $request->input("notes");
            if ($request->has("min_avg_revenue"))
                $user->min_avg_revenue = $request->input("min_avg_revenue");
            if ($request->has("min_monthly_deposit"))
                $user->min_monthly_deposit = $request->input("min_monthly_deposit");
            if ($request->has("max_mca_payoff_amount"))
                $user->max_mca_payoff_amount = $request->input("max_mca_payoff_amount");
            if ($request->has("loc"))
                $user->loc = $request->input("loc");
            if ($request->has("ownership_percentage"))
                $user->ownership_percentage = $request->input("ownership_percentage");
            if ($request->has("factor_rate"))
                $user->factor_rate = $request->input("factor_rate");
            if ($request->has("prohibited_industry"))
                $user->prohibited_industry = $request->input("prohibited_industry");
            if ($request->has("restricted_industry_note"))
                $user->restricted_industry_note = $request->input("restricted_industry_note");
            if ($request->has("restricted_state_note"))
                $user->restricted_state_note = $request->input("restricted_state_note");
            if ($request->has("secondary_email3"))
                $user->secondary_email3 = $request->input("secondary_email3");
            if ($request->has("secondary_email4"))
                $user->secondary_email4 = $request->input("secondary_email4");
            if ($request->has("api_status"))
                $user->api_status = $request->input("api_status");
            if ($request->api_status == "1") {
                $user->lender_api_type = trim($request->lender_api_type);
            };
            $user->saveOrFail();


            Log::info('updated', ['user' => $user]);
            if ($request->api_status == "1") {
                // Find or create a new CrmLenderAPI entry based on the crm_lender_id
                $crmLenderApi = CrmLenderAPIs::on("mysql_$clientId")
                    ->firstOrNew(['crm_lender_id' => $id]);
                $crmLenderApi->setConnection("mysql_$clientId");
                $crmLenderApi->username = trim($request->username);
                $crmLenderApi->password = trim($request->password);
                $crmLenderApi->api_key = trim($request->api_key);
                $crmLenderApi->url = trim($request->url);
                $crmLenderApi->type = trim($request->lender_api_type);
                $crmLenderApi->sales_rep_email = trim($request->salesRepEmailAddress);
                $crmLenderApi->partner_api_key = trim($request->partner_api_key);
                $crmLenderApi->client_id = trim($request->client_id);
                $crmLenderApi->auth_url = trim($request->auth_url);
                $crmLenderApi->crm_lender_id = $id;


                $crmLenderApi->saveOrFail();
            }

            //$user_extension
            return $this->successResponse("Lender Update", $user->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lender Not Found", [
                "Invalid Lender id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lender", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /**
     * @OA\Delete(
     *     path="/delete-lender/{id}",
     *     summary="delete a Lender",
     *     description="Marks a user as deleted by setting is_deleted to 1 in the master database.",
     *     tags={"Lender"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the user to delete",
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lender deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Lender Deleted Successfully"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=5),
     *                     @OA\Property(property="is_deleted", type="boolean", example=true)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lender not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No Lender with id 5")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to delete Lender",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to fetch Lender info")
     *         )
     *     )
     * )
     */


    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $User = Lender::on("mysql_$clientId")->findOrFail($id);
            $User->delete();
            return $this->successResponse("Lender Deleted Successfully", [$User]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No User Name with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch User Name info", [], $exception);
        }
    }

    public function changeLenderStatus(Request $request)
    {
        $clientId = $request->auth->parent_id;
        try {
            $Lender = Lender::on("mysql_$clientId")->findOrFail($request->lender_id);
            $Lender->status = $request->status;
            $Lender->saveOrFail();
            return $this->successResponse("Lender Updated", $Lender->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lender Not Found", [
                "Invalid Lender id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lender", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    function generateExtension($arrExistingExtensions)
    {
        $intGeneratedExtension = '';
        $boolUniqueFound = true;

        while ($boolUniqueFound) {
            $intGeneratedExtension = mt_rand(1000, 9999);
            if (!in_array($intGeneratedExtension, $arrExistingExtensions)) {
                $boolUniqueFound = false;
            }
        }
        return $intGeneratedExtension;
    }
    public function crmLenderApi(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $lender = [];
            $lender = CrmLenderApis::on("mysql_$clientId")->where('crm_lender_id', $id)->first();
            return $this->successResponse("View List of Lenders", $lender->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to View Lenders ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
}

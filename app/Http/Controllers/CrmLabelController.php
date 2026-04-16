<?php

namespace App\Http\Controllers;

use App\Model\Client\CrmLabel;
use App\Services\LeadFieldService;
use App\Services\ValidationSuggestionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use App\Http\Helper\Log;

class CrmLabelController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, CrmLabel $CrmLabel)
    {
        $this->request = $request;
        $this->model = $CrmLabel;
    }
    public function viewOnLead(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $Label = [];
            $Label = CrmLabel::on("mysql_$clientId")->where('view_on_lead', '1')->where('status', '1')->orderBy('display_order', 'ASC')->get()->all();
            return $this->successResponse("View List of Label", $Label);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to View Label ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    /*
     * Fetch Label details
     * @return json
     */
    /**
     * @OA\Get(
     *      path="/crm-labels",
     *      summary="Labels",
     *      tags={"CrmLabel"},
     *      security={{"Bearer":{}}},
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
     *      @OA\Response(
     *          response="200",
     *          description="label data"
     *      )
     * )
     */
    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $Label = [];
            $Label = CrmLabel::on("mysql_$clientId")->where('status', true)->orderBy('display_order', 'ASC')->get()->all();
            if ($request->has('start') && $request->has('limit')) {
                $total_row = count($Label);
                $start = (int)$request->input('start'); // Start index (0-based)
                $limit = (int)$request->input('limit'); // Limit number of records to fetch
                $Label = array_slice($Label, $start, $limit, false); // Fetch data from start to start+length

                return $this->successResponse("List of Label", [
                    'start' => $start,
                    'limit' => $limit,
                    'total' => $total_row,
                    'data' => $Label
                ]);
            }
            return $this->successResponse("List of Label", $Label);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Label ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function list_old(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $Label = [];
            $Label = CrmLabel::on("mysql_$clientId")->where('status', true)->orderBy('display_order', 'ASC')->get()->all();

            return $this->successResponse("List of Label", $Label);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Label ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function listAffiliates(Request $request, $client_id)
    {
        try {
            $clientId = $client_id;
            $Label = [];
            $Label = CrmLabel::on("mysql_$clientId")->where('status', true)->orderBy('display_order', 'ASC')->get()->all();
            return $this->successResponse("List of Label", $Label);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Label ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Put(
     *     path="/crm-add-label",
     *     summary="Create a new CRM Label",
     *     description="Creates a CRM label with dynamic fields and stores it in the client-specific database.",
     *     tags={"CrmLabel"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "edit_mode", "data_type"},
     *             @OA\Property(property="title", type="string", example="New Label 3"),
     *             @OA\Property(property="edit_mode", type="boolean", example=true),
     *             @OA\Property(property="data_type", type="string", example="text"),
     *             @OA\Property(property="required", type="boolean", example=true),
     *             @OA\Property(property="merchant_required", type="boolean", example=false),
     *             @OA\Property(property="number_length", type="integer", example=10),
     *             @OA\Property(property="icons", type="string", example="fa fa-user"),
     *             @OA\Property(property="heading_type", type="string", example="owner"),
     *             @OA\Property(property="values", type="string", example="option1,option2"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Label created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Label Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique("mysql_" . $request->auth->parent_id . ".crm_label", 'title')
                    ->where(function ($query) use ($request) {
                        $query->whereNull('deleted_at');
                    })
                    ->ignore($request->id), // Assuming $request->id contains the record ID
            ],
            'edit_mode' => 'required|int',
            'data_type' => 'required|string',
        ]);

        try {
            $label_title_url = str_replace(' ', '_', trim(strtolower($request->title)));
            $Label = new CrmLabel();
            $Label->setConnection("mysql_$clientId");
            $Label->title = $request->title;
            $Label->label_title_url = $label_title_url;
            $Label->edit_mode = $request->edit_mode;
            $Label->data_type = $request->data_type;
            $Label->required = $request->required;
            $Label->merchant_required = $request->merchant_required;
            $Label->number_length = $request->number_length;
            $Label->icons = $request->icons;
            $Label->heading_type = $request->heading_type;
            $Label->values = is_array($request->values)
                ? json_encode($request->values)
                : $request->values;
            $Label->placeholder = $request->placeholder;

            $Label->saveOrFail();
            $lastId = $Label->id;

            $Label->column_name   = 'option_' . $lastId;
            $Label->storage_type  = 'eav'; // All new labels use EAV — no ALTER TABLE
            $Label->save();

            return $this->successResponse("Label Added Successfully", $Label->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Label ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/crm-update-label/{id}",
     *     summary="Update an existing CRM Label",
     *     description="Updates a CRM label and optionally modifies the datatype in the lead data table.",
     *     tags={"CrmLabel"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the CRM Label to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title"},
     *             @OA\Property(property="title", type="string", example="Updated Label Title"),
     *             @OA\Property(property="edit_mode", type="boolean", example=true),
     *             @OA\Property(property="data_type", type="string", example="number"),
     *             @OA\Property(property="required", type="boolean", example=true),
     *             @OA\Property(property="merchant_required", type="boolean", example=true),
     *             @OA\Property(property="number_length", type="integer", example=10),
     *             @OA\Property(property="icons", type="string", example="fa fa-user"),
     *             @OA\Property(property="heading_type", type="string", example="owner"),
     *             @OA\Property(property="values", type="string", example="value1,value2")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Label updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Label Updated"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Label Not Found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */

    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;

        // Define the validation rules with the unique rule for title

        $validationRules = [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique("mysql_$clientId.crm_label", 'title')->where(function ($query) use ($clientId) {
                    $query->whereNull('deleted_at');
                })->ignore($id),
            ],
        ];

        $this->validate($request, $validationRules);

        try {
            $label_title_url = str_replace(' ', '_', trim(strtolower($request->input("title"))));
            $Label = CrmLabel::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("title")) {
                $Label->title = $request->input("title");
                $Label->label_title_url = $label_title_url;
            }

            // Your other field updates...
            if ($request->has("data_type")) {
                $newDataType = $request->input("data_type");

                // Update crm_lead_data column datatype
                $columnName = 'option_' . $id; // Assuming you have a consistent naming convention
                $this->updateLeadDataColumnType($clientId, $columnName, $newDataType);

                $Label->data_type = $newDataType;
            }

            if ($request->has("required"))
                $Label->required = $request->input("required");

            if ($request->has("merchant_required"))
                $Label->merchant_required = $request->input("merchant_required");

            if ($request->has("edit_mode"))
                $Label->edit_mode = $request->input("edit_mode");

            if ($request->has("heading_type"))
                $Label->heading_type = $request->input("heading_type");

            if ($request->has("values"))
                $Label->values = is_array($request->input("values"))
                    ? json_encode($request->input("values"))
                    : $request->input("values");
            if ($request->has("placeholder"))
                $Label->placeholder = $request->input("placeholder");
            if ($request->has("icons"))
                $Label->icons = $request->input("icons");
            if ($request->input("data_type") === "number") {
                $Label->number_length = $request->input("number_length");
            } else {
                $Label->number_length = null;
            }

            $Label->saveOrFail();
            return $this->successResponse("Label Updated", $Label->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Label Not Found", ["Invalid Label id $id"], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Label", [$exception->getMessage()], $exception, 404);
        }
    }

    /**
     * Update crm_lead_data column datatype.
     */
    private function updateLeadDataColumnType($clientId, $columnName, $newDataType)
    {
        // Check if the column already exists
        if (!Schema::connection("mysql_$clientId")->hasColumn('crm_lead_data', $columnName)) {
            // You may choose to handle this case differently, for example, skip the update or throw an exception
            return;
        }

        Schema::connection("mysql_$clientId")->table('crm_lead_data', function (Blueprint $table) use ($columnName, $newDataType) {
            // Log or print debug statements
            \Illuminate\Support\Facades\Log::info("Updating column type for $columnName to $newDataType");

            // Modify the existing column to the updated data type
            switch ($newDataType) {
                case "text":
                case "select_option":
                case "email":
                    $table->string($columnName)->nullable()->change();
                    break;

                case "number":
                    $table->string($columnName)->nullable()->change();
                    break;

                //case "number":
                case "phone_number":
                    $table->string($columnName)->nullable()->change();
                    break;
                case "date":
                    $table->date($columnName)->nullable()->change();
                    break;
                default:
                    $table->string($columnName)->nullable()->change();
            }
        });
    }

    /**
     * @OA\Get(
     *     path="/crm-delete-label/{id}",
     *     summary="Delete a CRM Label",
     *     description="Deletes the specified CRM label by ID.",
     *     tags={"CrmLabel"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the CRM Label to delete",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Label deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Label Deleted Successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="boolean", example=true))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Label not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to delete Label"
     *     )
     * )
     */

    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $Label = CrmLabel::on("mysql_$clientId")->find($id)->delete();
            return $this->successResponse("Label Deleted Successfully", [$Label]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Label Name with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Label Name info", [], $exception);
        }
    }
    /**
     * @OA\Post(
     *     path="/crm-change-view-on-lead-status",
     *     summary="Change the visibility of a CRM Label on the Lead page",
     *     description="Updates the `view_on_lead` status of a CRM label.",
     *     tags={"CrmLabel"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"label_id", "view_on_lead"},
     *             @OA\Property(property="label_id", type="integer", example=1, description="ID of the CRM Label"),
     *             @OA\Property(property="view_on_lead", type="boolean", example=true, description="Whether the label should be visible on lead view")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Label updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Label Updated"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Label Not Found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */

    public function changeViewOnLead(Request $request)
    {

        $clientId = $request->auth->parent_id;
        try {
            $Label = CrmLabel::on("mysql_$clientId")->findOrFail($request->label_id);
            $Label->view_on_lead = $request->view_on_lead;
            $Label->saveOrFail();
            return $this->successResponse("Label Updated", $Label->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Label Not Found", [
                "Invalid Label id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Label", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    public function changeLabelStatus(Request $request)
    {
        $clientId = $request->auth->parent_id;
        try {
            $Label = CrmLabel::on("mysql_$clientId")->findOrFail($request->label_id);
            $Label->status = $request->status;
            $Label->saveOrFail();
            return $this->successResponse("Label Updated", $Label->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Label Not Found", [
                "Invalid Label id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Label", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    public function updateDisplayOrder(Request $request)
    {



        $clientId = $request->auth->parent_id;

        $position = $request->ids ?? $request->display_order;



        try {
            $i = 1;
            foreach ($position as $k => $v) {
                $objLead = CrmLabel::on("mysql_$clientId")->findOrFail($v);
                $objLead->display_order = $i;
                $i++;
                $objLead->saveOrFail();
            }
            return $this->successResponse("Label Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Label Not Found", [
                $exception->getMessage()
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update display order", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    // ─── REST-style Lead Fields API (uses crm_labels — new EAV architecture) ──

    /**
     * GET /crm/lead-fields
     * Returns all active field definitions from crm_labels for dynamic form rendering.
     */
    public function leadFieldsList(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $svc      = new LeadFieldService();
            // Return ALL fields (active + inactive) so the frontend can:
            //   1. Use all field_keys to suppress hardcoded fallback fields
            //   2. Filter to status=true for actual rendering
            // This ensures inactive fields are never shown but also never
            // re-injected by hardcoded core-field fallback logic.
            $fields = $svc->getAllFields($clientId)['data'];
            return $this->successResponse("Lead fields retrieved successfully", $fields);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to retrieve lead fields", [$e->getMessage()], $e);
        }
    }

    /**
     * POST /crm/lead-fields
     * Create a new dynamic field in crm_labels.
     *
     * Request body:
     *   label_name  string  required  Display label (e.g. "First Name")
     *   field_key   string  required  Unique key (e.g. "first_name"); used as EAV key in crm_lead_values
     *   field_type  string  required  text|number|email|phone_number|date|textarea|dropdown|checkbox|radio
     *   section     string  optional  owner|contact|business|address|general
     *   options     array   optional  For dropdown/radio/checkbox fields
     *   placeholder string  optional
     *   conditions  mixed   optional  Conditional visibility JSON
     *   required    bool    optional
     *   display_order int   optional
     */
    public function leadFieldsCreate(Request $request)
    {
        $clientId = $request->auth->parent_id;

        $this->validate($request, [
            'label_name'       => ['required', 'string', 'max:255'],
            // 'file' added to support MCA Documents section
            'field_type'       => 'required|string|in:text,number,email,phone_number,date,textarea,dropdown,checkbox,radio,file',
            // field_key is optional — auto-generated from label_name when absent
            'field_key'        => ['sometimes', 'nullable', 'string', 'max:100', 'alpha_dash'],
            'validation_rules' => 'sometimes|nullable|array',
            // apply_to: which public forms show this field (visibility scope)
            // null = no restriction (all forms); 'affiliate', 'merchant', or 'both'
            'apply_to'         => 'sometimes|nullable|in:affiliate,merchant,both',
            // required_in: JSON array of contexts where the field is required
            // null = fall back to legacy `required` boolean; [] = not required anywhere
            'required_in'      => 'sometimes|nullable|array',
            'required_in.*'    => 'string|in:system,affiliate,merchant',
        ]);

        try {
            $svc     = new LeadFieldService();
            $section = trim((string) $request->input('section', ''));

            // ── MCA Auto-Seed (client_id = 3, or any configured MCA client) ───────
            // When a section is provided and the client is an MCA client, insert
            // the full predefined field set for that section instead of one field.
            // Falls through to single-field creation when section is unknown to MCA.
            $mcaClientIds = config('mca_fields.mca_client_ids', [3]);

            if (!empty($section) && in_array((int) $clientId, $mcaClientIds, true)) {
                $result = $svc->seedMcaFields((string) $clientId, $section);

                // seedMcaFields returns non-empty arrays when the section is in the config
                if (!empty($result['created']) || !empty($result['skipped'])) {
                    Log::info("MCA auto-seed completed", [
                        'client_id'     => $clientId,
                        'section'       => $section,
                        'created_count' => count($result['created']),
                        'skipped_count' => count($result['skipped']),
                    ]);

                    return $this->successResponse(
                        "MCA fields created for section: {$section}",
                        [
                            'created'       => $result['created'],
                            'skipped'       => $result['skipped'],
                            'created_count' => count($result['created']),
                            'skipped_count' => count($result['skipped']),
                        ]
                    );
                }
                // Section not found in MCA config — fall through to standard creation
            }

            // ── Standard single-field creation (all other clients / unknown sections) ─
            $baseKey  = preg_replace('/[^a-z0-9]+/', '_',
                            trim(strtolower($request->input('label_name'))));
            $baseKey  = trim($baseKey, '_');
            $fieldKey = $request->input('field_key') ?: $baseKey;

            // Ensure uniqueness — append _2, _3, etc. if collision
            $candidate = $fieldKey;
            $suffix    = 2;
            while (
                DB::connection("mysql_{$clientId}")
                    ->table('crm_labels')
                    ->where('field_key', $candidate)
                    ->exists()
            ) {
                $candidate = $fieldKey . '_' . $suffix++;
            }

            $data              = $request->all();
            $data['field_key'] = $candidate;

            $field = $svc->create($clientId, $data);
            return $this->successResponse("Field created successfully", (array) $field);

        } catch (\Throwable $e) {
            return $this->failResponse("Failed to create field", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead-fields/{id}
     * Update an existing field definition in crm_labels.
     */
    public function leadFieldsUpdate(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;

        $this->validate($request, [
            'label_name'       => 'sometimes|required|string|max:255',
            'field_type'       => 'sometimes|string|in:text,number,email,phone_number,date,textarea,dropdown,checkbox,radio',
            'section'          => 'sometimes|string|max:100',
            'required'         => 'sometimes|boolean',
            'status'           => 'sometimes|boolean',
            'validation_rules' => 'sometimes|nullable|array',
            // apply_to: which public forms show this field (visibility scope)
            'apply_to'         => 'sometimes|nullable|in:affiliate,merchant,both',
            // required_in: JSON array of contexts where the field is required
            'required_in'      => 'sometimes|nullable|array',
            'required_in.*'    => 'string|in:system,affiliate,merchant',
        ]);

        try {
            $svc   = new LeadFieldService();
            $field = $svc->update($clientId, (int) $id, $request->all());
            return $this->successResponse("Field updated successfully", (array) $field);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to update field", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead-fields/reorder
     * Persist a new display_order for ALL fields in one atomic transaction.
     *
     * Request body:
     *   ids  int[]  required  Flat ordered array of crm_labels.id values.
     *                         The array must include every field ID the client
     *                         wants to keep — position is the array index (1-based).
     *
     * Example:
     *   { "ids": [5, 2, 8, 1, 3, 7, 4, 6] }
     *
     * On success the field at ids[0] gets display_order = 1,
     * ids[1] gets display_order = 2, etc.
     */
    public function leadFieldsReorder(Request $request)
    {
        $clientId = $request->auth->parent_id;

        $this->validate($request, [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        try {
            $svc = new LeadFieldService();

            DB::connection("mysql_{$clientId}")->transaction(function () use ($svc, $clientId, $request) {
                $svc->reorder((string) $clientId, $request->ids);
            });

            return $this->successResponse("Field order saved successfully", []);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to save field order", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/lead-fields/suggest-validation
     * Return auto-suggested validation rules for a given field_key / label / type.
     *
     * Query params:
     *   field_key   string  required  EAV field key
     *   label_name  string  optional  Display label (improves matching)
     *   field_type  string  optional  field_type (text|number|email|…)
     */
    public function suggestValidation(Request $request)
    {
        $this->validate($request, [
            'field_key'  => 'required|string|max:100',
            'label_name' => 'sometimes|string|max:255',
            'field_type' => 'sometimes|string|max:50',
        ]);

        $svc   = new ValidationSuggestionService();
        $rules = $svc->suggest(
            (string) $request->input('field_key', ''),
            (string) $request->input('label_name', ''),
            (string) $request->input('field_type', 'text'),
        );

        return $this->successResponse('Validation suggestions', $rules);
    }

    /**
     * DELETE /crm/lead-fields/{id}
     * Hard-delete a field definition and all its stored values.
     */
    public function leadFieldsDelete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $svc = new LeadFieldService();
            $svc->delete($clientId, (int) $id);
            return $this->successResponse("Field deleted successfully", []);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to delete field", [$e->getMessage()], $e, 500);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Model\Client\DocumentTypes;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Http\Helper\Log;

class DocumentTypeController extends Controller
{
    /**
     * @OA\Get(
     *     path="/document-types",
     *     summary="List of Document Types",
     *     tags={"DocumentType"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="start",
     *         in="query",
     *         description="Start index for pagination",
     *         required=false,
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Limit number of records returned",
     *         required=false,
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Retrieve list of document types successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="List of Document Types"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
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
            $group = DocumentTypes::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            if ($request->has('start') && $request->has('limit')) {
                $total_row = count($group);

                $start = (int) $request->input('start');  // Start index (0-based)
                $limit = (int) $request->input('limit');  // Number of records to fetch

                $group = array_slice($group, $start, $limit, false);

                return $this->successResponse("Groups", [
                    'start' => $start,
                    'limit' => $limit,
                    'total' => $total_row,
                    'data' => $group
                ]);
            }

            return $this->successResponse("Groups", $group);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function list_old_code(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;

            $group = [];
            $group = DocumentTypes::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            return $this->successResponse("Groups", $group);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function listByTypeMerchant(Request $request)
    {
        try {
            $clientId = $request->client_id;
            $Documents = [];
            $Documents = DocumentTypes::on("mysql_$clientId")->where('type_title_url', $request->type)->get()->all();
            return $this->successResponse("List of Documents", $Documents);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Documents ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Get(
     *     path="/document-value/{type}",
     *     summary="Get Document Types by type_title_url",
     *     tags={"DocumentType"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         required=true,
     *         description="The type title url of the document type",
     *         @OA\Schema(type="string", example="sales")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of Documents",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="List of Documents"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="Aadhaar Card"),
     *                     @OA\Property(property="type_title_url", type="string", example="aadhaar_card"),
     *                     @OA\Property(
     *                         property="values",
     *                         type="array",
     *                         @OA\Items(type="string"),
     *                         example={"Front", "Back"}
     *                     ),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-16T12:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-16T12:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function listByType(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $Documents = [];
            $Documents = DocumentTypes::on("mysql_$clientId")->where('type_title_url', $request->type)->get()->all();
            return $this->successResponse("List of Documents", $Documents);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Documents ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Put(
     *     path="/document-type",
     *     summary="Create a new Document Type",
     *     tags={"DocumentType"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title"},
     *             @OA\Property(property="title", type="string", example="sales"),
     *             
     * *             @OA\Property(property="values", type="string", example="Jan,Feb,March"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Document Type Added Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Document Type Added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="sales report"),
     *                 @OA\Property(property="type_title_url", type="string", example="sales report"),
     *                 @OA\Property(
     *                     property="values",
     *                     type="array",
     *                     @OA\Items(type="string"),
     *                     example={"Jan", "Feb"}
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
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
            'title' => 'required|string|max:255|unique:' . 'mysql_' . $request->auth->parent_id . '.crm_documents_types',
        ]);
        try {
            $type_title_url = str_replace(' ', '_', trim(strtolower($request->title)));
            $DocumentTypes = new DocumentTypes();
            $DocumentTypes->setConnection("mysql_$clientId");
            $DocumentTypes->title = $request->title;
            $DocumentTypes->type_title_url = $type_title_url;
            $DocumentTypes->values = $request->values;
            $DocumentTypes->saveOrFail();

            return $this->successResponse("Document Type Added Successfully", $DocumentTypes->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Document Type ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


    /**
     * @OA\Post(
     *     path="/update-document-type/{id}",
     *     summary="Create a new Document Type",
     *     tags={"DocumentType"},
     *     security={{"Bearer":{}}},
     *        @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the Document Type to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title"},
     *             @OA\Property(property="title", type="string", example="sales"),
     *             
     * *             @OA\Property(property="values", type="string", example="Jan,Feb,March"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Document Type update Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Document Type Added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="sales report"),
     *                 @OA\Property(property="type_title_url", type="string", example="sales report"),
     *                 @OA\Property(
     *                     property="values",
     *                     type="array",
     *                     @OA\Items(type="string"),
     *                     example={"Jan", "Feb"}
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */


    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;

        // Define the validation rules separately with the unique rule
        $validationRules = [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique("mysql_$clientId.crm_documents_types")->ignore($id),
            ]
        ];

        $this->validate($request, $validationRules);

        try {
            $type_title_url = str_replace(' ', '_', trim(strtolower($request->input("title"))));
            $DocumentTypes = DocumentTypes::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("title")) {
                $DocumentTypes->title = $request->input("title");
                $DocumentTypes->type_title_url = $type_title_url;
            }

            if ($request->has("values")) {
                $DocumentTypes->values = $request->input("values");
            }

            $DocumentTypes->saveOrFail();
            return $this->successResponse("Document Type Updated", $DocumentTypes->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Document Type Not Found", [
                "Invalid Document Type id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Document Type", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    /**
     * @OA\Get(
     *     path="/delete-document-type/{id}",
     *     summary="Delete a specific Document Type by ID",
     *     tags={"DocumentType"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the Document Type to delete",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Document Type deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Document Types Deleted Successfully"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Document Type not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $DocumentTypes = DocumentTypes::on("mysql_$clientId")->find($id)->delete();
            return $this->successResponse("Document Types Deleted Successfully", [$DocumentTypes]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Document Types Name with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Document Types Name info", [], $exception);
        }
    }
    /**
     * @OA\Post(
     *     path="/change-document-type-status",
     *     summary="Change the status of a Document Type",
     *     tags={"DocumentType"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"documenttype_id", "status"},
     *             @OA\Property(property="documenttype_id", type="integer", example=1, description="ID of the Document Type"),
     *             @OA\Property(property="status", type="boolean", example=true, description="New status of the Document Type (true for active, false for inactive)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Document Type status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Document Types Updated"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="sales"),
     *                 @OA\Property(property="status", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Document Type Not Found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */


    public function changeDocumentTypeStatus(Request $request)
    {
        $clientId = $request->auth->parent_id;
        try {
            $DocumentTypes = DocumentTypes::on("mysql_$clientId")->findOrFail($request->documenttype_id);
            $DocumentTypes->status = $request->status;
            $DocumentTypes->saveOrFail();
            return $this->successResponse("Document Types Updated", $DocumentTypes->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Document Types Not Found", [
                "Invalid Group id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Document Types", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
}

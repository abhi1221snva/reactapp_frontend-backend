<?php

namespace App\Http\Controllers;

use App\Model\User;
use Illuminate\Http\Request;

class ContactsController extends Controller
{

    /**
     * @OA\Get(
     *     path="/company-users",
     *     summary="Get all company users",
     *     description="Fetches all users under the same company, excluding the currently authenticated user",
     *     tags={"Contacts"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of company users retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="All users"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=3),
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Doe"),
     *                     @OA\Property(property="extension", type="string", example="101"),
     *                     @OA\Property(property="alt_extension", type="string", example="102"),
     *                     @OA\Property(property="app_extension", type="string", example="APP101"),
     *                     @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *                     @OA\Property(property="avatar", type="string", example="path/to/avatar.jpg")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to load users",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to load users")
     *         )
     *     )
     * )
     */

    public function getCompanyUsers(Request $request)
    {

        $arrCompanyUser = [];
        try {
            //fetch users
            $arrCompanyUser = User::select('id', 'first_name', 'last_name', 'extension', 'email', 'avatar', 'alt_extension', 'app_extension')->where('parent_id', '=', $request->auth->parent_id)->where('id', '!=', $request->auth->id)->where('is_deleted', '=', 0)->orderBy('first_name', 'ASC')->get()->toArray();

            return $this->successResponse("All users", $arrCompanyUser);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to load users", [], $exception);
        }
    }
}

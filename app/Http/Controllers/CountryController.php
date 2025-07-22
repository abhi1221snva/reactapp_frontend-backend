<?php

namespace App\Http\Controllers;

use App\Model\Country;
use App\Model\Master\PhoneCountry;

use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Country $Country)
    {
        $this->request = $request;
        $this->model = $Country;
    }

    /**
     * @OA\POST(
     *     path="/country-list",
     *     summary="Get list of countries",
     *     tags={"Country"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "success": true,
     *                 "data": {
     *                    {"id": 1, "name": "USA"}
     *                 },
     *                 "message": "Countries retrieved successfully"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */
    public function getCountry()
    {
        $response = $this->model->getCountry($this->request);
        return response()->json($response);
    }
    /**
     * @OA\POST(
     *     path="/state-list",
     *     summary="Get states by country ID",
     *     tags={"Country"},
     * security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="country_id",
     *         in="query",
     *         required=true,
     *         description="Country ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "success": true,
     *                 "data": {
     *                     {"id": 10, "name": "Uttar Pradesh"},
     *                     {"id": 11, "name": "Maharashtra"}
     *                 },
     *                 "message": "States retrieved successfully"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "success": false,
     *                 "message": "The id field is required."
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */
    public function getState()
    {
        $this->validate($this->request, [
            'country_id' => 'required|numeric'
        ]);
        $response = $this->model->getState($this->request);
        return response()->json($response);
    }

    /**
     * @OA\POST(
     *     path="/phone-country-list",
     *     summary="Get list of phone countries",
     *     tags={"Country"},
     * security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "success": true,
     *                 "message": "Phone Country List",
     *                 "data": {
     *                     {"id": 1, "name": "India", "phone_code": "+91"},
     *                     {"id": 2, "name": "United States", "phone_code": "+1"}
     *                 }
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "success": false,
     *                 "message": "Something went wrong"
     *             }
     *         )
     *     )
     * )
     */
    public function getPhoneCountry()
    {
        $phone_country = PhoneCountry::on("master")->get()->all();
        return $this->successResponse("Phone Country List", $phone_country);
    }
}

<?php

namespace App\Http\Controllers;

use App\Model\Dest;
use Illuminate\Http\Request;

class DestTypeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Dest $dest)
    {
        $this->request = $request;
        $this->model = $dest;
    }

    /*
     * Fetch Dnc details
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/dest-type",
     *     summary="Get destination type details",
     *     tags={"DestType"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Destination details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dest detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Fax"),
     *                     @OA\Property(property="description", type="string", example="Fax Destination Type"),
     *                     @OA\Property(property="is_deleted", type="integer", example=0)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Destination types not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Dest not created."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */

    public function getDestType()
    {
        $response = $this->model->destDetail($this->request);
        return response()->json($response);
    }
}

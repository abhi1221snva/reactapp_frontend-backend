<?php

namespace App\Http\Controllers;

use App\Model\Master\ModuleComponent;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class ModuleComponentController extends Controller
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
     *     path="/components",
     *     summary="Retrieve Active Modules components List",
     *     tags={"ModuleComponent"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of Active Modules components List ",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Active Modules Components List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Component Name"),
     *                     @OA\Property(property="parent_key", type="string", example="parent_key_value"),
     *                     @OA\Property(property="display_order", type="integer", example=1),
     *                     @OA\Property(property="is_active", type="boolean", example=true)
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
    public function index()
    {
        $moduleComponent = ModuleComponent::on("master")->where('is_active', '=', '1')->orderBy('display_order', 'ASC')->get()->all();
        return $this->successResponse("Active Modules Components List", $moduleComponent);
    }



    /**
     * @OA\Get(
     *     path="/parent-menu",
     *     summary="Retrieve Active Modules Components List",
     *     tags={"ModuleComponent"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of Active Modules Components List ",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Active Modules Components List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Component Name"),
     *                     @OA\Property(property="parent_key", type="string", example="parent_key_value"),
     *                     @OA\Property(property="display_order", type="integer", example=1),
     *                     @OA\Property(property="is_active", type="boolean", example=true)
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
    public function parentMenu()
    {
        $moduleComponent = ModuleComponent::on("master")->where('is_active', '=', '1')->where('parent_key', '=', '')->orderBy('display_order', 'ASC')->get()->all();
        return $this->successResponse("Active Modules Components List", $moduleComponent);
    }

    /**
     * @OA\Get(
     *     path="/sub-menu",
     *     summary="Retrieve Active Modules Components List",
     *     tags={"ModuleComponent"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of Active Modules Components List ",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Active Modules Components List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Component Name"),
     *                     @OA\Property(property="parent_key", type="string", example="parent_key_value"),
     *                     @OA\Property(property="display_order", type="integer", example=1),
     *                     @OA\Property(property="is_active", type="boolean", example=true)
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
    public function subMenu()
    {
        $moduleComponent = ModuleComponent::on("master")->where('is_active', '=', '1')->where('parent_key', '!=', '')->orderBy('display_order', 'ASC')->get()->all();
        return $this->successResponse("Active Modules Components List", $moduleComponent);
    }
}

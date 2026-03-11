<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TenantAware;
use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *      version="1.0",
 *      title="Voiptella"
 * )
 * @OA\SecurityScheme(
 *   securityScheme="Bearer",
 *   type="http",
 *   scheme="bearer",
 *   description="Auth Bearer Token"
 * )
 * @OA\Tag(
 *     name="Authentication",
 *     description="Auth operations"
 * )
 * @OA\Tag(
 *     name="DID",
 *     description="DID operations"
 * )
 * @OA\Tag(
 *     name="Extension Group",
 *     description="Extension group operations"
 * )
 * @OA\Tag(
 *     name="Extensions",
 *     description="Extension operations"
 * )
 * @OA\Tag(
 *     name="Fax",
 *     description="Fax operations"
 * )
 * @OA\Tag(
 *     name="SMS",
 *     description="SMS operations"
 * )
 */
class Controller extends BaseController
{
    use TenantAware;

    protected function successResponse(string $message, array $data = [])
    {
        return response()->json([
            "success" => true,
            "message" => $message,
            "data" => $data
        ]);
    }

    protected function failResponse(string $message, array $errors = [], \Throwable $exception = null, $httpStatus=500)
    {
        if ($exception) {
            Log::error($exception->getMessage(), [
                "file" => $exception->getFile(),
                "line" => $exception->getLine()
            ]);
        }
        if (!is_numeric($httpStatus)) $httpStatus = 500;
        $code = intval($httpStatus / 100);
        if ($code < 2 || $code > 5) $httpStatus = 500;

        return response()->json([
            "success" => false,
            "message" => $message,
            "errors" => $errors
        ], $httpStatus);
    }
}

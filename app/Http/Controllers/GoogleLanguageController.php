<?php

namespace App\Http\Controllers;

use App\Model\Master\GoogleLanguage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class GoogleLanguageController extends Controller
{
    /**
    * Get all languages and voice types
    */
    /**
 * @OA\Post(
 *     path="/get-google-languages",
 *     summary="Get active languages",
 *     description="Fetches the list of all active languages from the GoogleLanguage table.",
 *     tags={"Language"},
 *     security={{"Bearer": {}}},
 *     @OA\Response(
 *         response=200,
 *         description="List of active languages retrieved successfully",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Languages"),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     @OA\Property(property="id", type="integer", example=1),
 *                     @OA\Property(property="language_code", type="string", example="en"),
 *                     @OA\Property(property="language_name", type="string", example="English"),
 *                     @OA\Property(property="status", type="integer", example=1),
 *                     @OA\Property(property="created_at", type="string", format="date-time"),
 *                     @OA\Property(property="updated_at", type="string", format="date-time")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Failed to list Languages",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="boolean", example=false),
 *             @OA\Property(property="message", type="string", example="Failed to list Languages"),
 *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
 *         )
 *     )
 * )
 */

    public function getlanguages()
    {
        try {
            $languages = GoogleLanguage::on("master")->where('status','1')->get()->all();
            return $this->successResponse("Languages", $languages);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list Languages", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
public function getVoiceNameOnLanugage(Request $request)
{
    $arrLang = [];
    $arrGoogleLang = GoogleLanguage::on("master")->where('status', '1')->get();

    foreach ($arrGoogleLang as $lang) {
        $temp = [
            'id' => $lang->id,
            'language' => $lang->language,
            'language_code' => $lang->language_code,
            'voice_name' => $lang->voice_name,
            'ssml_gender' => $lang->ssml_gender,
        ];

        // ✅ Match plain-text language (no base64)
        if (strcasecmp($request->language, $lang->language) === 0) {
            $arrLang[] = $temp;
        }
    }

    return response()->json($arrLang);
}


}

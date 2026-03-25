<?php

namespace App\Http\Controllers;

use App\Model\Client\AudioMessage;

use App\Model\Client\TariffLabelValues;
use App\Services\TenantStorageService;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AudioMessageController extends Controller
{


    /**
     * @OA\Get(
     *     path="/audio-message",
     *     summary="Get Audio Message list",
     *     tags={"AudioMessage"},
     *     security={{"Bearer": {}}},
     * *      @OA\Parameter(
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
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Audio message retrieved successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */

    // public function list(Request $request)
    // {
    //     $audio_message = AudioMessage::on("mysql_" . $request->auth->parent_id)->get()->all();
    //     if ($request->has('start') && $request->has('limit')) {
    //         $start = (int)$request->input('start'); // Start index (0-based)
    //         $limit = (int)$request->input('limit'); // Limit number of records to fetch
    //         $audio_message = array_slice($audio_message, $start, $limit, false);
    //     }
    //     return $this->successResponse("Audio Message List", $audio_message);
    // }
    public function list(Request $request)
{
    $query = AudioMessage::on("mysql_" . $request->auth->parent_id)
        ->orderBy('id', 'desc');

    // 🔎 SEARCH (only if not empty)
    if ($request->filled('search')) {
        $search = $request->input('search');

        $query->where(function ($q) use ($search) {
            $q->where('ivr_id', 'LIKE', "%{$search}%")
              ->orWhere('ann_id', 'LIKE', "%{$search}%")
              ->orWhere('ivr_desc', 'LIKE', "%{$search}%")
              ->orWhere('speech_text', 'LIKE', "%{$search}%")
              ->orWhere('language', 'LIKE', "%{$search}%")
              ->orWhere('voice_name', 'LIKE', "%{$search}%");
        });
    }

    // 📊 TOTAL COUNT (before pagination)
    $total = $query->count();

    // 📌 PAGINATION
    if ($request->has('start') && $request->has('limit')) {

        $start = (int) $request->input('start');
        $limit = (int) $request->input('limit');

        $data = $query->offset($start)
                      ->limit($limit)
                      ->get();

        return $this->successResponse("Audio Message List", [
            'start' => $start,
            'limit' => $limit,
            'total' => $total,
            'data'  => $data
        ]);
    }

    // If no pagination
    $data = $query->get();

    return $this->successResponse("Audio Message List", [
        'total' => $total,
        'data'  => $data
    ]);
}

    public function list_old(Request $request)
    {
        $audio_message = AudioMessage::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("Audio Message List", $audio_message);
    }

    /**
     * @OA\Post(
     *     path="/add-audio-message",
     *     summary="Add a new audio message",
     *     tags={"AudioMessage"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="ann_id", type="string", example="101"),
     *             @OA\Property(property="ivr_id", type="string", example="5001"),
     *             @OA\Property(property="ivr_desc", type="string", example="Welcome IVR"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Joanna"),
     *             @OA\Property(property="speech_text", type="string", example="Thank you for calling."),
     *             @OA\Property(property="prompt_option", type="string", example="text"),
     *             @OA\Property(property="speed", type="string", example="medium"),
     *             @OA\Property(property="pitch", type="string", example="high")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Audio Message created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Audio Message created"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=5),
     *                 @OA\Property(property="ivr_id", type="string", example="5001"),
     *                 @OA\Property(property="ann_id", type="string", example="101"),
     *                 @OA\Property(property="ivr_desc", type="string", example="Welcome IVR"),
     *                 @OA\Property(property="language", type="string", example="en"),
     *                 @OA\Property(property="voice_name", type="string", example="Joanna"),
     *                 @OA\Property(property="speech_text", type="string", example="Thank you for calling."),
     *                 @OA\Property(property="prompt_option", type="string", example="text"),
     *                 @OA\Property(property="speed", type="string", example="medium"),
     *                 @OA\Property(property="pitch", type="string", example="high")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */

    public function addAudioMessage(Request $request)
    {
        $this->validate($request, ['ann_id' => 'string', 'ivr_id'   => 'string', 'ivr_desc'   => 'string']);

        $AudioMessage = new AudioMessage();
        $AudioMessage->setConnection("mysql_" . $request->auth->parent_id);
        $AudioMessage->ivr_id = $request->ivr_id ?? '';
        $AudioMessage->ann_id = $request->ann_id ?? '';
        $AudioMessage->ivr_desc = $request->ivr_desc;
        $AudioMessage->language = $request->language;
        $AudioMessage->voice_name = $request->voice_name;
        $AudioMessage->speech_text = $request->speech_text;
        $AudioMessage->prompt_option = $request->prompt_option;
        $AudioMessage->speed = $request->speed;
        $AudioMessage->pitch = $request->pitch;
        $AudioMessage->save();

        return $this->successResponse("Audio Message  created", $AudioMessage->toArray());
    }

    /**
     * @OA\Post(
     *     path="/edit-audio-message",
     *     summary="Edit Audio Message",
     *     description="Update an existing Audio Message by ID.",
     *     tags={"AudioMessage"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"auto_id"},
     *             @OA\Property(property="auto_id", type="integer", example=1),
     *             @OA\Property(property="ivr_id", type="string", example="ivr_123"),
     *             @OA\Property(property="ann_id", type="string", example="ann_456"),
     *             @OA\Property(property="ivr_desc", type="string", example="Main IVR for support"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Emma"),
     *             @OA\Property(property="speech_text", type="string", example="Welcome to our support line."),
     *             @OA\Property(property="prompt_option", type="string", example="press 1 for sales"),
     *             @OA\Property(property="speed", type="string", example="1.0"),
     *             @OA\Property(property="pitch", type="string", example="1.2")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Audio Message updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Audio Message updated"),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Audio Message not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No Audio Message with id 1"),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Audio Message",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to update Audio Message"),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     )
     * )
     */

    public function generateTts(Request $request)
    {
        $this->validate($request, [
            'speech_text' => 'required|string|max:5000',
            'language'    => 'required|string',
        ]);

        try {
            $clientId  = (int) $request->auth->parent_id;
            $text      = $request->speech_text;
            $langCode  = $request->language;
            $genderRaw = strtoupper($request->voice_gender ?? 'FEMALE');
            $speed     = $this->parseSpeedToFloat($request->speed ?? 'medium');

            $apiKey = env('OPENAI_API_KEY');
            if (empty($apiKey)) {
                return $this->failResponse('TTS API key not configured');
            }

            $http = new GuzzleClient(['timeout' => 30]);

            // Translate text to target language (skip for English variants)
            $translatedText = $this->translateText($text, $langCode, $apiKey, $http);

            // Map gender to OpenAI voice
            $voice = $genderRaw === 'MALE' ? 'onyx' : 'nova';

            $ttsResponse = $http->post('https://api.openai.com/v1/audio/speech', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'           => 'tts-1',
                    'input'           => $translatedText,
                    'voice'           => $voice,
                    'speed'           => $speed,
                    'response_format' => 'mp3',
                ],
            ]);

            TenantStorageService::ensureDirectories($clientId);
            $filename = 'tts_' . time() . '_' . rand(1000, 9999) . '.mp3';
            $destPath = TenantStorageService::getPath($clientId, 'uploads') . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($destPath, $ttsResponse->getBody()->getContents());

            $relativePath = 'uploads/' . $filename;

            return $this->successResponse('TTS audio generated', [
                'relative_path'   => $relativePath,
                'filename'        => $filename,
                'translated_text' => $translatedText,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to generate TTS audio', [], $e);
        }
    }

    private function translateText(string $text, string $langCode, string $apiKey, GuzzleClient $http): string
    {
        // English variants — no translation needed
        if (str_starts_with($langCode, 'en-')) {
            return $text;
        }

        $langName = $this->getLangName($langCode);

        try {
            $response = $http->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role'    => 'system',
                            'content' => "You are a professional translator. Translate the given text to {$langName}. Return ONLY the translated text — no explanations, no quotes, no extra words.",
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'temperature' => 0.2,
                    'max_tokens'  => 2000,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $translated = trim($data['choices'][0]['message']['content'] ?? '');
            return $translated ?: $text;
        } catch (\Throwable $e) {
            return $text; // fallback: use original text
        }
    }

    private function getLangName(string $langCode): string
    {
        $map = [
            'ar-XA' => 'Arabic',
            'zh-CN' => 'Chinese (Simplified)',
            'zh-TW' => 'Chinese (Traditional)',
            'da-DK' => 'Danish',
            'nl-NL' => 'Dutch',
            'en-AU' => 'English',
            'en-IN' => 'English',
            'en-GB' => 'English',
            'en-US' => 'English',
            'fi-FI' => 'Finnish',
            'fr-CA' => 'French',
            'fr-FR' => 'French',
            'de-DE' => 'German',
            'el-GR' => 'Greek',
            'hi-IN' => 'Hindi',
            'id-ID' => 'Indonesian',
            'it-IT' => 'Italian',
            'ja-JP' => 'Japanese',
            'ko-KR' => 'Korean',
            'ms-MY' => 'Malay',
            'nb-NO' => 'Norwegian',
            'pl-PL' => 'Polish',
            'pt-BR' => 'Portuguese (Brazilian)',
            'pt-PT' => 'Portuguese (European)',
            'ro-RO' => 'Romanian',
            'ru-RU' => 'Russian',
            'es-ES' => 'Spanish (Spain)',
            'es-MX' => 'Spanish (Mexican)',
            'es-US' => 'Spanish (American)',
            'sv-SE' => 'Swedish',
            'tr-TR' => 'Turkish',
            'uk-UA' => 'Ukrainian',
            'cy-GB' => 'Welsh',
        ];
        return $map[$langCode] ?? $langCode;
    }

    private function parseSpeedToFloat(string $speed): float
    {
        $map = ['slow' => 0.75, 'medium' => 1.0, 'fast' => 1.5];
        return $map[strtolower(trim($speed))] ?? 1.0;
    }

    public function uploadAudio(Request $request)
    {
        $this->validate($request, [
            'audio' => 'required|file|mimes:mp3,wav,ogg,webm,mp4,m4a|max:20480',
        ]);

        try {
            $clientId     = (int) $request->auth->parent_id;
            $relativePath = TenantStorageService::storeFile($clientId, $request->file('audio'), 'uploads');

            return $this->successResponse("Audio uploaded", [
                'relative_path' => $relativePath,
                'filename'      => basename($relativePath),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to upload audio", [], $e);
        }
    }

    public function deleteAudioMessage(Request $request)
    {
        $this->validate($request, ['auto_id' => 'required|numeric']);

        $clientId = (int) $request->auth->parent_id;
        $id       = (int) $request->auto_id;

        try {
            $msg = AudioMessage::on("mysql_{$clientId}")->where('id', $id)->first();
            if (!$msg) {
                return $this->failResponse("Audio message not found");
            }

            // Delete the stored file if it's a local relative path
            if (!empty($msg->ann_id) && !str_starts_with($msg->ann_id, 'http')) {
                TenantStorageService::deleteFile($clientId, $msg->ann_id);
            }

            AudioMessage::on("mysql_{$clientId}")->where('id', $id)->delete();

            return $this->successResponse("Audio message deleted");
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to delete audio message", [], $e);
        }
    }

    public function ediAudioMessage(Request $request)
    {
        $this->validate($request, ['ann_id' => 'string', 'ivr_id'   => 'string', 'ivr_desc'   => 'string', 'auto_id'        => 'required|numeric']);

        try {
            $id = $request->auto_id;
            $input = [
                'ivr_id' => $request->ivr_id,
                'ann_id' => $request->ann_id,
                'ivr_desc' => $request->ivr_desc,
                'language' => $request->language,
                'voice_name' => $request->voice_name,
                'speech_text' => $request->speech_text,
                'prompt_option' => $request->prompt_option,
                'speed' => $request->speed,
                'pitch' => $request->pitch
            ];

            AudioMessage::on("mysql_" . $request->auth->parent_id)->where('id', $id)->update($input);
            return $this->successResponse("Audio Message updated");
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Audio Message with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Audio Message ", [], $exception);
        }
    }
}

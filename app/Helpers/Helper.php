<?php

use Illuminate\Support\Facades\Log;
use Predis\Client as PredisClient;
use App\Model\User;
use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use Illuminate\Http\Request;
use App\Model\Client\Prompt;
use App\Model\Client\PromptFunction;
use App\Model\SmsTemplete;
use App\Model\Client\EmailTemplete;
use App\Model\Client\Label;
use Carbon\Carbon;

if (!function_exists('getRedisClient')) {
    function getRedisClient(): PredisClient
    {
        $url = env('REDIS_URL');
        if ($url) {
            return new PredisClient($url);
        }
        return new PredisClient([
            'scheme'   => 'tcp',
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD') ?: null,
            'database' => (int) env('REDIS_DB', 0),
        ]);
    }
}

if (!function_exists('convertToUserTimezone')) {
    function convertToUserTimezone($datetime, $timezone = null, $format = 'Y-m-d H:i:s')
    {
        if (empty($datetime)) {
            return null;
        }

        $timezone = $timezone ?? 'Asia/Kolkata';

        return Carbon::parse($datetime)
            ->timezone($timezone)
            ->format($format);
    }
}
if (!function_exists('hhmmss')) {
    function hhmmss($seconds)
    {
        $t = round($seconds);
        return sprintf('%02d:%02d:%02d', ($t / 3600), ($t / 60 % 60), $t % 60);
    }
}

if (!function_exists('now')) {
    function now($tz = null): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now($tz);
    }
}

function buildContext(\Throwable $throwable, array $context = []): array
{
    $context["message"] = $throwable->getMessage();
    $context["file"] = $throwable->getFile();
    $context["line"] = $throwable->getLine();
    $context["code"] = $throwable->getCode();
    buildPrevious($throwable, $context);
    return $context;
}

function buildPrevious(\Throwable $throwable, array &$context, $index = 0)
{
    $previous = $throwable->getPrevious();
    if ($previous) {
        $context["previous.$index"] = [
            "message" => $throwable->getMessage(),
            "file" => $throwable->getFile(),
            "line" => $throwable->getLine(),
            "code" => $throwable->getCode()
        ];
        buildPrevious($previous, $context, $index++);
    }
}


if (!function_exists('recycleLogicLog')) {
    function recycleLogicLog($message, $data = [])
    {
        try {
            $logPath = storage_path('logs/recycle.log');
            $timestamp = date('Y-m-d H:i:s');
            $dataStr = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            $logEntry = "[{$timestamp}] {$message} {$dataStr}" . PHP_EOL;
            file_put_contents($logPath, $logEntry, FILE_APPEND);
        } catch (\Exception $e) {
            // Silently fail if logging fails
        }
    }
}

if (!function_exists('redisDebugLog')) {
    function redisDebugLog($message, $data = [])
    {
        try {
            $logPath = storage_path('logs/redis_debug.log');
            $timestamp = date('Y-m-d H:i:s');
            $dataStr = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            $logEntry = "[{$timestamp}] {$message} {$dataStr}" . PHP_EOL;
            file_put_contents($logPath, $logEntry, FILE_APPEND);
        } catch (\Exception $e) {
            // Silently fail if logging fails to avoid breaking main flow
        }
    }
}

if (!function_exists('externalRedisCacheSet')) {
    function externalRedisCacheSet($client_id, $prompt_id): bool
    {
        redisDebugLog('externalRedisCacheSet: Start', ['client_id' => $client_id, 'prompt_id' => $prompt_id]);

        if (empty($client_id) || empty($prompt_id)) {
            $error = 'Missing required parameters for external Redis cache set';
            Log::error($error, [
                'client_id' => $client_id ?? 'null',
                'prompt_id' => $prompt_id ?? 'null'
            ]);
            redisDebugLog('externalRedisCacheSet: Failed - ' . $error);
            return false;
        }

        $key = "{$client_id}_{$prompt_id}";


        try {
            $prompt = Prompt::on("mysql_" . $client_id)
                ->where('id', $prompt_id)
                ->first();

            if (!$prompt) {
                Log::error('Prompt not found for external Redis cache set', [
                    'client_id' => $client_id,
                    'prompt_id' => $prompt_id
                ]);
                return false;
            }

            /** ✅ Sanitize prompt description - strip HTML tags and decode entities */
            $rawDescription = $prompt->description;
            $cleanDescription = html_entity_decode($rawDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cleanDescription = strip_tags($cleanDescription);
            $cleanDescription = str_replace("\xc2\xa0", ' ', $cleanDescription); // Non-breaking space
            $cleanDescription = preg_replace('/[\x{00A0}\x{200B}]+/u', ' ', $cleanDescription); // Other whitespace chars
            
            // ✅ Normalize tabs - convert tabs to single space
            $cleanDescription = str_replace("\t", ' ', $cleanDescription);
            
            // ✅ Clean up multiple spaces on same line
            $cleanDescription = preg_replace('/ {2,}/', ' ', $cleanDescription);
            
            // ✅ Collapse multiple newlines to max 2 (one blank line)
            $cleanDescription = preg_replace('/\n{3,}/', "\n\n", $cleanDescription);
            
            // ✅ Trim each line and remove trailing spaces
            $cleanDescription = implode("\n", array_map('trim', explode("\n", $cleanDescription)));
            
            // ✅ Remove empty lines that only had whitespace
            $cleanDescription = preg_replace('/\n{3,}/', "\n\n", $cleanDescription);
            
            $prompt->description = trim($cleanDescription);

            // ✅ Fetch raw database fields - show only relevant fields per function type
            // Matches saveFunctions() logic in PromptController
            $functions = PromptFunction::on("mysql_" . $client_id)
                ->where('prompt_id', $prompt_id)
                ->get()
                ->map(function ($fn) {
                    $data = [];

                    switch ($fn->type) {
                        case 'sms':
                        case 'email':
                            $data = [
                                'type'                 => $fn->type,
                                'name'                 => $fn->name,
                                'description'          => $fn->description,
                                'message'              => $fn->message,              // Template ID from DB
                                'did_number'           => $fn->did_number,
                                'function_description' => $fn->function_description, // AI-generated description
                            ];
                            break;

                        case 'call':
                            $data = [
                                'type'                 => 'call',
                                'name'                 => $fn->name,
                                'description'          => $fn->description,
                                'phone'                => $fn->phone,
                                'function_description' => $fn->function_description, // AI-generated description
                            ];
                            break;

                        case 'curl':
                            $data = [
                                'type'                 => 'curl',
                                'name'                 => $fn->name,
                                'description'          => $fn->description,
                                'curl_request'         => $fn->curl_request,
                                'curl_response'        => $fn->curl_response,
                                'function_description' => $fn->function_description, // AI-generated description
                            ];
                            break;

                        case 'api':
                            $data = [
                                'type'                 => 'api',
                                'name'                 => $fn->name,
                                'description'          => $fn->description,
                                'api_method'           => $fn->api_method,
                                'api_url'              => $fn->api_url,
                                'api_body'             => $fn->api_body,             // Raw JSON string
                                'api_response'         => $fn->api_response,         // Raw JSON string
                                'function_description' => $fn->function_description, // AI-generated description
                            ];
                            break;

                        default:
                            // Fallback for unknown types
                            $data = [
                                'type'                 => $fn->type,
                                'name'                 => $fn->name,
                                'description'          => $fn->description,
                                'function_description' => $fn->function_description,
                            ];
                            break;
                    }

                    return $data;
                })
                ->toArray();

            $data = [
                'prompt'    => $prompt->description,
                'functions' => $functions,
            ];

            $value = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($value)) {
                Log::error('External Redis cache set failed: Value is not a string', [
                    'key'        => $key,
                    'value_type' => gettype($value)
                ]);
                return false;
            }

            $success = (bool) getRedisClient()->set($key, $value);
            getRedisClient()->persist($key);

            redisDebugLog('externalRedisCacheSet: Success', ['key' => $key, 'success' => $success, 'value_length' => strlen($value)]);
            return $success;
        } catch (Exception $e) {
            Log::error('External Redis cache set failed', [
                'key'   => "{$client_id}_{$prompt_id}",
                'error' => $e->getMessage()
            ]);
            redisDebugLog('externalRedisCacheSet: Exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

if (!function_exists('externalRedisCacheGet')) {
    function externalRedisCacheGet($client_id, $prompt_id, mixed $default = null): mixed
    {
        $key = "{$client_id}_{$prompt_id}";

        try {
            $value = getRedisClient()->get($key);

            if ($value && is_string($value) && json_decode($value) !== null) {
                $value = json_decode($value, true);
            }

            $hit = $value !== null;
            Log::info('Redis cache get', ['key' => $key, 'hit' => $hit]);
            return $value ?? $default;
        } catch (Exception $e) {
            Log::error('Redis cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $default;
        }
    }
}

if (!function_exists('externalRedisCacheList')) {
    function externalRedisCacheList(?string $searchPattern = null): array
    {
        try {
            // Use search pattern if provided, otherwise get all keys
            $pattern = $searchPattern ? "*{$searchPattern}*" : '*';
            $keys = getRedisClient()->keys($pattern);

            $cacheList = [];
            foreach ($keys as $key) {
                $value = getRedisClient()->get($key);
                if ($value && is_string($value) && json_decode($value) !== null) {
                    $value = json_decode($value, true);
                }
                $cacheList[$key] = $value;
            }

            Log::info('Redis custom cache list fetched', [
                'pattern' => $pattern,
                'count' => count($cacheList)
            ]);

            return $cacheList;
        } catch (Exception $e) {
            Log::error('Redis cache list failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}

if (!function_exists('clientCampaignLeadPromptRedisCacheSet_old')) {
    function clientCampaignLeadPromptRedisCacheSet_old($client_id, $campaign_id, $lead_id, $list_id, $prompt_id, bool $dynamic = false, Request $request = null): bool
    {
        redisDebugLog('clientCampaignLeadPromptRedisCacheSet_old: Start', ['client_id' => $client_id, 'campaign_id' => $campaign_id, 'lead_id' => $lead_id, 'prompt_id' => $prompt_id]);

        if (empty($client_id) || empty($campaign_id) || empty($lead_id) || empty($list_id) || empty($prompt_id)) {
            $error = 'Missing required parameters for Redis cache set';
            Log::error($error, [
                'client_id'   => $client_id ?? 'null',
                'campaign_id' => $campaign_id ?? 'null',
                'lead_id'     => $lead_id ?? 'null',
                'list_id'     => $list_id ?? 'null',
                'prompt_id'   => $prompt_id ?? 'null'
            ]);
            redisDebugLog('clientCampaignLeadPromptRedisCacheSet_old: Failed - ' . $error);
            return false;
        }

        $key         = "{$client_id}_{$campaign_id}_{$lead_id}_{$prompt_id}";
        $prompt_text = '';
        $functions   = [];
        $value       = '';

        try {
            /** ✅ Fetch main prompt */
            $prompt = Prompt::on("mysql_" . $client_id)->where('id', $prompt_id)->first();
            if (!$prompt) {
                Log::error('Prompt not found', ['prompt_id' => $prompt_id]);
                return false;
            }

            /** ✅ Fetch related functions */
            $functions = PromptFunction::on("mysql_" . $client_id)
                ->where('prompt_id', $prompt_id)
                ->get()
                ->map(function ($fn) {
                    return [
                        'type'    => $fn->type,
                        'name'    => $fn->name,
                        'message' => $fn->message, // holds template id or direct message
                        'phone'   => $fn->phone
                    ];
                })
                ->toArray();

            /** ✅ Replace template IDs with actual content */
            if (!empty($functions)) {
                $parent_id = $request->auth->parent_id ?? $client_id;

                // Fetch SMS templates
                $sms_templates = SmsTemplete::on("mysql_" . $parent_id)
                    ->get(['templete_id', 'templete_desc'])
                    ->keyBy('templete_id')
                    ->toArray();

                foreach ($functions as &$fn) {
                    // ✅ Handle SMS template
                    if ($fn['type'] === 'sms' && !empty($fn['message'])) {
                        $tplId = $fn['message'];
                        if (isset($sms_templates[$tplId])) {
                            $tplDesc = $sms_templates[$tplId]['templete_desc'];

                            // Convert {Field} → [[Field]] (SMS only)
                            $tplDesc = preg_replace_callback('/\{([^}]+)\}/', function ($matches) {
                                return '[[' . trim($matches[1]) . ']]';
                            }, $tplDesc);

                            $fn['message'] = $tplDesc; // overwrite with SMS body
                        }
                    }

                    // ✅ Handle Email template (no conversion, already uses [[ ]])
                    if ($fn['type'] === 'email' && !empty($fn['message'])) {
                        $emailTplId = $fn['message'];
                        try {
                            $tpl_record = EmailTemplete::on("mysql_" . $parent_id)
                                ->select('template_html')
                                ->find($emailTplId);

                            if ($tpl_record && !empty($tpl_record->template_html)) {
                                $fn['message'] = $tpl_record->template_html;
                            }
                        } catch (Exception $e) {
                            Log::error('Email template fetch failed', [
                                'template_id' => $emailTplId,
                                'error'       => $e->getMessage()
                            ]);
                        }
                    }
                }
                unset($fn);
            }

            /** ✅ Dynamic replacements if enabled */
            // ✅ Dynamic replacements if enabled
            if ($dynamic) {
                $lead_record = ListData::on("mysql_" . $client_id)->where('id', $lead_id)->first();
                if (!$lead_record) {
                    Log::error('Lead not found', ['lead_id' => $lead_id]);
                    return false;
                }

                $list_headers = ListHeader::on("mysql_" . $client_id)->where("list_id", $list_id)->get();
                $lead_data    = [];

                foreach ($list_headers as $val) {

                    $label = Label::on("mysql_" . $client_id)->where("id", "=", $val->label_id)->first();
                    $lebel_id = $label->title;
                    $lead_data[$lebel_id] = $lead_record->{$val->column_name} ?? '';
                }

                //echo "<pre>";print_r($lead_data);die;

                // --- Minimal added logging: record replacement key=>value pairs and count ---
                Log::info('Lead replacement data prepared', [
                    'prompt_id' => $prompt_id,
                    'client_id' => $client_id,
                    'lead_id'   => $lead_id,
                    'total_pairs' => count($lead_data),
                    'replacement_pairs' => $lead_data, // key => value map to be used for replacements
                ]);
                // ------------------------------------------------------------------------

                // Helper: extract placeholders like [[Field Name]]
                $extractPlaceholders = function ($text) {
                    preg_match_all('/\[\[([^\]]+)\]\]/', $text, $matches);
                    return array_map('trim', $matches[1] ?? []);
                };

                // Replace in main prompt — but first log placeholders and missing ones
                $prompt_text = $prompt->description;
                $prompt_vars = $extractPlaceholders($prompt_text);
                $missing_vars = array_diff($prompt_vars, array_keys($lead_data));

                if (!empty($prompt_vars)) {
                    Log::info('Prompt placeholders found', [
                        'prompt_id' => $prompt_id,
                        'placeholders' => $prompt_vars,
                    ]);
                }

                if (!empty($missing_vars)) {
                    Log::warning('Missing prompt placeholders in lead data', [
                        'prompt_id' => $prompt_id,
                        'missing_fields' => $missing_vars,
                    ]);
                }

                // Replace available variables in prompt
                foreach ($lead_data as $key1 => $val) {
                    $prompt_text = str_replace('[[' . $key1 . ']]', $val, $prompt_text);
                }

                // Check & replace for each function, logging placeholders + missing ones
                foreach ($functions as &$fn) {
                    if (in_array($fn['type'], ['sms', 'email']) && !empty($fn['message'])) {
                        $fn_vars = $extractPlaceholders($fn['message']);
                        $missing_fn_vars = array_diff($fn_vars, array_keys($lead_data));

                        if (!empty($fn_vars)) {
                            Log::info('Function placeholders found', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'type' => $fn['type'],
                                'placeholders' => $fn_vars,
                            ]);
                        }

                        if (!empty($missing_fn_vars)) {
                            Log::warning('Missing function placeholders in lead data', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'missing_fields' => $missing_fn_vars,
                            ]);
                        }

                        // Replace what we have
                        foreach ($lead_data as $key1 => $val) {
                            $fn['message'] = str_replace('[[' . $key1 . ']]', $val, $fn['message']);
                        }

                        // Minimal post-replacement log for this function (optional — remove if noisy)
                        Log::debug('Function message after replacement', [
                            'prompt_id' => $prompt_id,
                            'function_name' => $fn['name'],
                            'type' => $fn['type'],
                            'final_preview' => mb_substr($fn['message'], 0, 100) // store small preview to avoid huge logs
                        ]);
                        // --- Minimal post-replacement check: any remaining [[...]] placeholders? ---
                        $extractPlaceholders = function ($text) {
                            preg_match_all('/\[\[([^\]]+)\]\]/', $text, $matches);
                            return array_map('trim', $matches[1] ?? []);
                        };

                        $leftover = [
                            'prompt' => [],
                            'functions' => []
                        ];

                        // Check prompt
                        $prompt_left = $extractPlaceholders($prompt_text);
                        if (!empty($prompt_left)) {
                            $leftover['prompt'] = $prompt_left;
                            Log::warning('Unreplaced placeholders left in prompt', [
                                'prompt_id' => $prompt_id,
                                'client_id' => $client_id,
                                'leftover_placeholders' => $prompt_left,
                            ]);
                        }

                        // Check functions
                        foreach ($functions as $fnIdx => $fn) {
                            if (in_array($fn['type'], ['sms', 'email']) && !empty($fn['message'])) {
                                $fn_left = $extractPlaceholders($fn['message']);
                                if (!empty($fn_left)) {
                                    $leftover['functions'][] = [
                                        'function_index' => $fnIdx,
                                        'function_name'  => $fn['name'] ?? null,
                                        'type'           => $fn['type'],
                                        'leftover'       => $fn_left,
                                    ];
                                    Log::warning('Unreplaced placeholders left in function message', [
                                        'prompt_id' => $prompt_id,
                                        'client_id' => $client_id,
                                        'function_name' => $fn['name'] ?? null,
                                        'function_type' => $fn['type'],
                                        'leftover_placeholders' => $fn_left,
                                    ]);
                                }
                            }
                        }

                        // (optional) If you want a single summary log as well:
                        if (!empty($leftover['prompt']) || !empty($leftover['functions'])) {
                            Log::notice('Placeholder replacement incomplete summary', [
                                'prompt_id' => $prompt_id,
                                'client_id' => $client_id,
                                'summary' => $leftover,
                            ]);
                        }
                        // --- end post-replacement check ---

                    }
                }
                unset($fn);
            } else {
                $prompt_text = $prompt->description;
            }


            /** ✅ Build Redis payload */
            $data = [
                'prompt'    => $prompt_text,
                'functions' => $functions
            ];
            $value = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($value)) {
                Log::error('Redis cache set failed: Value is not a string', [
                    'key'        => $key,
                    'value_type' => gettype($value)
                ]);
                return false;
            }

            // Clean up and save
            $value = preg_replace('/[[:cntrl:]]/', '', $value);
            $value = trim($value);

            $success = (bool) getRedisClient()->set($key, $value);
            getRedisClient()->persist($key);

            Log::info('Redis multi cache set (forever)', [
                'key'     => $key,
                'success' => $success,
                'dynamic' => $dynamic
            ]);

            redisDebugLog('clientCampaignLeadPromptRedisCacheSet_old: Success', ['key' => $key, 'success' => $success, 'value_length' => strlen($value)]);

            //echo $value;die;

            return $success;
        } catch (Exception $e) {
            Log::error('Redis multi cache set failed', [
                'key'   => $key,
                'error' => $e->getMessage()
            ]);
            redisDebugLog('clientCampaignLeadPromptRedisCacheSet_old: Exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

if (!function_exists('clientCampaignLeadPromptRedisCacheSet_success')) {
    function clientCampaignLeadPromptRedisCacheSet_success($client_id, $campaign_id, $lead_id, $list_id, $prompt_id, bool $dynamic = false, Request $request = null): bool
    {
        redisDebugLog('clientCampaignLeadPromptRedisCacheSet_success: Start', ['client_id' => $client_id, 'campaign_id' => $campaign_id, 'lead_id' => $lead_id, 'prompt_id' => $prompt_id]);

        if (empty($client_id) || empty($campaign_id) || empty($lead_id) || empty($list_id) || empty($prompt_id)) {
            $error = 'Missing required parameters for Redis cache set';
            Log::error($error, [
                'client_id'   => $client_id ?? 'null',
                'campaign_id' => $campaign_id ?? 'null',
                'lead_id'     => $lead_id ?? 'null',
                'list_id'     => $list_id ?? 'null',
                'prompt_id'   => $prompt_id ?? 'null'
            ]);
            redisDebugLog('clientCampaignLeadPromptRedisCacheSet_success: Failed - ' . $error);
            return false;
        }

        $key         = "{$client_id}_{$campaign_id}_{$lead_id}_{$prompt_id}";
        $prompt_text = '';
        $functions   = [];
        $value       = '';

        try {
            /** ✅ Fetch main prompt */
            $prompt = Prompt::on("mysql_" . $client_id)->where('id', $prompt_id)->first();
            if (!$prompt) {
                Log::error('Prompt not found', ['prompt_id' => $prompt_id]);
                return false;
            }

            /** ✅ Fetch related functions */
            $functions = PromptFunction::on("mysql_" . $client_id)
                ->where('prompt_id', $prompt_id)
                ->get()
                ->map(function ($fn) {
                    return [
                        'type'    => $fn->type,
                        'name'    => $fn->name,
                        'message' => $fn->message,
                        'phone'   => $fn->phone
                    ];

                    if ($fn->type === 'api') {
                        $body = json_decode($fn->api_body, true) ?: [];
                        $response = json_decode($fn->api_response, true) ?: [];

                        $data = [
                            'type'        => 'api',
                            'name'        => $fn->name,
                            'description' => $fn->description ?? '',
                            'request' => [
                                'curl' => [
                                    'method'  => $fn->api_method ?? 'POST',
                                    'url'     => $fn->api_url ?? '',
                                    'headers' => [
                                        'Content-Type' => 'application/json'
                                    ],
                                ]
                            ],
                            'response' => $response
                        ];

                        if (strtoupper($fn->api_method) !== 'GET') {
                            $data['request']['curl']['body'] = $body ?: new \stdClass();
                        }
                    }

                    if ($fn->type === 'curl') {
                        $data = [
                            'type'     => 'curl',
                            'name'     => $fn->name,
                            'request'  => normalizeCurlCommand($fn->curl_request ?? ''),
                            'response' => $fn->curl_response ?? null,
                            'curl_ai_description' => $fn->curl_ai_description ?? null,
                        ];
                    }
                })
                ->toArray();

            /** ✅ Replace template IDs with actual content */
            if (!empty($functions)) {
                $parent_id = $request->auth->parent_id ?? $client_id;

                // Fetch SMS templates
                $sms_templates = SmsTemplete::on("mysql_" . $parent_id)
                    ->get(['templete_id', 'templete_desc'])
                    ->keyBy('templete_id')
                    ->toArray();

                foreach ($functions as &$fn) {
                    // ✅ Handle SMS template
                    if ($fn['type'] === 'sms' && !empty($fn['message'])) {
                        $tplId = $fn['message'];
                        if (isset($sms_templates[$tplId])) {
                            $tplDesc = $sms_templates[$tplId]['templete_desc'];

                            // Convert {Field} → [[Field]] (SMS only)
                            $tplDesc = preg_replace_callback('/\{([^}]+)\}/', function ($matches) {
                                return '[[' . trim($matches[1]) . ']]';
                            }, $tplDesc);

                            $fn['message'] = $tplDesc; // overwrite with SMS body
                        }
                    }

                    // ✅ Handle Email template (no conversion, already uses [[ ]])
                    if ($fn['type'] === 'email' && !empty($fn['message'])) {
                        $emailTplId = $fn['message'];
                        try {
                            $tpl_record = EmailTemplete::on("mysql_" . $parent_id)
                                ->select('template_html')
                                ->find($emailTplId);

                            if ($tpl_record && !empty($tpl_record->template_html)) {
                                $fn['message'] = $tpl_record->template_html;
                            }
                        } catch (Exception $e) {
                            Log::error('Email template fetch failed', [
                                'template_id' => $emailTplId,
                                'error'       => $e->getMessage()
                            ]);
                        }
                    }
                }
                unset($fn);
            }

            /** ✅ Dynamic replacements if enabled */
            // ✅ Dynamic replacements if enabled
            if ($dynamic) {
                $lead_record = ListData::on("mysql_" . $client_id)->where('id', $lead_id)->first();
                if (!$lead_record) {
                    Log::error('Lead not found', ['lead_id' => $lead_id]);
                    return false;
                }

                $list_headers = ListHeader::on("mysql_" . $client_id)->where("list_id", $list_id)->get();
                $lead_data    = [];

                foreach ($list_headers as $val) {

                    $label = Label::on("mysql_" . $client_id)->where("id", "=", $val->label_id)->first();
                    $lebel_id = $label->title;
                    $lead_data[$lebel_id] = $lead_record->{$val->column_name} ?? '';
                }

                //echo "<pre>";print_r($lead_data);die;

                // --- Minimal added logging: record replacement key=>value pairs and count ---
                Log::info('Lead replacement data prepared', [
                    'prompt_id' => $prompt_id,
                    'client_id' => $client_id,
                    'lead_id'   => $lead_id,
                    'total_pairs' => count($lead_data),
                    'replacement_pairs' => $lead_data, // key => value map to be used for replacements
                ]);
                // ------------------------------------------------------------------------

                // Helper: extract placeholders like [[Field Name]]
                $extractPlaceholders = function ($text) {
                    preg_match_all('/\[\[([^\]]+)\]\]/', $text, $matches);
                    return array_map('trim', $matches[1] ?? []);
                };

                // Replace in main prompt — but first log placeholders and missing ones
                $prompt_text = $prompt->description;
                $prompt_vars = $extractPlaceholders($prompt_text);
                $missing_vars = array_diff($prompt_vars, array_keys($lead_data));

                if (!empty($prompt_vars)) {
                    Log::info('Prompt placeholders found', [
                        'prompt_id' => $prompt_id,
                        'placeholders' => $prompt_vars,
                    ]);
                }

                if (!empty($missing_vars)) {
                    Log::warning('Missing prompt placeholders in lead data', [
                        'prompt_id' => $prompt_id,
                        'missing_fields' => $missing_vars,
                    ]);
                }

                // Replace available variables in prompt
                foreach ($lead_data as $key1 => $val) {
                    $prompt_text = str_replace('[[' . $key1 . ']]', $val, $prompt_text);
                }

                // Check & replace for each function, logging placeholders + missing ones
                foreach ($functions as &$fn) {
                    if (in_array($fn['type'], ['sms', 'email']) && !empty($fn['message'])) {
                        $fn_vars = $extractPlaceholders($fn['message']);
                        $missing_fn_vars = array_diff($fn_vars, array_keys($lead_data));

                        if (!empty($fn_vars)) {
                            Log::info('Function placeholders found', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'type' => $fn['type'],
                                'placeholders' => $fn_vars,
                            ]);
                        }

                        if (!empty($missing_fn_vars)) {
                            Log::warning('Missing function placeholders in lead data', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'missing_fields' => $missing_fn_vars,
                            ]);
                        }

                        // Replace what we have
                        foreach ($lead_data as $key1 => $val) {
                            $fn['message'] = str_replace('[[' . $key1 . ']]', $val, $fn['message']);
                        }

                        // Minimal post-replacement log for this function (optional — remove if noisy)
                        Log::debug('Function message after replacement', [
                            'prompt_id' => $prompt_id,
                            'function_name' => $fn['name'],
                            'type' => $fn['type'],
                            'final_preview' => mb_substr($fn['message'], 0, 100) // store small preview to avoid huge logs
                        ]);
                        // --- Minimal post-replacement check: any remaining [[...]] placeholders? ---
                        $extractPlaceholders = function ($text) {
                            preg_match_all('/\[\[([^\]]+)\]\]/', $text, $matches);
                            return array_map('trim', $matches[1] ?? []);
                        };

                        $leftover = [
                            'prompt' => [],
                            'functions' => []
                        ];

                        // Check prompt
                        $prompt_left = $extractPlaceholders($prompt_text);
                        if (!empty($prompt_left)) {
                            $leftover['prompt'] = $prompt_left;
                            Log::warning('Unreplaced placeholders left in prompt', [
                                'prompt_id' => $prompt_id,
                                'client_id' => $client_id,
                                'leftover_placeholders' => $prompt_left,
                            ]);
                        }

                        // Check functions
                        foreach ($functions as $fnIdx => $fn) {
                            if (in_array($fn['type'], ['sms', 'email']) && !empty($fn['message'])) {
                                $fn_left = $extractPlaceholders($fn['message']);
                                if (!empty($fn_left)) {
                                    $leftover['functions'][] = [
                                        'function_index' => $fnIdx,
                                        'function_name'  => $fn['name'] ?? null,
                                        'type'           => $fn['type'],
                                        'leftover'       => $fn_left,
                                    ];
                                    Log::warning('Unreplaced placeholders left in function message', [
                                        'prompt_id' => $prompt_id,
                                        'client_id' => $client_id,
                                        'function_name' => $fn['name'] ?? null,
                                        'function_type' => $fn['type'],
                                        'leftover_placeholders' => $fn_left,
                                    ]);
                                }
                            }
                        }

                        // (optional) If you want a single summary log as well:
                        if (!empty($leftover['prompt']) || !empty($leftover['functions'])) {
                            Log::notice('Placeholder replacement incomplete summary', [
                                'prompt_id' => $prompt_id,
                                'client_id' => $client_id,
                                'summary' => $leftover,
                            ]);
                        }
                        // --- end post-replacement check ---

                    }
                }
                unset($fn);
            } else {
                $prompt_text = $prompt->description;
            }


            /** ✅ Build Redis payload */
            $data = [
                'prompt'    => $prompt_text,
                'functions' => $functions
            ];
            $value = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($value)) {
                Log::error('Redis cache set failed: Value is not a string', [
                    'key'        => $key,
                    'value_type' => gettype($value)
                ]);
                return false;
            }

            // Clean up and save
            $value = preg_replace('/[[:cntrl:]]/', '', $value);
            $value = trim($value);

            $success = (bool) getRedisClient()->set($key, $value);
            getRedisClient()->persist($key);

            Log::info('Redis multi cache set (forever)', [
                'key'     => $key,
                'success' => $success,
                'dynamic' => $dynamic
            ]);

            redisDebugLog('clientCampaignLeadPromptRedisCacheSet_success: Success', ['key' => $key, 'success' => $success, 'value_length' => strlen($value)]);

            //echo $value;die;

            return $success;
        } catch (Exception $e) {
            Log::error('Redis multi cache set failed', [
                'key'   => $key,
                'error' => $e->getMessage()
            ]);
            redisDebugLog('clientCampaignLeadPromptRedisCacheSet_success: Exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

if (!function_exists('logRedisCacheSet')) {
    /**
     * Custom logging function for clientCampaignLeadPromptRedisCacheSet_log
     * Writes logs to a separate file in storage/logs/
     */
    function logRedisCacheSet($message, $data = [])
    {
        $logFile = storage_path('logs/redis_cache_set_log.log');
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}";
        
        if (!empty($data)) {
            $logMessage .= " | Data: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        
        $logMessage .= "\n";
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}

if (!function_exists('clientCampaignLeadPromptRedisCacheSet_log')) {
    function clientCampaignLeadPromptRedisCacheSet_log($client_id, $campaign_id, $lead_id, $list_id, $prompt_id, bool $dynamic = false, Request $request = null): bool
    {
        logRedisCacheSet('Function Start', [
            'client_id' => $client_id,
            'campaign_id' => $campaign_id,
            'lead_id' => $lead_id,
            'list_id' => $list_id,
            'prompt_id' => $prompt_id,
            'dynamic' => $dynamic
        ]);
        if (empty($client_id) || empty($campaign_id) || empty($lead_id) || empty($list_id) || empty($prompt_id)) {
            logRedisCacheSet('ERROR: Missing required parameters', [
                'client_id'   => $client_id ?? 'null',
                'campaign_id' => $campaign_id ?? 'null',
                'lead_id'     => $lead_id ?? 'null',
                'list_id'     => $list_id ?? 'null',
                'prompt_id'   => $prompt_id ?? 'null'
            ]);
            Log::error('Missing required parameters for Redis cache set', [
                'client_id'   => $client_id ?? 'null',
                'campaign_id' => $campaign_id ?? 'null',
                'lead_id'     => $lead_id ?? 'null',
                'list_id'     => $list_id ?? 'null',
                'prompt_id'   => $prompt_id ?? 'null'
            ]);
            return false;
        }

        $key         = "{$client_id}_{$campaign_id}_{$lead_id}_{$prompt_id}";
        $prompt_text = '';
        $functions   = [];
        $value       = '';

        logRedisCacheSet('Redis key generated', ['key' => $key]);
        
        try {
            /** ✅ Fetch main prompt */
            logRedisCacheSet('Fetching prompt from database', ['prompt_id' => $prompt_id, 'client_id' => $client_id]);
            $prompt = Prompt::on("mysql_" . $client_id)->where('id', $prompt_id)->first();
            if (!$prompt) {
                logRedisCacheSet('ERROR: Prompt not found', ['prompt_id' => $prompt_id]);
                Log::error('Prompt not found', ['prompt_id' => $prompt_id]);
                return false;
            }
            logRedisCacheSet('Prompt fetched successfully', ['prompt_id' => $prompt_id, 'description_length' => strlen($prompt->description)]);

            /** ✅ Fetch related functions */
            logRedisCacheSet('Fetching prompt functions', ['prompt_id' => $prompt_id]);
            $functions = PromptFunction::on("mysql_" . $client_id)
                ->where('prompt_id', $prompt_id)
                ->get()
                ->map(function ($fn) {
                    return [
                        'type'    => $fn->type,
                        'name'    => $fn->name,
                        'message' => $fn->message,
                        'phone'   => $fn->phone
                    ];

                    if ($fn->type === 'api') {
                        $body = json_decode($fn->api_body, true) ?: [];
                        $response = json_decode($fn->api_response, true) ?: [];

                        $data = [
                            'type'        => 'api',
                            'name'        => $fn->name,
                            'description' => $fn->description ?? '',
                            'request' => [
                                'curl' => [
                                    'method'  => $fn->api_method ?? 'POST',
                                    'url'     => $fn->api_url ?? '',
                                    'headers' => [
                                        'Content-Type' => 'application/json'
                                    ],
                                ]
                            ],
                            'response' => $response
                        ];

                        if (strtoupper($fn->api_method) !== 'GET') {
                            $data['request']['curl']['body'] = $body ?: new \stdClass();
                        }
                    }

                    if ($fn->type === 'curl') {
                        $data = [
                            'type'     => 'curl',
                            'name'     => $fn->name,
                            'request'  => normalizeCurlCommand($fn->curl_request ?? ''),
                            'response' => $fn->curl_response ?? null,
                            'curl_ai_description' => $fn->curl_ai_description ?? null,
                        ];
                    }
                })
                ->toArray();

            logRedisCacheSet('Functions fetched and mapped', ['function_count' => count($functions), 'function_types' => array_column($functions, 'type')]);

            /** ✅ Replace template IDs with actual content */
            if (!empty($functions)) {
                $parent_id = $request->auth->parent_id ?? $client_id;

                // Fetch SMS templates
                $sms_templates = SmsTemplete::on("mysql_" . $parent_id)
                    ->get(['templete_id', 'templete_desc'])
                    ->keyBy('templete_id')
                    ->toArray();

                foreach ($functions as &$fn) {
                    // ✅ Handle SMS template
                    if ($fn['type'] === 'sms' && !empty($fn['message'])) {
                        $tplId = $fn['message'];
                        logRedisCacheSet('Processing SMS template', ['function_name' => $fn['name'], 'template_id' => $tplId]);
                        if (isset($sms_templates[$tplId])) {
                            $tplDesc = $sms_templates[$tplId]['templete_desc'];
                            logRedisCacheSet('SMS template found', ['template_id' => $tplId, 'original_desc' => $tplDesc]);

                            // Convert {Field} → [[Field]] (SMS only)
                            $tplDesc = preg_replace_callback('/\{([^}]+)\}/', function ($matches) {
                                return '[[' . trim($matches[1]) . ']]';
                            }, $tplDesc);

                            $fn['message'] = $tplDesc; // overwrite with SMS body
                            logRedisCacheSet('SMS template converted', ['template_id' => $tplId, 'converted_desc' => $tplDesc]);
                        } else {
                            logRedisCacheSet('WARNING: SMS template not found', ['template_id' => $tplId, 'available_templates' => array_keys($sms_templates)]);
                        }
                    }

                    // ✅ Handle Email template (no conversion, already uses [[ ]])
                    if ($fn['type'] === 'email' && !empty($fn['message'])) {
                        $emailTplId = $fn['message'];
                        logRedisCacheSet('Processing Email template', ['function_name' => $fn['name'], 'template_id' => $emailTplId]);
                        try {
                            $tpl_record = EmailTemplete::on("mysql_" . $parent_id)
                                ->select('template_html')
                                ->find($emailTplId);

                            if ($tpl_record && !empty($tpl_record->template_html)) {
                                $fn['message'] = $tpl_record->template_html;
                                logRedisCacheSet('Email template found', ['template_id' => $emailTplId, 'html_length' => strlen($tpl_record->template_html)]);
                            } else {
                                logRedisCacheSet('WARNING: Email template not found or empty', ['template_id' => $emailTplId, 'record_found' => !is_null($tpl_record)]);
                            }
                        } catch (Exception $e) {
                            logRedisCacheSet('ERROR: Email template fetch exception', ['template_id' => $emailTplId, 'error' => $e->getMessage()]);
                            Log::error('Email template fetch failed', [
                                'template_id' => $emailTplId,
                                'error'       => $e->getMessage()
                            ]);
                        }
                    }
                }
                unset($fn);
            }

            /** ✅ Dynamic replacements if enabled */
            // ✅ Dynamic replacements if enabled
            if ($dynamic) {
                logRedisCacheSet('Dynamic replacement enabled', ['lead_id' => $lead_id, 'list_id' => $list_id]);
                $lead_record = ListData::on("mysql_" . $client_id)->where('id', $lead_id)->first();
                if (!$lead_record) {
                    logRedisCacheSet('ERROR: Lead not found', ['lead_id' => $lead_id, 'client_id' => $client_id]);
                    Log::error('Lead not found', ['lead_id' => $lead_id]);
                    return false;
                }
                logRedisCacheSet('Lead record fetched successfully', ['lead_id' => $lead_id]);

                $list_headers = ListHeader::on("mysql_" . $client_id)->where("list_id", $list_id)->get();
                logRedisCacheSet('List headers fetched', ['list_id' => $list_id, 'header_count' => count($list_headers)]);
                $lead_data    = [];

                foreach ($list_headers as $index => $val) {
                    logRedisCacheSet('Processing list header', ['index' => $index, 'label_id' => $val->label_id, 'column_name' => $val->column_name]);
                    
                    $label = Label::on("mysql_" . $client_id)->where("id", "=", $val->label_id)->first();
                    if (!$label) {
                        logRedisCacheSet('WARNING: Label not found', ['label_id' => $val->label_id]);
                        continue;
                    }
                    $lebel_id = $label->title;
                    $field_value = $lead_record->{$val->column_name} ?? '';
                    $lead_data[$lebel_id] = $field_value;
                    logRedisCacheSet('Lead data field mapped', ['field_name' => $lebel_id, 'column' => $val->column_name, 'value' => $field_value]);
                }

                //echo "<pre>";print_r($lead_data);die;

                logRedisCacheSet('Lead data preparation complete', [
                    'field_count' => count($lead_data), 
                    'fields' => array_keys($lead_data),
                    'sample_values' => array_map(function($v) { return substr($v, 0, 50); }, array_slice($lead_data, 0, 5, true))
                ]);
                
                // --- Minimal added logging: record replacement key=>value pairs and count ---
                Log::info('Lead replacement data prepared', [
                    'prompt_id' => $prompt_id,
                    'client_id' => $client_id,
                    'lead_id'   => $lead_id,
                    'total_pairs' => count($lead_data),
                    'replacement_pairs' => $lead_data, // key => value map to be used for replacements
                ]);
                // ------------------------------------------------------------------------

                // Helper: extract placeholders like [[Field Name]]
                $extractPlaceholders = function ($text) {
                    preg_match_all('/\[\[([^\]]+)\]\]/', $text, $matches);
                    return array_map('trim', $matches[1] ?? []);
                };

                // Replace in main prompt — but first log placeholders and missing ones
                $prompt_text = $prompt->description;
                logRedisCacheSet('Starting prompt text replacement', ['original_length' => strlen($prompt_text), 'text_preview' => substr($prompt_text, 0, 150)]);
                $prompt_vars = $extractPlaceholders($prompt_text);
                $missing_vars = array_diff($prompt_vars, array_keys($lead_data));
                logRedisCacheSet('Prompt placeholders analyzed', ['total' => count($prompt_vars), 'placeholders' => $prompt_vars, 'missing' => $missing_vars]);

                if (!empty($prompt_vars)) {
                    Log::info('Prompt placeholders found', [
                        'prompt_id' => $prompt_id,
                        'placeholders' => $prompt_vars,
                    ]);
                }

                if (!empty($missing_vars)) {
                    Log::warning('Missing prompt placeholders in lead data', [
                        'prompt_id' => $prompt_id,
                        'missing_fields' => $missing_vars,
                    ]);
                }

                // Replace available variables in prompt
                foreach ($lead_data as $key1 => $val) {
                    $prompt_text = str_replace('[[' . $key1 . ']]', $val, $prompt_text);
                }

                // Check & replace for each function, logging placeholders + missing ones
                foreach ($functions as &$fn) {
                    if (in_array($fn['type'], ['sms', 'email']) && !empty($fn['message'])) {
                        $fn_vars = $extractPlaceholders($fn['message']);
                        $missing_fn_vars = array_diff($fn_vars, array_keys($lead_data));

                        if (!empty($fn_vars)) {
                            Log::info('Function placeholders found', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'type' => $fn['type'],
                                'placeholders' => $fn_vars,
                            ]);
                        }

                        if (!empty($missing_fn_vars)) {
                            Log::warning('Missing function placeholders in lead data', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'missing_fields' => $missing_fn_vars,
                            ]);
                        }

                        // Replace what we have
                        foreach ($lead_data as $key1 => $val) {
                            $fn['message'] = str_replace('[[' . $key1 . ']]', $val, $fn['message']);
                        }

                        // Minimal post-replacement log for this function (optional — remove if noisy)
                        Log::debug('Function message after replacement', [
                            'prompt_id' => $prompt_id,
                            'function_name' => $fn['name'],
                            'type' => $fn['type'],
                            'final_preview' => mb_substr($fn['message'], 0, 100) // store small preview to avoid huge logs
                        ]);
                        // --- Minimal post-replacement check: any remaining [[...]] placeholders? ---
                        $extractPlaceholders = function ($text) {
                            preg_match_all('/\[\[([^\]]+)\]\]/', $text, $matches);
                            return array_map('trim', $matches[1] ?? []);
                        };

                        $leftover = [
                            'prompt' => [],
                            'functions' => []
                        ];

                        // Check prompt
                        $prompt_left = $extractPlaceholders($prompt_text);
                        if (!empty($prompt_left)) {
                            $leftover['prompt'] = $prompt_left;
                            Log::warning('Unreplaced placeholders left in prompt', [
                                'prompt_id' => $prompt_id,
                                'client_id' => $client_id,
                                'leftover_placeholders' => $prompt_left,
                            ]);
                        }

                        // Check functions
                        foreach ($functions as $fnIdx => $fn) {
                            if (in_array($fn['type'], ['sms', 'email']) && !empty($fn['message'])) {
                                $fn_left = $extractPlaceholders($fn['message']);
                                if (!empty($fn_left)) {
                                    $leftover['functions'][] = [
                                        'function_index' => $fnIdx,
                                        'function_name'  => $fn['name'] ?? null,
                                        'type'           => $fn['type'],
                                        'leftover'       => $fn_left,
                                    ];
                                    Log::warning('Unreplaced placeholders left in function message', [
                                        'prompt_id' => $prompt_id,
                                        'client_id' => $client_id,
                                        'function_name' => $fn['name'] ?? null,
                                        'function_type' => $fn['type'],
                                        'leftover_placeholders' => $fn_left,
                                    ]);
                                }
                            }
                        }

                        // (optional) If you want a single summary log as well:
                        if (!empty($leftover['prompt']) || !empty($leftover['functions'])) {
                            Log::notice('Placeholder replacement incomplete summary', [
                                'prompt_id' => $prompt_id,
                                'client_id' => $client_id,
                                'summary' => $leftover,
                            ]);
                        }
                        // --- end post-replacement check ---

                    }
                }
                unset($fn);
            } else {
                $prompt_text = $prompt->description;
            }


            /** ✅ Build Redis payload */
            logRedisCacheSet('Building Redis payload', ['prompt_length' => strlen($prompt_text), 'function_count' => count($functions)]);
            $data = [
                'prompt'    => $prompt_text,
                'functions' => $functions
            ];
            $value = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            logRedisCacheSet('JSON encoded', ['value_length' => strlen($value)]);

            if (!is_string($value)) {
                Log::error('Redis cache set failed: Value is not a string', [
                    'key'        => $key,
                    'value_type' => gettype($value)
                ]);
                return false;
            }

            // Clean up and save
            logRedisCacheSet('Cleaning up value');
            $value = preg_replace('/[[:cntrl:]]/', '', $value);
            $value = trim($value);
            logRedisCacheSet('Value cleaned', ['final_length' => strlen($value)]);

            logRedisCacheSet('Setting Redis key', ['key' => $key]);
            $ttlSeconds = 3 * 60 * 60; // 3 hours TTL
            $success = (bool) getRedisClient()->setex($key, $ttlSeconds, $value);
            logRedisCacheSet('Redis operation completed', ['success' => $success, 'ttl_hours' => 3]);

            Log::info('Redis multi cache set (3 hour TTL)', [
                'key'     => $key,
                'success' => $success,
                'dynamic' => $dynamic,
                'ttl_seconds' => $ttlSeconds
            ]);

            //echo $value;die;

            logRedisCacheSet('Function End - Success', ['success' => $success]);
            return $success;
        } catch (Exception $e) {
            logRedisCacheSet('ERROR: Exception caught', ['key' => $key, 'error' => $e->getMessage()]);
            Log::error('Redis multi cache set failed', [
                'key'   => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

if (!function_exists('clientCampaignLeadPromptRedisCacheSet_2')) {
    function clientCampaignLeadPromptRedisCacheSet_2(
        $client_id, $campaign_id, $lead_id, $list_id, $prompt_id,
        bool $dynamic = false, Request $request = null
    ): bool {
        Log::debug('Step 1: Start Redis cache set', compact('client_id', 'campaign_id', 'lead_id', 'list_id', 'prompt_id', 'dynamic'));

        if (empty($client_id) || empty($campaign_id) || empty($lead_id) || empty($list_id) || empty($prompt_id)) {
            Log::error('Step 1.1: Missing required parameters', compact('client_id', 'campaign_id', 'lead_id', 'list_id', 'prompt_id'));
            return false;
        }

        $key = "{$client_id}_{$campaign_id}_{$lead_id}_{$prompt_id}";
        $prompt_text = '';
        $functions = [];
        $value = '';

        try {
            Log::debug('Step 2: Fetching main prompt');
            $prompt = Prompt::on("mysql_" . $client_id)->find($prompt_id);
            if (!$prompt) {
                Log::error('Step 2.1: Prompt not found', compact('prompt_id'));
                return false;
            }
            /** ✅ Sanitize prompt description (same as old logic) */
            $rawDescription = $prompt->description;
            $cleanDescription = html_entity_decode($rawDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cleanDescription = strip_tags($cleanDescription);
            $cleanDescription = str_replace("\xc2\xa0", ' ', $cleanDescription);
            $cleanDescription = preg_replace('/[\x{00A0}\x{200B}]+/u', ' ', $cleanDescription);
            $cleanDescription = implode("\n", array_map('rtrim', explode("\n", $cleanDescription)));

            $prompt->description = trim($cleanDescription);

            Log::debug('Step 3: Fetching related functions');
            $functions = PromptFunction::on("mysql_" . $client_id)
                ->where('prompt_id', $prompt_id)
                ->get()
                ->map(fn($fn) => [
                    'type' => $fn->type,
                    'name' => $fn->name,
                    'message' => $fn->message,
                    'phone' => $fn->phone
                ])->toArray();

            if (!empty($functions)) {
                Log::debug('Step 4: Processing template replacements');
                $parent_id = $request->auth->parent_id ?? $client_id;
                $sms_templates = SmsTemplete::on("mysql_" . $parent_id)
                    ->get(['templete_id', 'templete_desc'])
                    ->keyBy('templete_id')->toArray();

                foreach ($functions as &$fn) {
                    /** ✅ SMS template logic unchanged */
                    if ($fn['type'] === 'sms' && !empty($fn['message'])) {
                        $tplId = $fn['message'];
                        if (isset($sms_templates[$tplId])) {
                            $tplDesc = $sms_templates[$tplId]['templete_desc'];
                            $tplDesc = preg_replace_callback('/\{([^}]+)\}/', fn($m) => '[[' . trim($m[1]) . ']]', $tplDesc);
                            $fn['message'] = $tplDesc;
                        }
                    }

                    /** ✅ Email template logic updated: include subject + body */
                    if ($fn['type'] === 'email' && !empty($fn['message'])) {
                        $emailTplId = $fn['message'];
                        try {
                            $tpl_record = EmailTemplete::on("mysql_" . $parent_id)
                                ->select('subject', 'template_html')
                                ->find($emailTplId);

                            if ($tpl_record && (!empty($tpl_record->template_html) || !empty($tpl_record->subject))) {
                                $fn['message'] = [
                                    'subject' => $tpl_record->subject ?? '',
                                    'body' => $tpl_record->template_html ?? ''
                                ];
                            } else {
                                Log::warning('Step 4.1: Email template found but empty', compact('emailTplId'));
                            }
                        } catch (Exception $e) {
                            Log::error('Step 4.2: Email template fetch failed', [
                                'template_id' => $emailTplId, 'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
                unset($fn);
            }

            if ($dynamic) {
                Log::debug('Step 5: Performing dynamic replacements');
                $lead_record = ListData::on("mysql_" . $client_id)->find($lead_id);
                if (!$lead_record) {
                    Log::error('Step 5.1: Lead not found', compact('lead_id'));
                    return false;
                }

                $list_headers = ListHeader::on("mysql_" . $client_id)->where('list_id', $list_id)->get();
                $lead_data = [];

                foreach ($list_headers as $val) {
                    $label = Label::on("mysql_" . $client_id)->find($val->label_id);
                    $label_title = $label->title ?? null;
                    if ($label_title) $lead_data[$label_title] = $lead_record->{$val->column_name} ?? '';
                }

                Log::debug('Step 5.2: Lead data prepared', [
                    'pairs' => count($lead_data), 'keys' => array_keys($lead_data)
                ]);

                $extractPlaceholders = fn($text) => array_map('trim', (preg_match_all('/\[\[([^\]]+)\]\]/', $text, $m) ? $m[1] : []));

                $prompt_text = $prompt->description;
                $prompt_vars = $extractPlaceholders($prompt_text);
                $missing_vars = array_diff($prompt_vars, array_keys($lead_data));
                Log::debug('Step 5.3: Prompt placeholders', compact('prompt_vars', 'missing_vars'));

                foreach ($lead_data as $k => $v) $prompt_text = str_replace('[[' . $k . ']]', $v, $prompt_text);

                /** ✅ Replacement inside functions */
                foreach ($functions as &$fn) {
                    if ($fn['type'] === 'email' && is_array($fn['message'])) {
                        $fn_message = $fn['message'];
                        foreach (['subject', 'body'] as $field) {
                            foreach ($lead_data as $lk => $lv) {
                                $fn_message[$field] = str_replace('[[' . $lk . ']]', $lv, $fn_message[$field]);
                            }
                        }
                        $fn['message'] = $fn_message;
                        Log::debug('Step 5.4: Email function replaced', [
                            'function' => $fn['name'], 'preview_subject' => mb_substr($fn_message['subject'], 0, 80)
                        ]);
                        continue;
                    }

                    if (in_array($fn['type'], ['sms']) && !empty($fn['message'])) {
                        foreach ($lead_data as $lk => $lv) {
                            $fn['message'] = str_replace('[[' . $lk . ']]', $lv, $fn['message']);
                        }
                    }
                }
                unset($fn);
            } else {
                $prompt_text = $prompt->description;
                Log::debug('Step 6: Static prompt used');
            }

            Log::debug('Step 7: Building Redis payload');
            $data = ['prompt' => $prompt_text, 'functions' => $functions];
            $value = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($value)) {
                Log::error('Step 7.1: Redis value not string', ['type' => gettype($value)]);
                return false;
            }

            $value = preg_replace('/[[:cntrl:]]/', '', $value);
            $value = trim($value);

            Log::debug('Step 8: Writing to Redis', ['key' => $key, 'value' => $value]);
            $ttlSeconds = 3 * 60 * 60; // 3 hours TTL
            $success = (bool) getRedisClient()->setex($key, $ttlSeconds, $value);

            Log::info('Step 9: Redis cache set complete (3 hour TTL)', [
                'key' => $key, 'success' => $success, 'dynamic' => $dynamic, 'ttl_seconds' => $ttlSeconds
            ]);

            return $success;
        } catch (Exception $e) {
            Log::error('Step 10: Exception occurred', [
                'key' => $key, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}

if (!function_exists('clientCampaignLeadPromptRedisCacheSet')) {
    function clientCampaignLeadPromptRedisCacheSet($client_id, $campaign_id, $lead_id, $list_id, $prompt_id, bool $dynamic = false, Request $request = null): bool
    {
        if (empty($client_id) || empty($campaign_id) || empty($lead_id) || empty($list_id) || empty($prompt_id)) {
            Log::error('Missing required parameters for Redis cache set', [
                'client_id'   => $client_id ?? 'null',
                'campaign_id' => $campaign_id ?? 'null',
                'lead_id'     => $lead_id ?? 'null',
                'list_id'     => $list_id ?? 'null',
                'prompt_id'   => $prompt_id ?? 'null'
            ]);
            return false;
        }

        $key         = "{$client_id}_{$campaign_id}_{$lead_id}_{$prompt_id}";
        $prompt_text = '';
        $functions   = [];
        $value       = '';

        try {
            /** ✅ Fetch main prompt */
            $prompt = Prompt::on("mysql_" . $client_id)->where('id', $prompt_id)->first();
            if (!$prompt) {
                Log::error('Prompt not found', ['prompt_id' => $prompt_id]);
                return false;
            }

            /** ✅ Sanitize prompt description - strip HTML tags and decode entities */
            $rawDescription = $prompt->description;
            $cleanDescription = html_entity_decode($rawDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cleanDescription = strip_tags($cleanDescription);
            $cleanDescription = str_replace("\xc2\xa0", ' ', $cleanDescription); // Non-breaking space
            $cleanDescription = preg_replace('/[\x{00A0}\x{200B}]+/u', ' ', $cleanDescription); // Other whitespace chars
            
            // ✅ Normalize tabs - convert tabs to single space
            $cleanDescription = str_replace("\t", ' ', $cleanDescription);
            
            // ✅ Clean up multiple spaces on same line
            $cleanDescription = preg_replace('/ {2,}/', ' ', $cleanDescription);
            
            // ✅ Collapse multiple newlines to max 2 (one blank line)
            $cleanDescription = preg_replace('/\n{3,}/', "\n\n", $cleanDescription);
            
            // ✅ Trim each line and remove trailing spaces
            $cleanDescription = implode("\n", array_map('trim', explode("\n", $cleanDescription)));
            
            // ✅ Remove empty lines that only had whitespace
            $cleanDescription = preg_replace('/\n{3,}/', "\n\n", $cleanDescription);
            
            $prompt->description = trim($cleanDescription);

            /** ✅ Fetch related functions */
            $functions = PromptFunction::on("mysql_" . $client_id)
    ->where('prompt_id', $prompt_id)
    ->get()
    ->map(function ($fn) {

        $curlRequest  = $fn->curl_request;
        $curlResponse = $fn->curl_response;

        if ($fn->type === 'curl' && !empty($curlRequest)) {
            $curlRequest = normalizeCurlCommand($curlRequest);
        }

        $data = [
            'type'          => $fn->type,
            'name'          => $fn->name,
            'message'       => $fn->message,
            'phone'         => $fn->phone,
            'curl_request'  => $curlRequest,
            'curl_response' => $curlResponse,
            'function_description' => $fn->function_description ?? '',
            'api_method'    => $fn->api_method,
            'api_url'       => $fn->api_url,
            'api_body'      => $fn->api_body,
            'api_response'  => $fn->api_response,
        ];

        // ✅ Normalize old JSON-style curl (if exists)
        if ($fn->type === 'curl' && !empty($fn->curl_request)) {
            $decoded = json_decode($fn->curl_request, true);
            if (is_array($decoded) && isset($decoded['request'])) {

                $req = $decoded['request'];

                if (str_contains($req, '\\"')) {
                    $req = json_decode('"' . $req . '"');
                }

                $req = str_replace(["\r\n", "\n", "\r", "\\"], ' ', $req);
                $req = preg_replace('/\s+/', ' ', trim($req));

                $data['curl_request']  = $req;
                $data['curl_response'] = $decoded['response'] ?? null;
            }
        }

        return $data;
    })
    ->toArray();


            /** ✅ Replace template IDs with actual content */
            if (!empty($functions)) {
                $parent_id = $request->auth->parent_id ?? $client_id;

                // Fetch SMS templates
                $sms_templates = SmsTemplete::on("mysql_" . $parent_id)
                    ->get(['templete_id', 'templete_desc'])
                    ->keyBy('templete_id')
                    ->toArray();

                foreach ($functions as &$fn) {
                    // ✅ Handle SMS template
                    if ($fn['type'] === 'sms' && !empty($fn['message'])) {
                        $tplId = $fn['message'];
                        if (isset($sms_templates[$tplId])) {
                            $tplDesc = $sms_templates[$tplId]['templete_desc'];

                            // Convert {Field} → [[Field]] (SMS only)
                            $tplDesc = preg_replace_callback('/\{([^}]+)\}/', function ($matches) {
                                return '[[' . trim($matches[1]) . ']]';
                            }, $tplDesc);

                            $fn['message'] = $tplDesc; // overwrite with SMS body
                        }
                    }

                    // ✅ Handle Email template (no conversion, already uses [[ ]])
                    if ($fn['type'] === 'email' && !empty($fn['message'])) {
                        $emailTplId = $fn['message'];
                        try {
                            $tpl_record = EmailTemplete::on("mysql_" . $parent_id)
                                ->select('template_html')
                                ->find($emailTplId);

                            if ($tpl_record && !empty($tpl_record->template_html)) {
                                $fn['message'] = $tpl_record->template_html;
                            }
                        } catch (Exception $e) {
                            Log::error('Email template fetch failed', [
                                'template_id' => $emailTplId,
                                'error'       => $e->getMessage()
                            ]);
                        }
                    }
                }
                unset($fn);
            }

            /** ✅ Dynamic replacements if enabled */
            // ✅ Dynamic replacements if enabled
            if ($dynamic) {
                $lead_record = ListData::on("mysql_" . $client_id)->where('id', $lead_id)->first();
                if (!$lead_record) {
                    Log::error('Lead not found', ['lead_id' => $lead_id]);
                    return false;
                }

                $list_headers = ListHeader::on("mysql_" . $client_id)->where("list_id", $list_id)->get();
                $lead_data    = [];

                foreach ($list_headers as $val) {

                    $label = Label::on("mysql_" . $client_id)->where("id", "=", $val->label_id)->first();
                    $lebel_id = $label->title;
                    $lead_data[$lebel_id] = $lead_record->{$val->column_name} ?? '';
                }

                //echo "<pre>";print_r($lead_data);die;

                // --- Minimal added logging: record replacement key=>value pairs and count ---
                Log::info('Lead replacement data prepared', [
                    'prompt_id' => $prompt_id,
                    'client_id' => $client_id,
                    'lead_id'   => $lead_id,
                    'total_pairs' => count($lead_data),
                    'replacement_pairs' => $lead_data, // key => value map to be used for replacements
                ]);
                // ------------------------------------------------------------------------

                // Helper: extract placeholders like [[Field Name]]
                $extractPlaceholders = function ($text) {
                    preg_match_all('/\[\[([^\]]+)\]\]/', $text, $matches);
                    return array_map('trim', $matches[1] ?? []);
                };

                // Replace in main prompt — but first log placeholders and missing ones
                $prompt_text = $prompt->description;
                $prompt_vars = $extractPlaceholders($prompt_text);
                $missing_vars = array_diff($prompt_vars, array_keys($lead_data));

                if (!empty($prompt_vars)) {
                    Log::info('Prompt placeholders found', [
                        'prompt_id' => $prompt_id,
                        'placeholders' => $prompt_vars,
                    ]);
                }

                if (!empty($missing_vars)) {
                    Log::warning('Missing prompt placeholders in lead data', [
                        'prompt_id' => $prompt_id,
                        'missing_fields' => $missing_vars,
                    ]);
                }

                // Replace available variables in prompt
                foreach ($lead_data as $key1 => $val) {
                    $prompt_text = str_replace('[[' . $key1 . ']]', $val, $prompt_text);
                }

                // Check & replace for each function, logging placeholders + missing ones
                foreach ($functions as &$fn) {
                    // Handle SMS and Email message replacements
                    if (in_array($fn['type'], ['sms', 'email']) && !empty($fn['message'])) {
                        $fn_vars = $extractPlaceholders($fn['message']);
                        $missing_fn_vars = array_diff($fn_vars, array_keys($lead_data));

                        if (!empty($fn_vars)) {
                            Log::info('Function placeholders found', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'type' => $fn['type'],
                                'placeholders' => $fn_vars,
                            ]);
                        }

                        if (!empty($missing_fn_vars)) {
                            Log::warning('Missing function placeholders in lead data', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'missing_fields' => $missing_fn_vars,
                            ]);
                        }

                        // Replace what we have
                        foreach ($lead_data as $key1 => $val) {
                            $fn['message'] = str_replace('[[' . $key1 . ']]', $val, $fn['message']);
                        }

                        // Minimal post-replacement log for this function (optional — remove if noisy)
                        Log::debug('Function message after replacement', [
                            'prompt_id' => $prompt_id,
                            'function_name' => $fn['name'],
                            'type' => $fn['type'],
                            'final_preview' => mb_substr($fn['message'], 0, 100) // store small preview to avoid huge logs
                        ]);
                    }

                    // Handle cURL request replacements
                    if ($fn['type'] === 'curl') {
                        $curl_fields_to_replace = [];
                        
                        // Check curl_request field
                        if (!empty($fn['curl_request'])) {
                            $curl_vars = $extractPlaceholders($fn['curl_request']);
                            $curl_fields_to_replace['curl_request'] = $curl_vars;
                        }
                        
                        // Check api_body field
                        if (!empty($fn['api_body'])) {
                            $api_body_vars = $extractPlaceholders($fn['api_body']);
                            $curl_fields_to_replace['api_body'] = $api_body_vars;
                        }

                        // Check api_url field
                        if (!empty($fn['api_url'])) {
                            $api_url_vars = $extractPlaceholders($fn['api_url']);
                            $curl_fields_to_replace['api_url'] = $api_url_vars;
                        }

                        // Collect all unique placeholders
                        $all_curl_vars = [];
                        foreach ($curl_fields_to_replace as $field_vars) {
                            $all_curl_vars = array_merge($all_curl_vars, $field_vars);
                        }
                        $all_curl_vars = array_unique($all_curl_vars);

                        if (!empty($all_curl_vars)) {
                            $missing_curl_vars = array_diff($all_curl_vars, array_keys($lead_data));

                            Log::info('cURL function placeholders found', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                'type' => $fn['type'],
                                'placeholders' => $all_curl_vars,
                                'fields' => array_keys($curl_fields_to_replace),
                            ]);

                            if (!empty($missing_curl_vars)) {
                                Log::warning('Missing cURL placeholders in lead data', [
                                    'prompt_id' => $prompt_id,
                                    'function_name' => $fn['name'],
                                    'missing_fields' => $missing_curl_vars,
                                ]);
                            }

                            // Replace placeholders in curl_request
                            if (isset($fn['curl_request'])) {
                                foreach ($lead_data as $key1 => $val) {
                                    $fn['curl_request'] = str_replace('[[' . $key1 . ']]', $val, $fn['curl_request']);
                                }
                            }

                            // Replace placeholders in api_body
                            if (isset($fn['api_body'])) {
                                foreach ($lead_data as $key1 => $val) {
                                    $fn['api_body'] = str_replace('[[' . $key1 . ']]', $val, $fn['api_body']);
                                }
                            }

                            // Replace placeholders in api_url
                            if (isset($fn['api_url'])) {
                                foreach ($lead_data as $key1 => $val) {
                                    $fn['api_url'] = str_replace('[[' . $key1 . ']]', $val, $fn['api_url']);
                                }
                            }

                            Log::debug('cURL function after replacement', [
                                'prompt_id' => $prompt_id,
                                'function_name' => $fn['name'],
                                 'type' => $fn['type'],
                                'curl_preview' => mb_substr($fn['curl_request'] ?? '', 0, 100),
                                'api_body_preview' => mb_substr($fn['api_body'] ?? '', 0, 100),
                            ]);
                        }
                    }
                }
                unset($fn);

                // --- Minimal post-replacement check: any remaining [[...]] placeholders? ---
                $leftover = [
                    'prompt' => [],
                    'functions' => []
                ];

                // Check prompt
                $prompt_left = $extractPlaceholders($prompt_text);
                if (!empty($prompt_left)) {
                    $leftover['prompt'] = $prompt_left;
                    Log::warning('Unreplaced placeholders left in prompt', [
                        'prompt_id' => $prompt_id,
                        'client_id' => $client_id,
                        'leftover_placeholders' => $prompt_left,
                    ]);
                }

                // Check functions
                foreach ($functions as $fnIdx => $fn) {
                    $fn_left = [];
                    
                    // Check SMS/Email message field
                    if (in_array($fn['type'], ['sms', 'email']) && !empty($fn['message'])) {
                        $fn_left = $extractPlaceholders($fn['message']);
                    }
                    
                    // Check cURL fields
                    if ($fn['type'] === 'curl') {
                        $curl_left = [];
                        if (!empty($fn['curl_request'])) {
                            $curl_left = array_merge($curl_left, $extractPlaceholders($fn['curl_request']));
                        }
                        if (!empty($fn['api_body'])) {
                            $curl_left = array_merge($curl_left, $extractPlaceholders($fn['api_body']));
                        }
                        if (!empty($fn['api_url'])) {
                            $curl_left = array_merge($curl_left, $extractPlaceholders($fn['api_url']));
                        }
                        $fn_left = array_unique($curl_left);
                    }
                    
                    if (!empty($fn_left)) {
                        $leftover['functions'][] = [
                            'function_index' => $fnIdx,
                            'function_name'  => $fn['name'] ?? null,
                            'type'           => $fn['type'],
                            'leftover'       => $fn_left,
                        ];
                        Log::warning('Unreplaced placeholders left in function', [
                            'prompt_id' => $prompt_id,
                            'client_id' => $client_id,
                            'function_name' => $fn['name'] ?? null,
                            'function_type' => $fn['type'],
                            'leftover_placeholders' => $fn_left,
                        ]);
                    }
                }

                // (optional) If you want a single summary log as well:
                if (!empty($leftover['prompt']) || !empty($leftover['functions'])) {
                    Log::notice('Placeholder replacement incomplete summary', [
                        'prompt_id' => $prompt_id,
                        'client_id' => $client_id,
                        'summary' => $leftover,
                    ]);
                }
                // --- end post-replacement check ---

            } else {
                $prompt_text = $prompt->description;
            }


            /** ✅ Build Redis payload */
            $data = [
                'prompt'    => $prompt_text,
                'functions' => $functions
            ];
            $value = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (!is_string($value)) {
                Log::error('Redis cache set failed: Value is not a string', [
                    'key'        => $key,
                    'value_type' => gettype($value)
                ]);
                return false;
            }

            // Clean up and save
            $value = preg_replace('/[[:cntrl:]]/', '', $value);
            $value = trim($value);

            $ttlSeconds = 3 * 60 * 60; // 3 hours TTL
            $success = (bool) getRedisClient()->setex($key, $ttlSeconds, $value);

            Log::info('Redis multi cache set (3 hour TTL)', [
                'key'     => $key,
                'success' => $success,
                'dynamic' => $dynamic,
                'ttl_seconds' => $ttlSeconds
            ]);

            return $success;
        } catch (Exception $e) {
            Log::error('Redis multi cache set failed', [
                'key'   => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

/**
 * Get Redis storage information including memory usage details
 * 
 * @return array Redis storage information with success status
 */
if (!function_exists('getRedisStorageInfo')) {
    function getRedisStorageInfo(): array
    {
        try {
            // Get Redis connection and info
            $redis = app('redis')->connection();
            $info = $redis->info('memory');
            
            // Parse memory information
            $usedMemory = isset($info['used_memory']) ? $info['used_memory'] : 0;
            $usedMemoryHuman = isset($info['used_memory_human']) ? $info['used_memory_human'] : '0B';
            $maxMemory = isset($info['maxmemory']) ? $info['maxmemory'] : 0;
            $maxMemoryHuman = isset($info['maxmemory_human']) ? $info['maxmemory_human'] : 'unlimited';
            
            // Calculate available memory and percentage
            if ($maxMemory > 0) {
                $availableMemory = $maxMemory - $usedMemory;
                $usagePercentage = round(($usedMemory / $maxMemory) * 100, 2);
                $availableMemoryHuman = formatBytes($availableMemory);
            } else {
                $availableMemory = 'unlimited';
                $availableMemoryHuman = 'unlimited';
                $usagePercentage = 0;
            }
            
            Log::info('Redis storage info retrieved', [
                'used_memory' => $usedMemory,
                'max_memory' => $maxMemory,
                'usage_percentage' => $usagePercentage
            ]);
            
            return [
                'success' => true,
                'message' => 'Redis storage information retrieved successfully',
                'data' => [
                    'used_memory' => $usedMemory,
                    'used_memory_human' => $usedMemoryHuman,
                    'max_memory' => $maxMemory,
                    'max_memory_human' => $maxMemoryHuman,
                    'available_memory' => $availableMemory,
                    'available_memory_human' => $availableMemoryHuman,
                    'usage_percentage' => $usagePercentage,
                    'total_keys' => $redis->dbsize(),
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Failed to retrieve Redis storage information', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to retrieve Redis storage information',
                'error' => $e->getMessage()
            ];
        }
    }
}

/**
 * Format bytes to human-readable format
 * 
 * @param int $bytes Number of bytes
 * @param int $precision Decimal precision
 * @return string Formatted string with unit (B, KB, MB, GB, TB)
 */
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
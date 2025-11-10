<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Model\User;
use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use Illuminate\Http\Request;
use App\Model\Client\Prompt;
use App\Model\Client\PromptFunction;
use App\Model\SmsTemplete;
use App\Model\Client\EmailTemplete;
use App\Model\Client\Label;


if (!function_exists('hhmmss')) {
    function hhmmss($seconds)
    {
        $t = round($seconds);
        return sprintf('%02d:%02d:%02d', ($t / 3600), ($t / 60 % 60), $t % 60);
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

if (!function_exists('externalRedisCacheSet')) {
    function externalRedisCacheSet($client_id, $prompt_id): bool
    {
        if (empty($client_id) || empty($prompt_id)) {
            Log::error('Missing required parameters for external Redis cache set', [
                'client_id' => $client_id ?? 'null',
                'prompt_id' => $prompt_id ?? 'null'
            ]);
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

            $functions = PromptFunction::on("mysql_" . $client_id)
                ->where('prompt_id', $prompt_id)
                ->get()
                ->map(function ($fn) use ($client_id) {
                    $data = [
                        'type'    => $fn->type,
                        'name'    => $fn->name,
                        'message' => $fn->message,
                        'phone'   => $fn->phone,
                    ];

                    // ✅ SMS
                    if ($fn->type === 'sms' && !empty($fn->message)) {
                        $template = SmsTemplete::on("mysql_" . $client_id)
                            ->where('templete_id', $fn->message)
                            ->first(['templete_desc']);
                        if ($template) {
                            $data['message'] = $template->templete_desc;
                        }
                    }

                    if ($fn->type === 'curl') {
                        $data = [
                            'type'     => 'curl',
                            'name'     => $fn->name,
                            'request'  => $fn->curl_request ?? null,
                            'response' => $fn->curl_response ?? null,
                        ];
                    }

                    if ($fn->type === 'api') {
                        $body = null;
                        $decodedBody = json_decode($fn->api_body, true);
                        if (is_array($decodedBody) && !empty($decodedBody)) {
                            $body = $decodedBody;
                        }

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
                                ],
                            ],
                            'response' => $response,
                        ];

                        // Only attach body if method != GET
                        if (strtoupper($fn->api_method) !== 'GET') {
                            $data['request']['curl']['body'] = $body ?: new \stdClass();
                        }
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

            $success = (bool) Redis::set($key, $value);
            Redis::persist($key);

            Log::info('External Redis cache set (forever)', [
                'key'        => $key,
                'success'    => $success,
                'prompt_id'  => $prompt_id,
                'client_id'  => $client_id,
                'data'       => $data,
            ]);

            return $success;
        } catch (Exception $e) {
            Log::error('External Redis cache set failed', [
                'key'   => "{$client_id}_{$prompt_id}",
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}

if (!function_exists('externalRedisCacheGet')) {
    function externalRedisCacheGet($client_id, $prompt_id, mixed $default = null): mixed
    {
        $key = "{$client_id}_{$prompt_id}";

        try {
            $value = Redis::get($key);

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
    function externalRedisCacheList(): array
    {
        try {
            $keys = Redis::keys('*');

            $cacheList = [];
            foreach ($keys as $key) {
                $value = Redis::get($key);
                if ($value && is_string($value) && json_decode($value) !== null) {
                    $value = json_decode($value, true);
                }
                $cacheList[$key] = $value;
            }

            Log::info('Redis custom cache list fetched', [
                'pattern' => '*',
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

            $success = (bool) Redis::set($key, $value);
            Redis::persist($key);

            Log::info('Redis multi cache set (forever)', [
                'key'     => $key,
                'success' => $success,
                'dynamic' => $dynamic
            ]);

            //echo $value;die;

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
                            'request'  => $fn->curl_request ?? null,
                            'response' => $fn->curl_response ?? null,
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

            $success = (bool) Redis::set($key, $value);
            Redis::persist($key);

            Log::info('Redis multi cache set (forever)', [
                'key'     => $key,
                'success' => $success,
                'dynamic' => $dynamic
            ]);

            //echo $value;die;

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
            $success = (bool) Redis::set($key, $value);
            Redis::persist($key);

            Log::info('Step 9: Redis cache set complete', [
                'key' => $key, 'success' => $success, 'dynamic' => $dynamic
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

if (!function_exists('clientCampaignLeadPromptRedisCacheSet_3')) {
    function clientCampaignLeadPromptRedisCacheSet_3($client_id, $campaign_id, $lead_id, $list_id, $prompt_id, bool $dynamic = false, Request $request = null): bool
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

            /** ✅ Fetch related functions */
            $functions = PromptFunction::on("mysql_" . $client_id)
                ->where('prompt_id', $prompt_id)
                ->get()
                ->map(function ($fn) {
                    $data = [
                        'type'         => $fn->type,
                        'name'         => $fn->name,
                        'message'      => $fn->message,
                        'phone'        => $fn->phone,
                        'curl_request' => $fn->curl_request,
                        'curl_response'=> $fn->curl_response,
                        'api_method'   => $fn->api_method,
                        'api_url'      => $fn->api_url,
                        'api_body'     => $fn->api_body,
                        'api_response' => $fn->api_response,
                    ];

                    // Normalize CURL data if old style
                    if ($fn->type === 'curl' && !empty($fn->curl_request)) {
                        $decoded = json_decode($fn->curl_request, true);
                        if (is_array($decoded) && isset($decoded['request'])) {
                            $data['curl_request']  = $decoded['request'];
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

            $success = (bool) Redis::set($key, $value);
            Redis::persist($key);

            Log::info('Redis multi cache set (forever)', [
                'key'     => $key,
                'success' => $success,
                'dynamic' => $dynamic
            ]);

            //echo $value;die;

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

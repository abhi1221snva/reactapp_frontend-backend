<?php

namespace App\Http\Controllers;

use App\Model\Client\Prompt;
use App\Model\User;
use App\Model\Client\PromptFunction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PromptController extends Controller
{
    /**
     * Sanitize prompt description - strip HTML tags and decode entities
     * 
     * @param string|null $description Raw description from DB
     * @return string Cleaned description
     */
    private function sanitizeDescription($description)
    {
        if (empty($description)) {
            return '';
        }
        
        $clean = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = strip_tags($clean);
        $clean = str_replace("\xc2\xa0", ' ', $clean); // Non-breaking space
        $clean = preg_replace('/[\x{00A0}\x{200B}]+/u', ' ', $clean); // Other whitespace chars
        
        // ✅ Normalize tabs - convert tabs to single space
        $clean = str_replace("\t", ' ', $clean);
        
        // ✅ Clean up multiple spaces on same line
        $clean = preg_replace('/ {2,}/', ' ', $clean);
        
        // ✅ Collapse multiple newlines to max 2 (one blank line)
        $clean = preg_replace('/\n{3,}/', "\n\n", $clean);
        
        // ✅ Trim each line and remove trailing spaces
        $clean = implode("\n", array_map('trim', explode("\n", $clean)));
        
        // ✅ Remove empty lines that only had whitespace
        $clean = preg_replace('/\n{3,}/', "\n\n", $clean);
        
        return trim($clean);
    }

    // ✅ Get all prompts for logged-in user
    // public function index(Request $request)
    // {
    //     $userId = $request->user()->id ?? $request->header('user-id');
    //     $clientId = User::where('id', $userId)->value('parent_id');
    //     $prompts = Prompt::on("mysql_$clientId")->with('functions')
    //         ->latest()
    //         ->get();

    //     return response()->json([
    //         'success' => true,
    //         'message' => count($prompts) ? 'Prompts retrieved successfully' : 'No prompts found',
    //         'data' => $prompts
    //     ]);
    // }
public function index(Request $request)
{
    $userId = $request->user()->id ?? $request->header('user-id');
    $clientId = User::where('id', $userId)->value('parent_id');

    $start     = $request->input('start');
    $limit     = $request->input('limit');
    $search    = $request->input('search');

    // Base query
    $query = Prompt::on("mysql_$clientId")->with('functions')->latest();

    // Total rows before search
    $totalRows = $query->count();

    // Apply search
    if (!empty($search)) {
        $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
              ->orWhere('initial_greeting', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%")
            ->orWhere('voice_name', 'like', "%{$search}%");

        });
    }

    // Total after search
    $filteredRows = $query->count();

    // Apply pagination
    if ($start !== null && $limit !== null) {
        $query->skip($start)->take($limit);
    }

    $prompts = $query->get();

    // ✅ Sanitize description for each prompt before sending
    $prompts->transform(function ($prompt) {
        $prompt->description = $this->sanitizeDescription($prompt->description);
        return $prompt;
    });

    return response()->json([
        'success'         => true,
        'message'         => $prompts->count() ? 'Prompts retrieved successfully' : 'No prompts found',
        // 'total'           => $totalRows,
        // 'filtered'        => $filteredRows,
        'total_rows'           => $prompts->count(),
        'data'            => $prompts
    ]);
}


    // ✅ Store new prompt
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'initial_greeting' => 'nullable|string',
            'voice_name' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $userId = $request->user()->id ?? $request->header('user-id');
        $clientId = User::where('id', $userId)->value('parent_id');
        $prompt = Prompt::on("mysql_$clientId")->create([
            'user_id' => $userId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'initial_greeting' => $validated['initial_greeting'] ?? null,
            'voice_name' => $validated['voice_name'],
        ]);

        externalRedisCacheSet($clientId, $prompt->id);

        return response()->json([
            'success' => true,
            'message' => 'Prompt created successfully',
            'data' => $prompt
        ], 201);
    }

    // ✅ Show single prompt with functions
    public function show(Request $request, $id)
    {
        $userId = $request->user()->id ?? $request->header('user-id');
        $clientId = User::where('id', $userId)->value('parent_id');
        $prompt = Prompt::on("mysql_$clientId")->where('id', $id)
            ->first();

        if (!$prompt) {
            return response()->json([
                'success' => false,
                'message' => 'Prompt not found',
                'data' => null
            ], 404);
        }

        // Get all functions manually
        $functions = PromptFunction::on("mysql_$clientId")->where('prompt_id', $id)->get();

        // Return combined response
        return response()->json([
            'success' => true,
            'message' => 'Prompt and functions retrieved successfully',
            'data' => [
                'prompt' => $prompt,
                'functions' => $functions
            ]
        ]);
    }

    // ✅ Optional: separate endpoint just for functions (manual method)
    public function getPromptFunctions(Request $request, $id)
    {
        $userId = $request->user()->id ?? $request->header('user-id');
        $clientId = User::where('id', $userId)->value('parent_id');
        $prompt = Prompt::on("mysql_$clientId")->where('id', $id)
            ->first();

        if (!$prompt) {
            return response()->json([
                'success' => false,
                'message' => 'Prompt not found',
                'data' => null
            ], 404);
        }

        $functions = PromptFunction::on("mysql_$clientId")->where('prompt_id', $id)
            ->where('user_id', $userId)
            ->get();

        return response()->json([
            'success' => true,
            'message' => count($functions) ? 'Functions retrieved successfully' : 'No functions found',
            'data' => $functions
        ]);
    }

    // ✅ Update prompt
    public function update(Request $request, $id)
    {
        $userId = $request->user()->id ?? $request->header('user-id');
        $clientId = User::where('id', $userId)->value('parent_id');
        $prompt = Prompt::on("mysql_$clientId")->where('id', $id)
            ->first();

        if (!$prompt) {
            return response()->json([
                'success' => false,
                'message' => 'Prompt not found',
                'data' => null
            ], 404);
        }


        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'initial_greeting' => 'nullable|string',
            'voice_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $prompt->update($validated);

        externalRedisCacheSet($clientId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Prompt updated successfully',
            'data' => $prompt
        ]);
    }

    // ✅ Delete prompt (and all its functions)
    public function destroy(Request $request, $id)
    {
        $userId = $request->user()->id ?? $request->header('user-id');
        $clientId = User::where('id', $userId)->value('parent_id');
        $prompt = Prompt::on("mysql_$clientId")->where('id', $id)
            ->first();

        if (!$prompt) {
            return response()->json([
                'success' => false,
                'message' => 'Prompt not found',
                'data' => null
            ], 404);
        }

        $prompt->functions()->delete();
        $prompt->delete();

        return response()->json([
            'success' => true,
            'message' => 'Prompt deleted successfully',
            'data' => null
        ]);
    }

    public function saveFunctions(Request $request, $id)
    {
        $userId = $request->user()->id ?? $request->header('user-id');
        $clientId = User::where('id', $userId)->value('parent_id');

        $prompt = Prompt::on("mysql_$clientId")->where('id', $id)->first();
        if (!$prompt) {
            return response()->json([
                'success' => false,
                'message' => 'Prompt not found',
                'data' => null
            ], 404);
        }

        $functions = $request->input('functions', []);
        $existingIds = [];
        $errors = [];

        foreach ($functions as $index => $func) {
            $funcErrors = [];

            // Basic validation
            if (empty($func['type']) || empty($func['name'])) {
                $funcErrors[] = "Function #$index must have type and name";
            } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $func['name'])) {
                $funcErrors[] = "Function #$index name must contain only letters, numbers, underscores";
            }

            // Default structure
            $data = [
                'type' => $func['type'] ?? null,
                'name' => $func['name'] ?? null,
                'description' => $func['description'] ?? null,
                'message' => null,
                'did_number' => null,
                'phone' => null,
                'curl_request' => null,
                'curl_response' => null,
                'function_description' => null,
                'api_method' => null,
                'api_url' => null,
                'api_body' => null,
                'api_response' => null,
            ];

            switch ($func['type']) {
                case 'sms':
                case 'email':
                    $data['message'] = $func['message'] ?? null;
                    $data['did_number'] = $func['did_number'] ?? null;
                    
                    // Auto-generate description
                    if (empty($func['function_description']) && !empty($data['message'])) {
                        $data['function_description'] = $this->generateFunctionDescription(
                            $func['type'],
                            $data
                        );
                    } else {
                        $data['function_description'] = $func['function_description'] ?? null;
                    }
                    break;

                case 'call':
                    $data['phone'] = $func['phone'] ?? null;
                    
                    // Auto-generate description
                    if (empty($func['function_description']) && !empty($data['phone'])) {
                        $data['function_description'] = $this->generateFunctionDescription(
                            $func['type'],
                            $data
                        );
                    } else {
                        $data['function_description'] = $func['function_description'] ?? null;
                    }
                    break;

                case 'curl':
                    $data['curl_request'] = $func['curl_request'] ?? null;
                    $data['curl_response'] = $func['curl_response'] ?? null;
                    
                    // Validate cURL format
                    if (!empty($data['curl_request'])) {
                        $curlValidation = $this->validateCurlFormat($data['curl_request']);
                        if (!$curlValidation['valid']) {
                            $funcErrors[] = "Function #$index (cURL): " . $curlValidation['error'];
                        }
                    }
                    
                    // Auto-generate description
                    if (empty($func['function_description']) && !empty($data['curl_request']) && !empty($data['curl_response'])) {
                        $data['function_description'] = $this->generateFunctionDescription(
                            $func['type'],
                            $data
                        );
                    } else {
                        $data['function_description'] = $func['function_description'] ?? null;
                    }
                    break;

                case 'api':
                    $data['api_method'] = $func['api_method'] ?? null;
                    $data['api_url'] = $func['api_url'] ?? null;
                    $data['api_body'] = $func['api_body'] ?? null;
                    $data['api_response'] = $func['api_response'] ?? null;

                    // Validate required fields
                    if (empty($data['api_method']) || empty($data['api_url'])) {
                        $funcErrors[] = "Function #$index (API/Webhook) requires both method and URL";
                    }

                    // Skip body validation for GET requests
                    if (strtoupper($data['api_method']) !== 'GET') {
                        if (!empty($data['api_body'])) {
                            json_decode($data['api_body']);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                $funcErrors[] = "Function #$index (api_body) must be valid JSON";
                            }
                        }
                    } else {
                        $data['api_body'] = null; // force clear for GET
                    }

                    if (!empty($data['api_response'])) {
                        json_decode($data['api_response']);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $funcErrors[] = "Function #$index (api_response) must be valid JSON";
                        }
                    }

                    // Auto-generate description
                    if (empty($func['function_description']) && !empty($data['api_method']) && !empty($data['api_url'])) {
                        $data['function_description'] = $this->generateFunctionDescription(
                            $func['type'],
                            $data
                        );
                    } else {
                        $data['function_description'] = $func['function_description'] ?? null;
                    }
                    break;
            }

            // Skip save if errors found
            if (!empty($funcErrors)) {
                $errors = array_merge($errors, $funcErrors);
                continue;
            }

            // Update existing
            if (!empty($func['id'])) {
                $existingFunc = PromptFunction::on("mysql_$clientId")
                    ->where('id', $func['id'])
                    ->where('prompt_id', $id)
                    ->first();

                if ($existingFunc) {
                    $existingFunc->update($data);
                    $existingIds[] = $existingFunc->id;
                    continue;
                }
            }

            // Create new
            $newFunc = PromptFunction::on("mysql_$clientId")->create(array_merge($data, [
                'prompt_id' => $id,
                'user_id' => $userId,
            ]));

            $existingIds[] = $newFunc->id;
        }

        // Return validation errors if any
        if (!empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors occurred',
                'errors' => $errors
            ], 400);
        }

        // Cleanup deleted ones
        PromptFunction::on("mysql_$clientId")
            ->where('prompt_id', $id)
            ->whereNotIn('id', $existingIds)
            ->delete();

        // Refresh data
        $allFunctions = PromptFunction::on("mysql_$clientId")
            ->where('prompt_id', $id)
            ->get();

        externalRedisCacheSet($clientId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Functions saved successfully',
            'data' => $allFunctions
        ]);
    }

    /**
     * Generate AI description for any function type using OpenAI API
     * 
     * @param string $type Function type (sms, email, call, curl, api)
     * @param array $functionData Function configuration data
     * @return string|null Generated description or null on failure
     */
    private function generateFunctionDescription($type, $functionData)
    {
        try {
            $apiKey = env('OPENAI_API_KEY');
            
            if (!$apiKey) {
                return null; // Skip if no API key configured
            }

            // Build type-specific prompt
            $prompt = $this->buildPromptForType($type, $functionData);
            
            if (!$prompt) {
                return null; // No prompt available for this type
            }

            $url = "https://api.openai.com/v1/chat/completions";
            
            $headers = [
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json"
            ];

            $postData = json_encode([
                "model" => "gpt-4",
                "messages" => [
                    ["role" => "system", "content" => "You are an AI function documentation expert who helps other AI agents understand when to call specific functions."],
                    ["role" => "user", "content" => $prompt]
                ],
                "temperature" => 0.3
            ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                curl_close($ch);
                return null; // Fail silently
            }

            curl_close($ch);
            
            $result = json_decode($response, true);
            return $result['choices'][0]['message']['content'] ?? null;
            
        } catch (\Exception $e) {
            // Log error but don't fail the function save
            \Log::error("Failed to generate function description", [
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Build type-specific prompts for OpenAI
     * 
     * @param string $type Function type
     * @param array $data Function data
     * @return string|null Prompt or null if type not supported
     */
    private function buildPromptForType($type, $data)
    {
        $baseInstruction = "Based on this configuration, provide a concise description (2-3 sentences) that explains:
1. What this function does
2. When an AI agent should call this function during a conversation
3. What the expected outcome or data will be

Provide ONLY plain text. No headings, bullet points, or formatting.";

        switch ($type) {
            case 'sms':
                return "Analyze this SMS function configuration.

Message Template:
{$data['message']}

DID Number (Sender): {$data['did_number']}

{$baseInstruction}";

            case 'email':
                return "Analyze this Email function configuration.

Email Message Template:
{$data['message']}

DID Number (Sender): {$data['did_number']}

{$baseInstruction}";

            case 'call':
                return "Analyze this Call function configuration.

Phone Number to Call: {$data['phone']}

{$baseInstruction}";

            case 'curl':
                return "Analyze this cURL API request and its expected response.

cURL Request:
{$data['curl_request']}

Expected Response Format:
{$data['curl_response']}

{$baseInstruction}";

            case 'api':
                $bodyText = !empty($data['api_body']) ? $data['api_body'] : 'None';
                $responseText = !empty($data['api_response']) ? $data['api_response'] : 'Not specified';
                
                return "Analyze this API/Webhook configuration.

HTTP Method: {$data['api_method']}
URL: {$data['api_url']}
Request Body: {$bodyText}
Expected Response: {$responseText}

{$baseInstruction}";

            default:
                return null;
        }
    }

    /**
     * Validate cURL request format - basic syntax check
     * 
     * @param string $curlRequest The cURL command to validate
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private function validateCurlFormat($curlRequest)
    {
        // Normalize the curl command (remove line breaks and extra spaces for easier parsing)
        $normalizedCurl = preg_replace('/\\\\\s*\n\s*/', ' ', $curlRequest);
        $normalizedCurl = preg_replace('/\s+/', ' ', trim($normalizedCurl));
        
        // Check if it starts with 'curl'
        if (!preg_match('/^curl\s/i', $normalizedCurl)) {
            return [
                'valid' => false,
                'error' => 'Invalid cURL syntax: command must start with "curl"'
            ];
        }
        
        // Check if there's a URL present (either with --location, --url, or just a URL)
        if (!preg_match('/https?:\/\/[^\s\'"]+/i', $normalizedCurl)) {
            return [
                'valid' => false,
                'error' => 'Invalid cURL syntax: URL not found in the command'
            ];
        }
        
        // If there's --data or --data-raw with JSON body, validate the JSON
        if (preg_match('/--data(-raw)?\s+[\'"]?(\{.*\})[\'"]?/is', $normalizedCurl, $matches)) {
            $jsonBody = $matches[2];
            $cleanedJson = stripslashes($jsonBody);
            json_decode($cleanedJson);
            if (json_last_error() !== JSON_ERROR_NONE) {
                json_decode($jsonBody);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'valid' => false,
                        'error' => 'Invalid cURL syntax: JSON body is malformed - ' . json_last_error_msg()
                    ];
                }
            }
        }
        
        return [
            'valid' => true,
            'error' => null
        ];
    }
}

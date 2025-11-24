<?php

namespace App\Http\Controllers;

use App\Model\Client\Prompt;
use App\Model\User;
use App\Model\Client\PromptFunction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PromptController extends Controller
{
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

        //externalRedisCacheSet($clientId, $prompt->id);

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

        //externalRedisCacheSet($clientId, $id);

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
                'phone' => null,
                'curl_request' => null,
                'curl_response' => null,
                'api_method' => null,
                'api_url' => null,
                'api_body' => null,
                'api_response' => null,
            ];

            switch ($func['type']) {
                case 'sms':
                case 'email':
                    $data['message'] = $func['message'] ?? null;
                    break;

                case 'call':
                    $data['phone'] = $func['phone'] ?? null;
                    break;

                case 'curl':
                    $data['curl_request'] = $func['curl_request'] ?? null;
                    $data['curl_response'] = $func['curl_response'] ?? null;
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

       // externalRedisCacheSet($clientId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Functions saved successfully',
            'data' => $allFunctions
        ]);
    }
}

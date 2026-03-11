<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Predis\Client as PredisClient;

class RedisCacheController extends Controller
{
    private function getRedis(): PredisClient
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

    /**
     * List all Redis cache entries with pagination and search support
     * 
     * URL Examples:
     * - GET /list-all-cache                              → All entries (paginated, latest first)
     * - GET /list-all-cache?page=2&per_page=100          → Page 2 with 100 items per page
     * - GET /list-all-cache?search=62_                   → Keys containing "62_" (e.g., 62_1_21724_4)
     * - GET /list-all-cache?search=_21724                → Keys containing "_21724" (e.g., 62_1_21724_4)
     * - GET /list-all-cache?search=_4                    → Keys containing "_4" (e.g., 62_1_21724_4)
     * - GET /list-all-cache?search=prompt&page=1         → Combine search with pagination
     * 
     * Search Pattern: The search parameter uses wildcards (*search*), so you can search for:
     * - Prefixes: "62_" matches "62_1_21724_4", "62_2_99999_1"
     * - Suffixes: "_4" matches "62_1_21724_4", "123_456_789_4"
     * - Middle segments: "_21724" matches "62_1_21724_4", "99_5_21724_8"
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listAllCache(Request $request): JsonResponse
    {
        try {
            // Get query parameters
            $search = $request->query('search', null);
            $page = (int) $request->query('page', 1);
            $perPage = (int) $request->query('per_page', 50);

            // Validate pagination parameters
            $page = max(1, $page);
            $perPage = max(1, min(500, $perPage)); // Limit max per_page to 500

            // Build Redis pattern based on search
            $pattern = $search ? "*{$search}*" : '*';

            $redis = $this->getRedis();

            // Fetch keys from Redis
            $keys = $redis->keys($pattern);

            // Fetch and decode cache entries
            $allCache = [];
            foreach ($keys as $key) {
                $value = $redis->get($key);
                if ($value && is_string($value) && json_decode($value) !== null) {
                    $value = json_decode($value, true);
                }
                $allCache[$key] = $value;
            }

            $totalCount = count($allCache);

            // Sort by keys in reverse order (latest first)
            krsort($allCache);

            // Calculate pagination
            $totalPages = $totalCount > 0 ? ceil($totalCount / $perPage) : 0;
            $offset = ($page - 1) * $perPage;

            // Slice the array for pagination
            $paginatedCache = array_slice($allCache, $offset, $perPage, true);

            return response()->json([
                'success' => true,
                'message' => 'Cached entries retrieved successfully',
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_items' => $totalCount,
                    'total_pages' => $totalPages,
                    'has_next' => $page < $totalPages,
                    'has_previous' => $page > 1
                ],
                'search' => $search,
                'data' => $paginatedCache
            ]);
        } catch (\Exception $e) {
            \Log::error('Redis cache list failed', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve cache entries',
                'error' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * Delete one or more Redis cache keys to free up storage space
     * 
     * URL Examples:
     * - DELETE /delete-cache?key=62_1_21724_4                    → Delete single key
     * - DELETE /delete-cache?keys[]=62_1_21724_4&keys[]=62_2_1_5 → Delete multiple keys
     * - POST /delete-cache (JSON body: {"key": "62_1_21724_4"})  → Delete single key via POST
     * - POST /delete-cache (JSON body: {"keys": ["key1", "key2"]}) → Delete multiple keys via POST
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteCache(Request $request): JsonResponse
    {
        try {
            // Get keys from query params or request body
            $key = $request->input('key');
            $keys = $request->input('keys', []);

            // Normalize to array
            if ($key && empty($keys)) {
                $keys = [$key];
            }

            // Validate that we have keys to delete
            if (empty($keys) || !is_array($keys)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No keys provided for deletion. Use "key" for single deletion or "keys" array for multiple deletions.',
                    'examples' => [
                        'single' => '?key=62_1_21724_4',
                        'multiple' => '?keys[]=62_1_21724_4&keys[]=62_2_1_5',
                        'json_single' => '{"key": "62_1_21724_4"}',
                        'json_multiple' => '{"keys": ["62_1_21724_4", "62_2_1_5"]}'
                    ]
                ], 400);
            }

            $deletedKeys = [];
            $notFoundKeys = [];
            $protectedKeys = [];
            $freedMemory = 0;
            $redis = $this->getRedis();

            // Delete each key
            foreach ($keys as $keyToDelete) {
                // Protection: Do not delete keys with only 2 segments (format: 23_23)
                // Only delete keys with 3+ segments (format: 23_232_23232_23)
                $segmentCount = substr_count($keyToDelete, '_') + 1;

                if ($segmentCount <= 2) {
                    $protectedKeys[] = $keyToDelete;
                    continue; // Skip this key
                }

                // Check if key exists and get its memory usage before deletion
                if ($redis->exists($keyToDelete)) {
                    // Get memory usage of the key (approximate)
                    $value = $redis->get($keyToDelete);
                    $memoryUsed = strlen(serialize($value));

                    // Delete the key
                    $result = $redis->del($keyToDelete);

                    if ($result > 0) {
                        $deletedKeys[] = $keyToDelete;
                        $freedMemory += $memoryUsed;
                    }
                } else {
                    $notFoundKeys[] = $keyToDelete;
                }
            }

            $totalRequested = count($keys);
            $totalDeleted = count($deletedKeys);
            $totalNotFound = count($notFoundKeys);
            $totalProtected = count($protectedKeys);

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Deletion completed: %d deleted, %d protected (2-segment keys)',
                    $totalDeleted,
                    $totalProtected
                ),
                'summary' => [
                    'requested_count' => $totalRequested,
                    'deleted_count' => $totalDeleted,
                    'protected_count' => $totalProtected,
                    'not_found_count' => $totalNotFound,
                    'freed_memory_bytes' => $freedMemory,
                    'freed_memory_kb' => round($freedMemory / 1024, 2),
                    'freed_memory_mb' => round($freedMemory / (1024 * 1024), 2)
                ],
                'deleted_keys' => $deletedKeys,
                'protected_keys' => $protectedKeys,
                'not_found_keys' => $notFoundKeys
            ]);
        } catch (\Exception $e) {
            \Log::error('Redis cache deletion failed', [
                'error' => $e->getMessage(),
                'keys' => $keys ?? []
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete cache entries',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete Redis cache entries older than specified time
     * 
     * URL Examples:
     * - POST /delete-cache-by-age?hours=24                    → Delete entries older than 24 hours
     * - POST /delete-cache-by-age?days=7                      → Delete entries older than 7 days
     * - POST /delete-cache-by-age?hours=48&pattern=62_*       → Delete specific pattern older than 48 hours
     * - POST /delete-cache-by-age (JSON: {"hours": 24})       → Delete entries older than 24 hours
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteCacheByAge(Request $request): JsonResponse
    {
        try {
            // Get time parameters
            $hours = $request->input('hours');
            $days = $request->input('days');
            $pattern = $request->input('pattern', '*');

            // Validate time input
            if (!$hours && !$days) {
                return response()->json([
                    'success' => false,
                    'message' => 'Time parameter required. Use "hours" or "days".',
                    'examples' => [
                        'hours' => '?hours=24',
                        'days' => '?days=7',
                        'with_pattern' => '?hours=48&pattern=62_*',
                        'json' => '{"hours": 24, "pattern": "62_*"}'
                    ]
                ], 400);
            }

            // Convert to seconds
            $maxAgeSeconds = 0;
            if ($hours) {
                $maxAgeSeconds = (int)$hours * 3600;
            } elseif ($days) {
                $maxAgeSeconds = (int)$days * 86400;
            }

            $redis = $this->getRedis();

            // Fetch keys from Redis based on pattern
            $keys = $redis->keys($pattern);

            if (empty($keys)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No keys found matching the pattern',
                    'pattern' => $pattern,
                    'summary' => [
                        'scanned_count' => 0,
                        'deleted_count' => 0,
                        'kept_count' => 0,
                        'freed_memory_bytes' => 0,
                        'freed_memory_kb' => 0,
                        'freed_memory_mb' => 0
                    ],
                    'deleted_keys' => []
                ]);
            }

            $deletedKeys = [];
            $keptKeys = [];
            $protectedKeys = [];
            $freedMemory = 0;

            foreach ($keys as $key) {
                try {
                    // Protection: Do not delete keys with only 2 segments (format: 23_23)
                    // Only delete keys with 3+ segments (format: 23_232_23232_23)
                    $segmentCount = substr_count($key, '_') + 1;

                    if ($segmentCount <= 2) {
                        $protectedKeys[] = $key;
                        continue; // Skip this key
                    }

                    // Get the cached value
                    $value = $redis->get($key);

                    if (!$value) {
                        continue; // Key doesn't exist, skip
                    }

                    // Try to parse JSON and find creation timestamp
                    $creationTime = null;
                    $ageInSeconds = null;

                    if (is_string($value)) {
                        $decoded = json_decode($value, true);

                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            // Look for common timestamp fields
                            $timestampFields = ['created_at', 'updated_at', 'timestamp', 'time', 'cache_time'];

                            foreach ($timestampFields as $field) {
                                if (isset($decoded[$field])) {
                                    // Try to parse the timestamp
                                    try {
                                        $creationTime = strtotime($decoded[$field]);
                                        if ($creationTime !== false) {
                                            $ageInSeconds = time() - $creationTime;
                                            break;
                                        }
                                    } catch (\Exception $e) {
                                        continue;
                                    }
                                }
                            }
                        }
                    }

                    // If we couldn't determine creation time, use Redis OBJECT IDLETIME as fallback
                    if ($ageInSeconds === null) {
                        $idleTime = $redis->object('idletime', $key);
                        if ($idleTime !== null) {
                            $ageInSeconds = $idleTime;
                        }
                    }

                    // If age is still null, skip this key
                    if ($ageInSeconds === null) {
                        $keptKeys[] = $key;
                        continue;
                    }

                    // If age is greater than max age, delete it
                    if ($ageInSeconds >= $maxAgeSeconds) {
                        // Get memory usage before deletion
                        $memoryUsed = strlen(serialize($value));

                        // Delete the key
                        $result = $redis->del($key);
                        
                        if ($result > 0) {
                            $deletedKeys[] = [
                                'key' => $key,
                                'age_hours' => round($ageInSeconds / 3600, 2),
                                'age_days' => round($ageInSeconds / 86400, 2),
                                'created_at' => $creationTime ? date('Y-m-d H:i:s', $creationTime) : 'N/A'
                            ];
                            $freedMemory += $memoryUsed;
                        }
                    } else {
                        $keptKeys[] = $key;
                    }
                } catch (\Exception $e) {
                    // If we can't get idle time for a key, skip it
                    \Log::warning('Could not check idle time for key', [
                        'key' => $key,
                        'error' => $e->getMessage()
                    ]);
                    $keptKeys[] = $key;
                }
            }

            $totalScanned = count($keys);
            $totalDeleted = count($deletedKeys);
            $totalKept = count($keptKeys);
            $totalProtected = count($protectedKeys);

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Deleted %d keys older than %s (%d protected, %d kept)',
                    $totalDeleted,
                    $hours ? "{$hours} hours" : "{$days} days",
                    $totalProtected,
                    $totalKept
                ),
                'criteria' => [
                    'max_age_hours' => $hours ?? ($days * 24),
                    'max_age_days' => $days ?? round($hours / 24, 2),
                    'pattern' => $pattern,
                    'protection' => '2-segment keys (e.g., 23_23) are protected from deletion'
                ],
                'summary' => [
                    'scanned_count' => $totalScanned,
                    'deleted_count' => $totalDeleted,
                    'protected_count' => $totalProtected,
                    'kept_count' => $totalKept,
                    'freed_memory_bytes' => $freedMemory,
                    'freed_memory_kb' => round($freedMemory / 1024, 2),
                    'freed_memory_mb' => round($freedMemory / (1024 * 1024), 2)
                ],
                'deleted_keys' => $deletedKeys,
                'protected_keys' => array_slice($protectedKeys, 0, 10),
                'sample_kept_keys' => array_slice($keptKeys, 0, 10)
            ]);
        } catch (\Exception $e) {
            \Log::error('Redis cache deletion by age failed', [
                'error' => $e->getMessage(),
                'hours' => $hours ?? null,
                'days' => $days ?? null
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete cache entries by age',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed information about a specific Redis cache key
     * 
     * URL Examples:
     * - GET /cache-detail?key=62_1_21724_4     → Get all details for exact key
     * - GET /cache-detail/62_1_21724_4        → Get all details for exact key (route param)
     * 
     * @param Request $request
     * @param string|null $key
     * @return JsonResponse
     */
    public function getCacheDetail(Request $request, ?string $key = null): JsonResponse
    {
        try {
            // Get key from request or route parameter
            $cacheKey = $key ?? $request->input('key');

            // Validate key is provided
            if (!$cacheKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Key parameter is required',
                    'examples' => [
                        'query_param' => '/cache-detail?key=62_1_21724_4',
                        'route_param' => '/cache-detail/62_1_21724_4'
                    ]
                ], 400);
            }

            $redis = $this->getRedis();

            // Check if key exists
            if (!$redis->exists($cacheKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Key not found in Redis cache',
                    'key' => $cacheKey
                ], 404);
            }

            // Get raw value
            $rawValue = $redis->get($cacheKey);

            // Get Redis metadata
            $ttl = $redis->ttl($cacheKey);
            $type = $redis->type($cacheKey);
            $idleTime = $redis->object('idletime', $cacheKey);
            $encoding = $redis->object('encoding', $cacheKey);
            $refcount = $redis->object('refcount', $cacheKey);
            
            // Calculate memory usage
            $memoryBytes = strlen(serialize($rawValue));
            
            // Parse JSON if applicable
            $parsedData = null;
            $isJson = false;
            $jsonError = null;
            
            if (is_string($rawValue)) {
                $decoded = json_decode($rawValue, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $parsedData = $decoded;
                    $isJson = true;
                } else {
                    $jsonError = json_last_error_msg();
                }
            }
            
            // Extract timestamps if available
            $timestamps = [];
            if ($isJson && is_array($parsedData)) {
                $timestampFields = ['created_at', 'updated_at', 'timestamp', 'time', 'cache_time'];
                foreach ($timestampFields as $field) {
                    if (isset($parsedData[$field])) {
                        $timestamps[$field] = [
                            'value' => $parsedData[$field],
                            'unix' => strtotime($parsedData[$field]),
                            'age_seconds' => time() - strtotime($parsedData[$field]),
                            'age_hours' => round((time() - strtotime($parsedData[$field])) / 3600, 2),
                            'age_days' => round((time() - strtotime($parsedData[$field])) / 86400, 2)
                        ];
                    }
                }
            }
            
            // Parse key segments
            $keySegments = explode('_', $cacheKey);
            $segmentInfo = [
                'count' => count($keySegments),
                'segments' => $keySegments,
                'is_protected' => count($keySegments) <= 2
            ];
            
            // Guess segment meanings based on common patterns
            if (count($keySegments) >= 4) {
                $segmentInfo['possible_meaning'] = [
                    'segment_1' => 'client_id: ' . ($keySegments[0] ?? 'N/A'),
                    'segment_2' => 'campaign_id: ' . ($keySegments[1] ?? 'N/A'),
                    'segment_3' => 'lead_id: ' . ($keySegments[2] ?? 'N/A'),
                    'segment_4' => 'prompt_id: ' . ($keySegments[3] ?? 'N/A')
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Cache key details retrieved successfully',
                'key' => $cacheKey,
                'key_info' => [
                    'key' => $cacheKey,
                    'exists' => true,
                    'type' => $type,
                    'encoding' => $encoding,
                    'refcount' => $refcount,
                    'segments' => $segmentInfo
                ],
                'time_info' => [
                    'ttl_seconds' => $ttl,
                    'ttl_human' => $ttl == -1 ? 'No expiry' : ($ttl == -2 ? 'Key does not exist' : gmdate('H:i:s', $ttl)),
                    'idle_seconds' => $idleTime,
                    'idle_hours' => $idleTime ? round($idleTime / 3600, 2) : null,
                    'idle_days' => $idleTime ? round($idleTime / 86400, 2) : null,
                    'last_access' => $idleTime ? date('Y-m-d H:i:s', time() - $idleTime) : null
                ],
                'memory_info' => [
                    'size_bytes' => $memoryBytes,
                    'size_kb' => round($memoryBytes / 1024, 2),
                    'size_mb' => round($memoryBytes / (1024 * 1024), 4),
                    'raw_length' => is_string($rawValue) ? strlen($rawValue) : null
                ],
                'data_info' => [
                    'is_json' => $isJson,
                    'json_error' => $jsonError,
                    'data_type' => gettype($parsedData ?? $rawValue),
                    'field_count' => is_array($parsedData) ? count($parsedData) : null,
                    'fields' => is_array($parsedData) ? array_keys($parsedData) : null
                ],
                'timestamps' => empty($timestamps) ? null : $timestamps,
                'data' => $parsedData ?? $rawValue,
                'raw_value' => $rawValue
            ]);
        } catch (\Exception $e) {
            \Log::error('Redis cache detail retrieval failed', [
                'error' => $e->getMessage(),
                'key' => $cacheKey ?? null
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve cache key details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Test Redis connection and return diagnostics
     * 
     * @return JsonResponse
     */
    public function testConnection(): JsonResponse
    {
        try {
            // Start timer
            $start = microtime(true);

            $redis = $this->getRedis();
            $ping = $redis->ping();
            $connectionTime = round((microtime(true) - $start) * 1000, 2);

            // Get connection info (mask credentials)
            $config = config('database.redis.default');
            if (isset($config['password']) && $config['password']) {
                $config['password'] = '******';
            }
            if (isset($config['url'])) {
                $config['url'] = preg_replace('/:[^:@]+@/', ':******@', $config['url']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Redis connection successful',
                'ping_response' => $ping,
                'latency_ms' => $connectionTime,
                'client' => 'predis',
                'connection_config' => $config
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Redis connection failed',
                'error' => $e->getMessage(),
                'trace' => explode("\n", $e->getTraceAsString())[0]
            ], 500);
        }
    }
}

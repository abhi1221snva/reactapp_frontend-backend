<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SystemServerController extends Controller
{
    /**
     * @OA\Get(
     *     path="/system/server-info",
     *     summary="Comprehensive server health and resource usage",
     *     description="Returns CPU, RAM, disk, queue, Redis, database, and PHP metrics. Requires system_administrator (level 11).",
     *     tags={"System"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Server info retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="server",     type="object",
     *                     @OA\Property(property="hostname",      type="string"),
     *                     @OA\Property(property="os",            type="string"),
     *                     @OA\Property(property="cpu_model",     type="string"),
     *                     @OA\Property(property="cpu_cores",     type="integer"),
     *                     @OA\Property(property="ram_total_mb",  type="number"),
     *                     @OA\Property(property="ram_used_pct",  type="number"),
     *                     @OA\Property(property="disk_total_gb", type="number"),
     *                     @OA\Property(property="disk_used_pct", type="number")
     *                 ),
     *                 @OA\Property(property="queue",     type="object"),
     *                 @OA\Property(property="redis",     type="object"),
     *                 @OA\Property(property="database",  type="object"),
     *                 @OA\Property(property="php",       type="object"),
     *                 @OA\Property(property="fetched_at",type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden — system_administrator only"),
     *     @OA\Response(response=500, description="Failed to gather server info")
     * )
     */
    public function serverInfo()
    {
        try {
            return $this->successResponse('Server info', [
                'server'    => $this->getServerInfo(),
                'queue'     => $this->getQueueStatus(),
                'redis'     => $this->getRedisStatus(),
                'database'  => $this->getDatabaseStatus(),
                'php'       => $this->getPhpLimits(),
                'fetched_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse('Failed to gather server info', [$e->getMessage()], $e, 500);
        }
    }

    // ── Server / OS / Hardware ────────────────────────────────────────────────

    private function getServerInfo(): array
    {
        $cpu     = $this->getCpuInfo();
        $mem     = $this->getMemInfo();
        $total   = disk_total_space('/');
        $free    = disk_free_space('/');
        $used    = $total - $free;
        $usedPct = $total > 0 ? round($used / $total * 100, 1) : 0;

        return [
            'hostname'       => gethostname() ?: 'Unknown',
            'os'             => trim(php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m')),
            'cpu_model'      => $cpu['model'],
            'cpu_cores'      => $cpu['cores'],
            'ram_total_mb'   => $mem['total_mb'],
            'ram_free_mb'    => $mem['free_mb'],
            'ram_used_mb'    => $mem['total_mb'] - $mem['free_mb'],
            'ram_used_pct'   => $mem['total_mb'] > 0
                ? round(($mem['total_mb'] - $mem['free_mb']) / $mem['total_mb'] * 100, 1)
                : 0,
            'disk_total_gb'  => round($total / 1073741824, 2),
            'disk_used_gb'   => round($used  / 1073741824, 2),
            'disk_free_gb'   => round($free  / 1073741824, 2),
            'disk_used_pct'  => $usedPct,
            'php_version'    => PHP_VERSION,
            'mysql_version'  => $this->getMysqlVersion(),
            'app_version'    => app()->version(),
            'web_server'     => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'environment'    => app()->environment(),
        ];
    }

    private function getCpuInfo(): array
    {
        $model = 'Unknown';
        $cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $raw = file_get_contents('/proc/cpuinfo');
            if (preg_match('/model name\s*:\s*(.+)/i', $raw, $m)) {
                $model = trim($m[1]);
            }
            $cores = max(1, substr_count($raw, 'processor'));
        }
        return ['model' => $model, 'cores' => $cores];
    }

    private function getMemInfo(): array
    {
        $total = 0;
        $free  = 0;
        if (is_readable('/proc/meminfo')) {
            $raw = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $raw, $m)) {
                $total = (int) round($m[1] / 1024);
            }
            // MemAvailable is the practical "free" — includes reclaimable cache
            $key = preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $raw, $m)
                ? $m[1]
                : (preg_match('/MemFree:\s+(\d+)\s+kB/i', $raw, $m) ? $m[1] : 0);
            $free = (int) round($key / 1024);
        }
        return ['total_mb' => $total, 'free_mb' => $free];
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    private function getQueueStatus(): array
    {
        $driver  = env('QUEUE_CONNECTION', 'sync');
        $pending = 0;
        $failed  = 0;
        $workers = 0;

        try {
            $pending = DB::table('jobs')->count();
        } catch (\Throwable) {}

        try {
            $failed = DB::table('failed_jobs')->count();
        } catch (\Throwable) {}

        try {
            $out     = shell_exec('pgrep -c "artisan queue:work" 2>/dev/null');
            $workers = max(0, (int) trim($out ?? '0'));
        } catch (\Throwable) {}

        return [
            'driver'  => $driver,
            'workers' => $workers,
            'pending' => $pending,
            'failed'  => $failed,
        ];
    }

    // ── Redis ─────────────────────────────────────────────────────────────────

    private function getRedisStatus(): array
    {
        try {
            $redis = Redis::connection();
            $redis->ping();
            $info   = $redis->info('memory');
            return [
                'connected'      => true,
                'used_memory_mb' => isset($info['used_memory'])
                    ? round($info['used_memory'] / 1048576, 2) : null,
                'peak_memory_mb' => isset($info['used_memory_peak'])
                    ? round($info['used_memory_peak'] / 1048576, 2) : null,
            ];
        } catch (\Throwable $e) {
            return [
                'connected'      => false,
                'used_memory_mb' => null,
                'peak_memory_mb' => null,
                'error'          => $e->getMessage(),
            ];
        }
    }

    // ── Database ──────────────────────────────────────────────────────────────

    private function getDatabaseStatus(): array
    {
        try {
            $version     = $this->getMysqlVersion();
            $connRow     = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
            $dbName      = DB::getDatabaseName();
            $sizeRow     = DB::selectOne(
                "SELECT ROUND(SUM(data_length + index_length) / 1048576, 2) AS size_mb
                 FROM information_schema.TABLES
                 WHERE table_schema = ?",
                [$dbName]
            );
            return [
                'version'     => $version,
                'connections' => $connRow  ? (int) $connRow->Value   : null,
                'db_name'     => $dbName,
                'size_mb'     => $sizeRow  ? (float) $sizeRow->size_mb : null,
            ];
        } catch (\Throwable $e) {
            return [
                'version'     => 'Unknown',
                'connections' => null,
                'db_name'     => null,
                'size_mb'     => null,
                'error'       => $e->getMessage(),
            ];
        }
    }

    private function getMysqlVersion(): string
    {
        try {
            $row = DB::selectOne('SELECT VERSION() AS v');
            return $row->v ?? 'Unknown';
        } catch (\Throwable) {
            return 'Unknown';
        }
    }

    // ── PHP Limits ────────────────────────────────────────────────────────────

    private function getPhpLimits(): array
    {
        return [
            'version'             => PHP_VERSION,
            'memory_limit'        => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size'       => ini_get('post_max_size'),
            'max_execution_time'  => ini_get('max_execution_time') . 's',
        ];
    }
}

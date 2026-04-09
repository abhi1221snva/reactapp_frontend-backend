<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OpenApi\Analysers\DocBlockAnnotationFactory;
use OpenApi\Analysers\TokenAnalyser;
use OpenApi\Generator;

/**
 * Artisan command to regenerate Swagger / OpenAPI documentation.
 *
 * Usage:
 *   php artisan swagger:generate          # scan app/ and write storage/api-docs/api-docs.json
 *   php artisan swagger:generate --yaml   # also write api-docs.yaml
 */
class GenerateSwaggerDocs extends Command
{
    protected $signature = 'swagger:generate
        {--yaml : Also generate a YAML copy}
        {--path= : Override scan path (default: app/)}';

    protected $description = 'Scan @OA annotations and generate storage/api-docs/api-docs.json';

    public function handle(): int
    {
        $scanPath = $this->option('path') ?: base_path('app');
        $outDir   = storage_path('api-docs');

        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $this->info("Scanning: {$scanPath}");

        try {
            // Use TokenAnalyser instead of the default ReflectionAnalyser to avoid
            // "Cannot declare class" errors caused by ReflectionAnalyser include()-ing
            // files that the autoloader has already loaded.
            $analyser = new TokenAnalyser([new DocBlockAnnotationFactory()]);
            $openapi = Generator::scan([$scanPath], [
                'analyser' => $analyser,
                'logger'   => new \Psr\Log\NullLogger(),
            ]);
        } catch (\Throwable $e) {
            $this->error('OpenAPI scan failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Write JSON
        $jsonPath = $outDir . '/api-docs.json';
        $json     = $openapi->toJson();
        file_put_contents($jsonPath, $json);

        $pathCount = count(json_decode($json, true)['paths'] ?? []);
        $this->info("Written: {$jsonPath}");
        $this->line("  Paths documented: {$pathCount}");

        // Write YAML (optional)
        if ($this->option('yaml')) {
            $yamlPath = $outDir . '/api-docs.yaml';
            file_put_contents($yamlPath, $openapi->toYaml());
            $this->info("Written: {$yamlPath}");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}

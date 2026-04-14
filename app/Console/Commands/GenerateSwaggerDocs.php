<?php

namespace App\Console\Commands;

use App\Services\Swagger\RouteSpecMerger;
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
        {--path= : Override scan path (default: app/)}
        {--no-routes : Skip route-scanner stub generation}';

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

        // Decode the annotation-derived spec so we can merge route stubs.
        $spec = json_decode($openapi->toJson(), true);
        $annotatedOpCount = $this->countOperations($spec);

        if (!$this->option('no-routes')) {
            $merger = new RouteSpecMerger();
            $spec   = $merger->mergeRouteStubs($spec);
            $this->line("  Annotated operations: {$annotatedOpCount}");
            $this->line("  After route-stub merge: " . $this->countOperations($spec));
        }

        // Write JSON
        $jsonPath = $outDir . '/api-docs.json';
        $json     = json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($jsonPath, $json);

        $pathCount = count($spec['paths'] ?? []);
        $this->info("Written: {$jsonPath}");
        $this->line("  Paths documented: {$pathCount}");

        // Write YAML (optional). Note: when route stubs are merged, the
        // `$openapi` object no longer matches what's on disk — we fall
        // back to a manual YAML conversion via symfony/yaml if requested.
        if ($this->option('yaml')) {
            $yamlPath = $outDir . '/api-docs.yaml';
            if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                file_put_contents($yamlPath, \Symfony\Component\Yaml\Yaml::dump($spec, 6, 2));
            } else {
                file_put_contents($yamlPath, $openapi->toYaml());
            }
            $this->info("Written: {$yamlPath}");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    /** Count total operations (get/post/put/patch/delete/head/options) in a spec. */
    private function countOperations(array $spec): int
    {
        $count = 0;
        foreach ($spec['paths'] ?? [] as $methods) {
            foreach ($methods as $method => $_op) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) {
                    $count++;
                }
            }
        }
        return $count;
    }
}

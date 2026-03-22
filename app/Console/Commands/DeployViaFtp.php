<?php

namespace App\Console\Commands;

use App\Console\Helpers\FrameworkLayoutHelper;
use App\Console\Helpers\FtpHelper;
use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DeployViaFtp extends Command
{
    protected $signature = 'deploy:ftp
                            {--dry-run : Show what would be uploaded without sending files}
                            {--only= : Comma-separated include paths to deploy (overrides config include list)}
                            {--vendor : Include vendor directory in deployment}
                            {--framework : Ensure framework directories are set up with correct .gitignore files}';

    protected $description = 'Deploy selected application files to an FTP server';

    private int $uploaded = 0;

    private int $skipped = 0;

    private int $failed = 0;

    private FtpHelper $ftp;

    private FrameworkLayoutHelper $frameworkLayout;

    public function handle(): int
    {
        if (! function_exists('ftp_connect')) {
            $this->error('FTP extension is not available. Enable ext-ftp in your PHP installation.');

            return self::FAILURE;
        }

        $this->ftp = new FtpHelper;
        $this->frameworkLayout = new FrameworkLayoutHelper;

        /** @var array<string, mixed> $config */
        $config = config('ftp');

        if (! ($config['enabled'] ?? false)) {
            $this->error('FTP deployment is disabled. Set FTP_ENABLED=true in your environment.');

            return self::FAILURE;
        }

        $missing = $this->missingRequiredConfig($config);
        if ($missing !== []) {
            $this->error('Missing required FTP config: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $includes = $this->resolveIncludePaths($config);
        $excludes = $this->resolveExcludeRules($config);
        $localBasePath = base_path();
        $remoteBasePath = $this->ftp->normalizeRemoteRoot((string) ($config['root'] ?? ''));

        if ((bool) $this->option('framework')) {
            if (! $this->setupFrameworkDirectories($localBasePath, $dryRun)) {
                return self::FAILURE;
            }
        }

        if ((bool) $this->option('vendor')) {
            $includes[] = 'vendor';
        }

        $includes = array_values(array_unique($includes));

        if ($includes === []) {
            $this->error('No include paths configured for FTP deployment.');

            return self::FAILURE;
        }

        $this->line('Include paths:');
        foreach ($includes as $includePath) {
            $this->line("  - {$includePath}");
        }

        if ($dryRun) {
            $this->warn('Dry run mode is enabled. No files will be uploaded.');
        }

        if (! $this->prepareHotFileForPublicSync($includes, $dryRun)) {
            return self::FAILURE;
        }

        $connection = $this->connectToFtp($config, $dryRun);
        if ($connection === null && ! $dryRun) {
            return self::FAILURE;
        }

        $files = $this->collectFiles($includes, $excludes, $localBasePath);

        if ($files === []) {
            $this->warn('No files matched include/exclude rules.');
        }

        foreach ($files as $relativePath => $absolutePath) {
            if (! $this->uploadFile(
                $connection,
                $absolutePath,
                $relativePath,
                $remoteBasePath,
                $dryRun
            )) {
                $this->failed++;
            }
        }

        $this->syncEnvironmentFile($connection, $config, $remoteBasePath, $dryRun);

        $this->ftp->disconnect($connection);

        $this->newLine();
        $this->info('FTP deploy finished.');
        $this->line("Uploaded: {$this->uploaded}");
        $this->line("Skipped: {$this->skipped}");
        $this->line("Failed: {$this->failed}");

        return $this->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function missingRequiredConfig(array $config): array
    {
        $required = ['host', 'username', 'password'];
        $missing = [];

        foreach ($required as $key) {
            $value = trim((string) ($config[$key] ?? ''));
            if ($value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveIncludePaths(array $config): array
    {
        $onlyOption = trim((string) $this->option('only'));

        if ($onlyOption !== '') {
            $items = array_map('trim', explode(',', $onlyOption));

            return array_values(array_filter($items, fn (string $item): bool => $item !== ''));
        }

        $includes = (array) ($config['include'] ?? []);

        return array_values(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            $includes
        ), fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveExcludeRules(array $config): array
    {
        $excludes = (array) ($config['exclude'] ?? []);

        return array_values(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            $excludes
        ), fn (string $item): bool => $item !== ''));
    }

    /**
     * Ensure all framework-required directories and their .gitignore files are present.
     */
    private function setupFrameworkDirectories(string $localBasePath, bool $dryRun): bool
    {
        return $this->frameworkLayout->ensureLayout(
            $localBasePath,
            $dryRun,
            fn (string $message) => $this->line($message),
            fn (string $message) => $this->error($message),
        );
    }

    /**
     * @param  list<string>  $includes
     */
    private function prepareHotFileForPublicSync(array $includes, bool $dryRun): bool
    {
        $absPublicPath = public_path();
        $relPublicPath = $this->toRelativePath($absPublicPath, base_path());

        if ($relPublicPath === '') {
            return true;
        }

        $shouldSyncPublic = false;

        foreach ($includes as $includePath) {
            $normalized = $this->normalizeLocalRelativePath($includePath);

            if ($normalized === $relPublicPath || str_starts_with($normalized, $relPublicPath.'/')) {
                $shouldSyncPublic = true;
                break;
            }
        }

        if (! $shouldSyncPublic) return true;

        $hotPath = rtrim(str_replace('\\', '/', $absPublicPath), '/').'/hot';
        if (! file_exists($hotPath)) return true;

        if ($dryRun) {
            $this->line('[DRY-RUN] Would remove '.$relPublicPath.'/hot before syncing '.$relPublicPath.'.');
            return true;
        }

        if (! @unlink($hotPath)) {
            $this->error('Failed to remove '.$relPublicPath.'/hot before syncing '.$relPublicPath.'.');
            return false;
        }

        $this->line('Removed '.$relPublicPath.'/hot before syncing '.$relPublicPath.'.');

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function connectToFtp(array $config, bool $dryRun)
    {
        if ($dryRun) return null;

        $host = (string) $config['host'];
        $port = (int) ($config['port'] ?? 21);

        $this->line("Connecting to FTP host {$host}:{$port}...");

        $connection = $this->ftp->connect($config);

        if (! $connection) {
            $this->error('Unable to connect to FTP server.');
            return null;
        }

        $this->info('FTP connection established.');

        return $connection;
    }

    /**
     * @param  list<string>  $includes
     * @param  list<string>  $excludes
     * @return array<string, string>
     */
    private function collectFiles(array $includes, array $excludes, string $localBasePath): array
    {
        $collected = [];

        foreach ($includes as $includePath) {
            $relativeInclude = $this->normalizeLocalRelativePath($includePath);
            if ($relativeInclude === '') continue;

            $absoluteInclude = $localBasePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeInclude);

            if (! file_exists($absoluteInclude)) {
                $this->warn("Include path does not exist locally: {$relativeInclude}");
                continue;
            }

            if (is_file($absoluteInclude)) {
                if (! $this->isExcluded($relativeInclude, $excludes)) {
                    $collected[$relativeInclude] = $absoluteInclude;
                }

                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteInclude, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (! $fileInfo->isFile()) continue;

                $relativePath = $this->toRelativePath($fileInfo->getPathname(), $localBasePath);
                if ($relativePath === '' || $this->isExcluded($relativePath, $excludes)) {
                    continue;
                }

                $collected[$relativePath] = $fileInfo->getPathname();
            }
        }

        ksort($collected);

        return $collected;
    }

    private function uploadFile($connection, string $localPath, string $relativePath, string $remoteBasePath, bool $dryRun): bool
    {
        $remotePath = $this->ftp->buildRemotePath($remoteBasePath, $relativePath);

        if ($dryRun) {
            $this->line("[DRY-RUN] {$relativePath} => {$remotePath}");
            $this->uploaded++;

            return true;
        }

        if (! $this->ftp->shouldUpload($connection, $localPath, $remotePath)) {
            $this->skipped++;
            return true;
        }

        $remoteDirectory = dirname($remotePath);
        if (! $this->ftp->ensureRemoteDirectory($connection, $remoteDirectory)) {
            $this->error("Failed creating remote directory: {$remoteDirectory}");

            return false;
        }

        $success = $this->ftp->uploadBinary($connection, $remotePath, $localPath);

        if (! $success) {
            $this->error("Failed uploading: {$relativePath}");
            return false;
        }

        $this->line("Uploaded: {$relativePath}");
        $this->uploaded++;

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function syncEnvironmentFile($connection, array $config, string $remoteBasePath, bool $dryRun): void
    {
        $environment = (array) ($config['environment_file'] ?? []);
        $localRelative = trim((string) ($environment['local'] ?? '.env.production'));
        $remoteRelative = trim((string) ($environment['remote'] ?? '.env'));

        if ($localRelative === '' || $remoteRelative === '') return;

        $normalizedLocal = $this->normalizeLocalRelativePath($localRelative);
        $localPath = base_path($normalizedLocal);

        if (! is_file($localPath)) {
            $this->warn("Environment file not found: {$normalizedLocal}");
            return;
        }

        $this->line('Syncing production environment file...');

        if (! $this->uploadFile(
            $connection,
            $localPath,
            $normalizedLocal,
            $remoteBasePath,
            $dryRun
        )) {
            $this->failed++;
            return;
        }

        if ($dryRun) return;

        if ($normalizedLocal !== $this->normalizeLocalRelativePath($remoteRelative)) {
            $remoteSource = $this->ftp->buildRemotePath($remoteBasePath, $normalizedLocal);
            $remoteTarget = $this->ftp->buildRemotePath($remoteBasePath, $this->normalizeLocalRelativePath($remoteRelative));

            $this->ftp->ensureRemoteDirectory($connection, dirname($remoteTarget));

            $renamed = $this->ftp->rename($connection, $remoteSource, $remoteTarget);
            if (! $renamed) {
                $this->error("Failed renaming environment file to {$remoteRelative} on remote server.");
                $this->failed++;
            } else {
                $this->line("Environment file updated as: {$remoteRelative}");
            }
        }
    }

    /**
     * @param  list<string>  $excludeRules
     */
    private function isExcluded(string $relativePath, array $excludeRules): bool
    {
        $path = strtolower(trim(str_replace('\\', '/', $relativePath), '/'));

        foreach ($excludeRules as $rule) {
            $normalizedRule = strtolower(trim(str_replace('\\', '/', $rule)));
            if ($normalizedRule === '') continue;

            $normalizedRule = ltrim($normalizedRule, '/');

            if (str_ends_with($normalizedRule, '/')) {
                $directoryRule = trim($normalizedRule, '/');
                if ($directoryRule !== '' && ($path === $directoryRule || str_starts_with($path, $directoryRule.'/'))) return true;
                continue;
            }

            if (str_contains($normalizedRule, '*') || str_contains($normalizedRule, '?')) {
                if (fnmatch($normalizedRule, $path, FNM_PATHNAME) || fnmatch($normalizedRule, basename($path))) return true;

                continue;
            }

            if ($path === $normalizedRule || str_ends_with($path, '/'.$normalizedRule)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLocalRelativePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private function toRelativePath(string $absolutePath, string $localBasePath): string
    {
        $absolute = str_replace('\\', '/', $absolutePath);
        $base = rtrim(str_replace('\\', '/', $localBasePath), '/').'/';

        if (! str_starts_with($absolute, $base)) return '';

        return ltrim(substr($absolute, strlen($base)), '/');
    }
}
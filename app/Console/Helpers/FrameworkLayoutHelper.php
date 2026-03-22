<?php

namespace App\Console\Helpers;

use RuntimeException;

class FrameworkLayoutHelper
{
    /** @var array{directories?: list<array{path: string, gitignore: string}>} */
    private array $manifest;

    public function __construct(?string $manifestPath = null)
    {
        $path = $manifestPath ?? __DIR__.DIRECTORY_SEPARATOR.'framework-layout.json';
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException('Unable to read framework layout manifest: '.$path);
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON in framework layout manifest: '.$path);
        }

        $this->manifest = $decoded;
    }

    public function ensureLayout(string $localBasePath, bool $dryRun, callable $line, callable $error): bool
    {
        $directories = $this->manifest['directories'] ?? [];
        if (! is_array($directories)) {
            $error('Framework layout manifest is missing a valid directories list.');

            return false;
        }

        foreach ($directories as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $dirPath = trim((string) ($entry['path'] ?? ''));
            $gitignoreContent = (string) ($entry['gitignore'] ?? '');

            if ($dirPath === '') {
                continue;
            }

            $absolutePath = $localBasePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dirPath);

            if (! is_dir($absolutePath)) {
                if ($dryRun) {
                    $line("[DRY-RUN] Would create directory: {$dirPath}");
                } else {
                    if (! @mkdir($absolutePath, 0755, true)) {
                        $error("Failed to create directory: {$dirPath}");

                        return false;
                    }

                    $line("Created directory: {$dirPath}");
                }
            }

            $gitignorePath = $absolutePath.DIRECTORY_SEPARATOR.'.gitignore';

            if (! is_file($gitignorePath)) {
                if ($dryRun) {
                    $line("[DRY-RUN] Would create .gitignore in {$dirPath}");
                } else {
                    if (file_put_contents($gitignorePath, $gitignoreContent) === false) {
                        $error("Failed to create .gitignore in: {$dirPath}");

                        return false;
                    }

                    $line("Created .gitignore in: {$dirPath}");
                }
            }
        }

        return true;
    }
}

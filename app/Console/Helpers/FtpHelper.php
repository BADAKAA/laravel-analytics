<?php

namespace App\Console\Helpers;

class FtpHelper
{
    /** @var array<string, true> */
    private array $ensuredDirectories = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config)
    {
        $host = (string) $config['host'];
        $port = (int) ($config['port'] ?? 21);
        $timeout = (int) ($config['timeout'] ?? 90);
        $useSsl = (bool) ($config['ssl'] ?? false);

        $connection = $useSsl
            ? @ftp_ssl_connect($host, $port, $timeout)
            : @ftp_connect($host, $port, $timeout);

        if (! $connection) {
            return null;
        }

        $loggedIn = @ftp_login(
            $connection,
            (string) $config['username'],
            (string) $config['password']
        );

        if (! $loggedIn) {
            ftp_close($connection);

            return null;
        }

        @ftp_pasv($connection, (bool) ($config['passive'] ?? true));

        return $connection;
    }

    public function disconnect($connection): void
    {
        if ($connection instanceof \FTP\Connection) {
            ftp_close($connection);
        }
    }

    public function normalizeRemoteRoot(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));

        if ($normalized === '') {
            return '';
        }

        return rtrim($normalized, '/');
    }

    public function buildRemotePath(string $remoteBasePath, string $relativePath): string
    {
        $relative = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($remoteBasePath === '') {
            return $relative;
        }

        return trim($remoteBasePath, '/').'/'.$relative;
    }

    public function shouldUpload($connection, string $localPath, string $remotePath): bool
    {
        $remoteSize = @ftp_size($connection, $remotePath);
        if ($remoteSize < 0) {
            return true;
        }

        $localSize = filesize($localPath);
        if ($localSize === false || $localSize !== $remoteSize) {
            return true;
        }

        $remoteMtime = @ftp_mdtm($connection, $remotePath);
        if ($remoteMtime < 0) {
            return true;
        }

        $localMtime = filemtime($localPath);
        if ($localMtime === false) {
            return true;
        }

        return $localMtime > $remoteMtime;
    }

    public function ensureRemoteDirectory($connection, string $remoteDirectory): bool
    {
        $normalized = trim(str_replace('\\', '/', $remoteDirectory));
        if ($normalized === '' || $normalized === '.' || $normalized === '/') {
            return true;
        }

        if (isset($this->ensuredDirectories[$normalized])) {
            return true;
        }

        $isAbsolute = str_starts_with($normalized, '/');
        $segments = array_values(array_filter(explode('/', trim($normalized, '/')), fn (string $segment): bool => $segment !== ''));

        $path = $isAbsolute ? '/' : '';
        $initialDirectory = @ftp_pwd($connection) ?: null;

        foreach ($segments as $segment) {
            $path = $path === '' || $path === '/'
                ? $path.$segment
                : $path.'/'.$segment;

            if (@ftp_chdir($connection, $path)) {
                if ($initialDirectory !== null) {
                    @ftp_chdir($connection, $initialDirectory);
                }

                continue;
            }

            if (! @ftp_mkdir($connection, $path)) {
                return false;
            }
        }

        if ($initialDirectory !== null) {
            @ftp_chdir($connection, $initialDirectory);
        }

        $this->ensuredDirectories[$normalized] = true;

        return true;
    }

    public function uploadBinary($connection, string $remotePath, string $localPath): bool
    {
        return (bool) @ftp_put($connection, $remotePath, $localPath, FTP_BINARY);
    }

    public function rename($connection, string $from, string $to): bool
    {
        return (bool) @ftp_rename($connection, $from, $to);
    }
}

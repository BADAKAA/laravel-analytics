<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VisitorHash
{
    /**
     * Returns a daily-rotating, irreversible visitor identifier.
     * Raw IP and user-agent values are never persisted.
     */
    public static function make(string $ip, string $userAgent, string $domain): string
    {
        $salt = Cache::remember('analytics_salt', now()->endOfDay(),
            fn () => Str::random(64),
        );

        $truncatedIp = self::truncateIp($ip);

        return hash('sha256', $salt.$truncatedIp.$userAgent.$domain);
    }

    private static function truncateIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            $bin = substr($bin, 0, 6).str_repeat("\0", 10);

            return inet_ntop($bin);
        }

        return preg_replace('/\.\d+$/', '.0', $ip) ?? $ip;
    }
}

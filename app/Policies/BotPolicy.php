<?php

namespace App\Policies;

use Illuminate\Http\Request;

class BotPolicy
{
    private const BOTS = [
        'bot',
        'Slurp',
        'Scooter',
        'URL_Spider_SQL',
        'Googlebot',
        'Firefly',
        'WebBug',
        'WebFindBot',
        'crawler',
        'appie',
        'msnbot',
        'InfoSeek',
        'FAST',
        'Spade',
        'NationalDirectory',
    ];

    public static function isBot(Request $request): bool
    {
        $agent = strtolower((string) ($request->userAgent() ?? ''));
        foreach (self::BOTS as $bot) {
            if (str_contains($agent, strtolower($bot))) return true;
        }
        return false;
    }

}
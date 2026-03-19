<?php

namespace App\Enums;

enum SiteRole: int
{
    case Viewer = 1;
    case Admin = 2;
    case Owner = 3;

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Viewer',
            self::Admin => 'Admin',
            self::Owner => 'Owner',
        };
    }
}

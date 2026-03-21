<?php

namespace App\Enums;

enum DeviceType: int
{
    case Unknown = 0;
    case Desktop = 1;
    case Mobile = 2;
    case Tablet = 3;
    case TV = 4;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Desktop => 'Desktop',
            self::Mobile => 'Mobile',
            self::Tablet => 'Tablet',
            self::TV => 'TV',
        };
    }

    public static function fromScreenWidth(?int $screenWidth): self
    {
        return match (true) {
            !$screenWidth => self::Unknown,
            $screenWidth < 768 => self::Mobile,
            $screenWidth < 1024 => self::Tablet,
            default => self::Desktop,
        };
    }

    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) return $case;
        }
        return null;
    }
}

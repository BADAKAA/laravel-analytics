<?php

namespace App\Enums;

enum Channel: int
{
    case Unknown = 0;
    case Direct = 1;
    case OrganicSearch = 2;
    case OrganicSocial = 3;
    case OrganicVideo = 4;
    case OrganicShopping = 5;
    case PaidSearch = 6;
    case PaidSocial = 7;
    case PaidVideo = 8;
    case PaidShopping = 9;
    case Display = 10;
    case Email = 11;
    case Sms = 12;
    case Referral = 13;
    case Affiliates = 14;
    case Audio = 15;
    case MobilePush = 16;
    case CrossNetwork = 17;
    case PaidOther = 18;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Direct => 'Direct',
            self::OrganicSearch => 'Organic Search',
            self::OrganicSocial => 'Organic Social',
            self::OrganicVideo => 'Organic Video',
            self::OrganicShopping => 'Organic Shopping',
            self::PaidSearch => 'Paid Search',
            self::PaidSocial => 'Paid Social',
            self::PaidVideo => 'Paid Video',
            self::PaidShopping => 'Paid Shopping',
            self::Display => 'Display',
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::Referral => 'Referral',
            self::Affiliates => 'Affiliates',
            self::Audio => 'Audio',
            self::MobilePush => 'Mobile Push',
            self::CrossNetwork => 'Cross-network',
            self::PaidOther => 'Paid Other',
        };
    }
}

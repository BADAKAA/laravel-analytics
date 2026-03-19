<?php

namespace App\Services;

use App\Enums\Channel;

class ChannelClassifier
{
    private const SEARCH_ENGINES = [
        'google.com', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'baidu.com',
        'yandex.ru', 'ecosia.org', 'startpage.com', 'brave.com',
        'chatgpt.com', 'perplexity.ai', 'phind.com',
    ];

    private const SOCIAL_NETWORKS = [
        'facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'reddit.com',
        'instagram.com', 'tiktok.com', 'youtube.com', 'pinterest.com',
        'snapchat.com', 'discord.com', 'whatsapp.com', 'telegram.org',
        'mastodon.social', 'threads.net', 'bluesky.app',
    ];

    private const SHOPPING_SITES = [
        'amazon.com', 'ebay.com', 'etsy.com', 'shopify.com', 'temu.com',
    ];

    private const VIDEO_SITES = [
        'youtube.com', 'vimeo.com', 'twitch.tv', 'dailymotion.com',
    ];

    private const EMAIL_SOURCES = ['gmail', 'email', 'e-mail', 'e_mail', 'e mail'];
    private const EMAIL_MEDIUMS = ['email', 'e-mail', 'e_mail', 'e mail'];

    public function classify(
        ?string $source,
        ?string $medium,
        ?string $campaign,
        ?string $referrerDomain,
        bool $hasGclid = false,
        bool $hasMsclkid = false,
    ): Channel {
        $src = strtolower($source ?? '');
        $med = strtolower($medium ?? '');
        $cam = strtolower($campaign ?? '');
        $ref = strtolower($referrerDomain ?? '');

        if (! $src && ! $med && ! $ref) {
            return Channel::Direct;
        }

        if ($med === 'affiliate') {
            return Channel::Affiliates;
        }

        if ($med === 'audio') {
            return Channel::Audio;
        }

        if ($med === 'cross-network') {
            return Channel::CrossNetwork;
        }

        if (in_array($src, self::EMAIL_SOURCES, true) || in_array($med, self::EMAIL_MEDIUMS, true)) {
            return Channel::Email;
        }

        if ($src === 'sms' || $med === 'sms') {
            return Channel::Sms;
        }

        if (in_array($med, ['display', 'banner', 'expandable', 'interstitial', 'cpm'], true)) {
            return Channel::Display;
        }

        if ($src === 'firebase' || in_array($med, ['mobile', 'notification'], true) || str_ends_with($med, 'push')) {
            return Channel::MobilePush;
        }

        $lookupDomain = $ref ?: $src;
        $isPaidMedium = (bool) preg_match('/^(.*cp.*|ppc|retargeting|paid.*)$/', $med);
        $isSearchSite = in_array($lookupDomain, self::SEARCH_ENGINES, true);
        $isSocialSite = in_array($lookupDomain, self::SOCIAL_NETWORKS, true);
        $isVideoSite = in_array($lookupDomain, self::VIDEO_SITES, true);
        $isShoppingSite = in_array($lookupDomain, self::SHOPPING_SITES, true);
        $isShoppingCampaign = (bool) preg_match('/^(.*(([^a-df-z]|^)shop|shopping).*)$/', $cam);

        if (
            ($isSearchSite && $isPaidMedium)
            || ($src === 'google' && $hasGclid)
            || ($src === 'bing' && $hasMsclkid)
            || preg_match('/(google|bing)ads$/', $src)
        ) {
            return Channel::PaidSearch;
        }

        if (($isSocialSite && $isPaidMedium) || preg_match('/(facebook|fb|instagram|ig)[\-_]?ads?$/', $src)) {
            return Channel::PaidSocial;
        }

        if (($isVideoSite && $isPaidMedium) || preg_match('/(youtube|yt)[\-_]?ads?$/', $src)) {
            return Channel::PaidVideo;
        }

        if (($isShoppingSite || $isShoppingCampaign) && $isPaidMedium) {
            return Channel::PaidShopping;
        }

        if ($isPaidMedium) {
            return Channel::PaidOther;
        }

        if ($isSearchSite || $med === 'organic') {
            return Channel::OrganicSearch;
        }

        if ($isSocialSite || in_array($med, ['social', 'social-network', 'social-media', 'sm', 'social network', 'social media'], true)) {
            return Channel::OrganicSocial;
        }

        if ($isVideoSite || preg_match('/video/', $med)) {
            return Channel::OrganicVideo;
        }

        if ($isShoppingSite || $isShoppingCampaign) {
            return Channel::OrganicShopping;
        }

        if ($ref || in_array($med, ['referral', 'app', 'link'], true)) {
            return Channel::Referral;
        }

        return Channel::Unknown;
    }
}

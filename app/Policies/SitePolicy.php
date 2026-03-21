<?php

namespace App\Policies;

use App\Enums\SiteRole;
use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function view(User $user, Site $site): bool
    {
        return $user->sites()
            ->where('sites.id', $site->id)
            ->wherePivot('role', '>=', SiteRole::Viewer->value)
            ->exists();
    }

    public function update(User $user, Site $site): bool
    {
        return $user->sites()
            ->where('sites.id', $site->id)
            ->wherePivot('role', '>=', SiteRole::Admin->value)
            ->exists();
    }
}

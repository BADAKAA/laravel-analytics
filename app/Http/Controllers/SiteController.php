<?php

namespace App\Http\Controllers;

use App\Enums\SiteRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $sites = $this->userSites($user)
            ->orderBy('sites.name')
            ->get()
            ->map(fn (Site $site) => $this->transformSite($site));

        return inertia('sites/Index', [
            'sites' => $sites,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $this->validateSite($request);

        $site = Site::create($validated);
        $site->users()->attach($user->id, ['role' => SiteRole::Owner->value]);

        $createdSite = $this->userSites($user)
            ->where('sites.id', $site->id)
            ->firstOrFail();

        if ($request->expectsJson()) {
            return response()->json([
                'site' => $this->transformSite($createdSite),
            ], 201);
        }

        return redirect()
            ->route('sites.index')
            ->with('success', 'Site created successfully.');
    }

    public function update(Request $request, Site $site)
    {
        $user = $request->user();

        Gate::forUser($user)->authorize('update', $site);

        $validated = $this->validateSite($request, $site);
        $site->update($validated);

        $updatedSite = $this->userSites($user)
            ->where('sites.id', $site->id)
            ->firstOrFail();

        if ($request->expectsJson()) {
            return response()->json([
                'site' => $this->transformSite($updatedSite),
            ]);
        }

        return redirect()
            ->route('sites.index')
            ->with('success', 'Site updated successfully.');
    }

    public function apiIndex(Request $request)
    {
        $sites = $this->userSites($request->user())
            ->orderBy('sites.name')
            ->get()
            ->map(fn (Site $site) => $this->transformSite($site));

        return response()->json([
            'sites' => $sites,
        ]);
    }

    public function apiStore(Request $request)
    {
        return $this->store($request);
    }

    public function apiUpdate(Request $request, Site $site)
    {
        return $this->update($request, $site);
    }

    private function userSites(User $user)
    {
        return $user->sites()
            ->withPivot('role')
            ->select([
                'sites.id',
                'sites.name',
                'sites.domain',
                'sites.timezone',
                'sites.is_public',
                'sites.created_at',
                'sites.updated_at',
            ]);
    }

    private function validateSite(Request $request, ?Site $site = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sites', 'domain')->ignore($site?->id),
            ],
            'timezone' => ['required', 'string', 'timezone'],
            'is_public' => ['required', 'boolean'],
        ]);
    }

    private function transformSite(Site $site): array
    {
        $roleValue = (int) ($site->pivot?->role ?? SiteRole::Viewer->value);
        $role = SiteRole::from($roleValue);

        return [
            'id' => $site->id,
            'name' => $site->name,
            'domain' => $site->domain,
            'timezone' => $site->timezone,
            'is_public' => $site->is_public,
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
            'role' => $role->value,
            'role_label' => $role->label(),
            'can_edit' => $role->value >= SiteRole::Admin->value,
        ];
    }
}

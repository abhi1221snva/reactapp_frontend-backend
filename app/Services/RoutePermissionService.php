<?php

namespace App\Services;

use App\Model\Master\RouteGroup;
use App\Model\Master\RoleRoutePermission;
use App\Model\Master\SidebarMenuItem;
use Illuminate\Support\Facades\Cache;

class RoutePermissionService
{
    /** System administrator level — bypasses all checks */
    const SYSTEM_ADMIN_LEVEL = 11;

    /** Cache TTL in seconds (1 hour) */
    const CACHE_TTL = 3600;

    /** Menu cache TTL in seconds (5 minutes) */
    const MENU_CACHE_TTL = 300;

    /**
     * Match a request path to a route group key.
     * Returns null if no group matches (route is unrestricted).
     */
    public function getRouteGroupForPath(string $requestPath): ?string
    {
        $groups = $this->loadRouteGroups();

        // Normalise: strip leading slash and query string
        $path = ltrim($requestPath, '/');
        $path = explode('?', $path)[0];

        // Find the longest matching pattern across all groups so that
        // more-specific patterns (e.g. "disposition-by-campaign-id") win
        // over shorter generic prefixes (e.g. "disposition").
        $bestGroup = null;
        $bestLen = -1;

        foreach ($groups as $groupKey => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_starts_with($path, $pattern) && strlen($pattern) > $bestLen) {
                    $bestLen = strlen($pattern);
                    $bestGroup = $groupKey;
                }
            }
        }

        return $bestGroup;
    }

    /**
     * Check if a role has access to a route group.
     */
    public function roleHasRouteAccess(int $roleId, string $routeGroupKey): bool
    {
        $allowedGroups = $this->loadRolePermissions($roleId);
        return in_array($routeGroupKey, $allowedGroups, true);
    }

    /**
     * Get sidebar menu items filtered by role permissions and user level.
     * Returns hierarchical array grouped by section_label.
     */
    public function getUserMenuItems(int $roleId, int $userLevel, string $engine): array
    {
        $cacheKey = "rbac:menu:{$roleId}:{$engine}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $allowedGroups = $this->loadRolePermissions($roleId);

        $items = SidebarMenuItem::on('master')
            ->where('engine', $engine)
            ->where('is_active', true)
            ->where('min_level', '<=', $userLevel)
            ->orderBy('display_order')
            ->get();

        // Filter items: route_group_key is null (always visible) or in allowed groups
        $filtered = $items->filter(function ($item) use ($allowedGroups) {
            if (empty($item->route_group_key)) {
                return true;
            }
            return in_array($item->route_group_key, $allowedGroups, true);
        });

        $result = $this->groupBySection($filtered);

        Cache::put($cacheKey, $result, self::MENU_CACHE_TTL);

        return $result;
    }

    /**
     * Get ALL active menu items (for system_admin, no permission filtering).
     */
    public function getAllMenuItems(string $engine): array
    {
        $cacheKey = "rbac:menu:all:{$engine}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $items = SidebarMenuItem::on('master')
            ->where('engine', $engine)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        $result = $this->groupBySection($items);

        Cache::put($cacheKey, $result, self::MENU_CACHE_TTL);

        return $result;
    }

    /**
     * Clear all RBAC caches (or specific role).
     */
    public function clearCache(?int $roleId = null): void
    {
        Cache::forget('rbac:route_groups');
        Cache::forget('rbac:menu:all:dialer');
        Cache::forget('rbac:menu:all:crm');

        if ($roleId !== null) {
            Cache::forget("rbac:role_perms:{$roleId}");
            Cache::forget("rbac:menu:{$roleId}:dialer");
            Cache::forget("rbac:menu:{$roleId}:crm");
        } else {
            // Clear all role permission caches
            $roleIds = RoleRoutePermission::on('master')
                ->select('role_id')
                ->distinct()
                ->pluck('role_id');

            foreach ($roleIds as $rid) {
                Cache::forget("rbac:role_perms:{$rid}");
                Cache::forget("rbac:menu:{$rid}:dialer");
                Cache::forget("rbac:menu:{$rid}:crm");
            }
        }
    }

    // ── Private helpers ────────────────────────────────────────────────

    /**
     * Load all route groups from cache/DB. Returns [groupKey => [patterns]].
     */
    private function loadRouteGroups(): array
    {
        $cached = Cache::get('rbac:route_groups');
        if ($cached !== null) {
            return $cached;
        }

        $groups = RouteGroup::on('master')->get()->mapWithKeys(function ($g) {
            return [$g->key => $g->url_patterns];
        })->toArray();

        Cache::put('rbac:route_groups', $groups, self::CACHE_TTL);

        return $groups;
    }

    /**
     * Load a role's allowed route group keys from cache/DB.
     */
    private function loadRolePermissions(int $roleId): array
    {
        $cacheKey = "rbac:role_perms:{$roleId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $keys = RoleRoutePermission::on('master')
            ->where('role_id', $roleId)
            ->pluck('route_group_key')
            ->toArray();

        Cache::put($cacheKey, $keys, self::CACHE_TTL);

        return $keys;
    }

    /**
     * Group a collection of SidebarMenuItem into sections.
     */
    private function groupBySection($items): array
    {
        $sections = [];

        foreach ($items as $item) {
            $sectionKey = $item->section_label;
            if (!isset($sections[$sectionKey])) {
                $sections[$sectionKey] = [
                    'section_label' => $sectionKey,
                    'items' => [],
                ];
            }
            $sections[$sectionKey]['items'][] = [
                'route_path' => $item->route_path,
                'label' => $item->label,
                'icon_name' => $item->icon_name,
                'badge_source' => $item->badge_source,
            ];
        }

        return array_values($sections);
    }
}

<?php

namespace Keloola\KeloolaSsoAuthorize\Integration;

use Illuminate\Support\Facades\DB;

/** Local permission checks for the default directory, using already verified IDs. */
class DirectoryAccess
{
    public function allows(string $organizationId, string $userId, string $permission): bool
    {
        $id = null;
        foreach (config('keloolauthorize.integration.permissions', []) as $item) {
            if (($item['name'] ?? null) === $permission) $id = $item['id'];
        }
        if ($id === null) return false;
        $role = DB::table('keloola_sso_memberships as m')
            ->join('keloola_sso_roles as r', function ($join) {
                $join->on('r.id', '=', 'm.role_id')->on('r.organization_id', '=', 'm.organization_id');
            })
            ->where('m.organization_id', $organizationId)->where('m.user_id', $userId)->first(['r.permissions']);
        return $role !== null && in_array($id, json_decode($role->permissions, true) ?? [], true);
    }
}

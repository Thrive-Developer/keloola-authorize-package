<?php

namespace Keloola\KeloolaSsoAuthorize\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/** Standalone integration directory. Does not write native application tables. */
class DatabaseIntegrationHandler implements IntegrationHandler
{
    public function handle(string $operation, array $context, array $payload): array
    {
        return DB::transaction(function () use ($operation, $context, $payload) {
            if ($operation === 'permissions.index') return $this->catalog();
            if ($operation === 'organizations.provision') return $this->provision($payload);
            if ($operation === 'invitations.store') return $this->invite($payload);
            if ($operation === 'invitations.destroy') return $this->cancelInvitation($context['invitationId']);
            $org = $this->organization($context['organizationId']);
            return match ($operation) {
                'organizations.update' => $this->updateOrganization($org, $payload),
                'members.store' => $this->storeMember($org, $payload),
                'members.role' => $this->changeMemberRole($org, $context['userId'], $payload['role_id']),
                'members.destroy' => $this->removeMember($org, $context['userId']),
                'roles.index' => DB::table('keloola_sso_roles')->where('organization_id', $org->sso_id)->orderBy('id')->get()->map(fn ($r) => $this->roleData($r))->all(),
                'roles.store' => $this->storeRole($org, $payload),
                'roles.update' => $this->updateRole($org, $context['roleId'], $payload),
                'roles.destroy' => $this->removeRole($org, $context['roleId']),
                default => throw new IntegrationException('unsupported_operation', 'Unsupported operation.', 501),
            };
        }, 3);
    }

    private function organization(string $id): object
    {
        return DB::table('keloola_sso_organizations')->where('sso_id', $id)->lockForUpdate()->first()
            ?? throw new IntegrationException('organization_not_found', 'Organization not found.', 404);
    }

    private function catalog(): array
    {
        $items = config('keloolauthorize.integration.permissions', []);
        if (!is_array($items)) throw new IntegrationException('integration_unavailable', 'Invalid permission catalog configuration.', 503);
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'], $item['name'], $item['guard_name'])
                || !is_int($item['id']) || $item['id'] < 1 || in_array($item['id'], $ids, true)
                || !is_string($item['name']) || $item['name'] === '' || !is_string($item['guard_name'])) {
                throw new IntegrationException('integration_unavailable', 'Invalid permission catalog configuration.', 503);
            }
            $ids[] = $item['id'];
        }
        return array_values($items);
    }

    private function permissions(array $ids): array
    {
        $ids = array_map('intval', $ids);
        if (array_diff($ids, array_column($this->catalog(), 'id'))) {
            throw new IntegrationException('invalid_permissions', 'Unknown permission ID.', 422);
        }
        sort($ids);
        return $ids;
    }

    private function provision(array $p): array
    {
        $id = $p['sso_organization_id'];
        $now = now();
        DB::table('keloola_sso_organizations')->insertOrIgnore([
            'sso_id' => $id, 'name' => $p['organization_name'], 'email' => $p['organization_email'] ?? null,
            'owner_sso_id' => $p['owner']['sso_id'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $org = $this->organization($id);
        if ($org->owner_sso_id !== $p['owner']['sso_id']) {
            throw new IntegrationException('owner_conflict', 'Existing organization has a different owner.');
        }
        $this->upsertUser($p['owner']['sso_id'], $p['owner']['name'], $p['owner']['email']);
        $role = DB::table('keloola_sso_roles')->where('organization_id', $id)->where('is_default', true)->first();
        if (!$role) {
            $roleId = DB::table('keloola_sso_roles')->insertGetId([
                'organization_id' => $id, 'name' => 'Admin', 'permissions' => json_encode(array_column($this->catalog(), 'id')),
                'is_default' => true, 'is_delete' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else $roleId = $role->id;
        DB::table('keloola_sso_memberships')->insertOrIgnore([
            'organization_id' => $id, 'user_id' => $org->owner_sso_id, 'role_id' => $roleId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['organization_id' => $id, 'sso_organization_id' => $id, 'owner_user_id' => $org->owner_sso_id, 'owner_role_id' => (int) $roleId, 'status' => 'ready'];
    }

    private function upsertUser(string $id, string $name, string $email): void
    {
        DB::table('keloola_sso_users')->upsert([
            ['sso_id' => $id, 'name' => $name, 'email' => $email, 'created_at' => now(), 'updated_at' => now()],
        ], ['sso_id'], ['name', 'email', 'updated_at']);
    }

    private function updateOrganization(object $org, array $p): array
    {
        DB::table('keloola_sso_organizations')->where('sso_id', $org->sso_id)->update($p + ['updated_at' => now()]);
        $org = $this->organization($org->sso_id);
        return ['sso_organization_id' => $org->sso_id, 'name' => $org->name, 'email' => $org->email, 'address' => $org->address];
    }

    private function role(object $org, int $id): object
    {
        return DB::table('keloola_sso_roles')->where('organization_id', $org->sso_id)->where('id', $id)->first()
            ?? throw new IntegrationException('role_not_found', 'Role not found in this organization.', 404);
    }

    private function memberData(object $org, string $userId, int $roleId): array
    {
        return ['sso_organization_id' => $org->sso_id, 'sso_id' => $userId, 'user_id' => $userId, 'role_id' => $roleId, 'membership_status' => 'active'];
    }

    private function storeMember(object $org, array $p): array
    {
        $role = $this->role($org, (int) $p['role_id']);
        if ($role->is_default && $p['sso_id'] !== $org->owner_sso_id) {
            throw new IntegrationException('owner_role_protected', 'Owner role cannot be assigned to another member.');
        }
        $member = DB::table('keloola_sso_memberships')->where('organization_id', $org->sso_id)->where('user_id', $p['sso_id'])->first();
        if ($member && (int) $member->role_id !== (int) $p['role_id']) {
            throw new IntegrationException('membership_conflict', 'Use the role update endpoint to change an existing membership.');
        }
        $this->upsertUser($p['sso_id'], $p['name'], $p['email']);
        DB::table('keloola_sso_memberships')->insertOrIgnore([
            'organization_id' => $org->sso_id, 'user_id' => $p['sso_id'], 'role_id' => $p['role_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('keloola_sso_invitations')->where('organization_id', $org->sso_id)->where('email', $p['email'])->where('status', 'pending')->update(['status' => 'accepted', 'updated_at' => now()]);
        return $this->memberData($org, $p['sso_id'], (int) $p['role_id']);
    }

    private function changeMemberRole(object $org, string $userId, int $roleId): array
    {
        if ($userId === $org->owner_sso_id) throw new IntegrationException('owner_protected', 'Owner role cannot be changed.');
        $role = $this->role($org, $roleId);
        if ($role->is_default) throw new IntegrationException('owner_role_protected', 'Owner role cannot be assigned to another member.');
        $query = DB::table('keloola_sso_memberships')->where('organization_id', $org->sso_id)->where('user_id', $userId);
        if (!$query->exists()) throw new IntegrationException('member_not_found', 'Membership not found.', 404);
        $query->update(['role_id' => $roleId, 'updated_at' => now()]);
        return $this->memberData($org, $userId, $roleId);
    }

    private function removeMember(object $org, string $userId): array
    {
        if ($userId === $org->owner_sso_id) throw new IntegrationException('owner_protected', 'Owner membership cannot be removed.');
        DB::table('keloola_sso_memberships')->where('organization_id', $org->sso_id)->where('user_id', $userId)->delete();
        return ['sso_organization_id' => $org->sso_id, 'sso_id' => $userId, 'membership_status' => 'removed'];
    }

    private function roleData(object $role): array
    {
        return ['id' => (int) $role->id, 'name' => $role->name, 'description' => $role->description, 'permissions' => json_decode($role->permissions, true), 'is_default' => (bool) $role->is_default, 'is_delete' => (bool) $role->is_delete];
    }

    private function storeRole(object $org, array $p): array
    {
        $permissions = $this->permissions($p['permissions']);
        $existing = DB::table('keloola_sso_roles')->where('organization_id', $org->sso_id)->where('sso_role_id', $p['sso_role_id'])->first();
        if ($existing) {
            if ($existing->name !== $p['name'] || $existing->description !== ($p['description'] ?? null) || json_decode($existing->permissions, true) !== $permissions) {
                throw new IntegrationException('role_conflict', 'Source role already exists with different values.');
            }
            return $this->roleData($existing);
        }
        $id = DB::table('keloola_sso_roles')->insertGetId([
            'organization_id' => $org->sso_id, 'sso_role_id' => $p['sso_role_id'], 'name' => $p['name'], 'description' => $p['description'] ?? null,
            'permissions' => json_encode($permissions), 'is_default' => false, 'is_delete' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->roleData($this->role($org, $id));
    }

    private function updateRole(object $org, int $id, array $p): array
    {
        $role = $this->role($org, $id);
        if ($role->is_default) throw new IntegrationException('owner_role_protected', 'Owner role is protected.');
        DB::table('keloola_sso_roles')->where('id', $id)->where('organization_id', $org->sso_id)->update([
            'name' => $p['name'], 'description' => $p['description'] ?? null, 'permissions' => json_encode($this->permissions($p['permissions'])), 'updated_at' => now(),
        ]);
        return $this->roleData($this->role($org, $id));
    }

    private function removeRole(object $org, int $id): array
    {
        $role = DB::table('keloola_sso_roles')->where('organization_id', $org->sso_id)->where('id', $id)->first();
        if ($role && (!$role->is_delete || DB::table('keloola_sso_memberships')->where('organization_id', $org->sso_id)->where('role_id', $id)->exists()
            || DB::table('keloola_sso_invitations')->where('organization_id', $org->sso_id)->where('role_id', $id)->where('status', 'pending')->exists())) {
            throw new IntegrationException('role_in_use', 'Role is protected or still in use.');
        }
        DB::table('keloola_sso_roles')->where('organization_id', $org->sso_id)->where('id', $id)->delete();
        return ['id' => $id, 'deleted' => true];
    }

    private function invite(array $p): array
    {
        $org = $this->organization($p['sso_organization_id']);
        $role = $this->role($org, (int) $p['role_id']);
        if ($role->is_default) throw new IntegrationException('owner_role_protected', 'Owner role cannot be invited.');
        $alreadyMember = DB::table('keloola_sso_memberships as m')->join('keloola_sso_users as u', 'u.sso_id', '=', 'm.user_id')
            ->where('m.organization_id', $org->sso_id)->where('u.email', $p['email'])->exists();
        DB::table('keloola_sso_invitations')->insertOrIgnore([
            'sso_id' => $p['sso_invitation_id'], 'organization_id' => $org->sso_id, 'email' => $p['email'], 'role_id' => $p['role_id'],
            'expires_at' => Carbon::parse($p['expires_at'])->utc()->format('Y-m-d H:i:s'),
            'status' => $alreadyMember ? 'accepted' : (Carbon::parse($p['expires_at'])->isPast() ? 'removed' : 'pending'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('keloola_sso_invitations')->where('sso_id', $p['sso_invitation_id'])->lockForUpdate()->first();
        if ($row->organization_id !== null && ($row->organization_id !== $org->sso_id || $row->email !== $p['email'] || (int) $row->role_id !== (int) $p['role_id'] || $row->expires_at !== Carbon::parse($p['expires_at'])->utc()->format('Y-m-d H:i:s'))) {
            throw new IntegrationException('invitation_conflict', 'Invitation ID already has different values.');
        }
        return ['sso_invitation_id' => $row->sso_id, 'status' => $row->status];
    }

    private function cancelInvitation(string $id): array
    {
        DB::table('keloola_sso_invitations')->insertOrIgnore(['sso_id' => $id, 'status' => 'removed', 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('keloola_sso_invitations')->where('sso_id', $id)->lockForUpdate()->first();
        if ($row->status !== 'accepted') DB::table('keloola_sso_invitations')->where('sso_id', $id)->update(['status' => 'removed', 'updated_at' => now()]);
        return ['sso_invitation_id' => $id, 'status' => $row->status === 'accepted' ? 'accepted' : 'removed'];
    }
}

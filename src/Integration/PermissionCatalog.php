<?php

namespace Keloola\KeloolaSsoAuthorize\Integration;

use Illuminate\Database\Eloquent\Model;

/** Shared catalog for export, role validation, provisioning, and local checks. */
class PermissionCatalog
{
    public function all(): array
    {
        $source = config('keloolauthorize.integration.permission_source', 'array');
        $items = match ($source) {
            'array' => config('keloolauthorize.integration.permissions', []),
            'spatie' => $this->spatie(),
            default => $this->unavailable(),
        };
        if (!is_array($items)) $this->unavailable();
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'], $item['name'], $item['guard_name'])
                || !is_int($item['id']) || $item['id'] < 1 || in_array($item['id'], $ids, true)
                || !is_string($item['name']) || $item['name'] === '' || !is_string($item['guard_name'])) {
                $this->unavailable();
            }
            $ids[] = $item['id'];
        }
        return array_values($items);
    }

    private function spatie(): array
    {
        $model = config('permission.models.permission');
        $guard = config('keloolauthorize.integration.spatie.guard_name', 'web');
        $groupAttribute = config('keloolauthorize.integration.spatie.group_attribute');
        if (!is_string($model) || !is_subclass_of($model, Model::class)
            || !is_string($guard) || $guard === ''
            || ($groupAttribute !== null && (!is_string($groupAttribute) || $groupAttribute === ''))) {
            $this->unavailable();
        }
        try {
            $instance = new $model;
            return $instance->newQuery()->where('guard_name', $guard)->orderBy($instance->getKeyName())->get()
                ->map(function ($permission) use ($groupAttribute) {
                    $rawId = $permission->getRawOriginal($permission->getKeyName());
                    $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) $this->unavailable();
                    $group = $groupAttribute === null ? 'general' : ($permission->getAttribute($groupAttribute) ?? 'general');
                    if (!is_string($group)) $this->unavailable();
                    return ['id' => $id, 'name' => $permission->getAttribute('name'),
                        'group' => $group, 'guard_name' => $permission->getAttribute('guard_name')];
                })->all();
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Never fall back to a different catalog or expose database details.
            $this->unavailable();
        }
    }

    private function unavailable()
    {
        throw new IntegrationException('integration_unavailable', 'Invalid permission catalog configuration.', 503);
    }
}

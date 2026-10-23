<?php

namespace App\Auth;

use App\Models\AdminUser;
use App\Models\Client;

/**
 * What each admin may do. 'full' admins can do everything. 'limited' admins only get the
 * permissions in PERMISSIONS that were granted to them; anything else is denied (missing or
 * malformed data counts as no permission).
 *
 * Stored permissions (admin_users.permissions, JSON):
 *   {"reports": {"scope": "all"}}                                   every client
 *   {"reports": {"scope": "clients", "clientIds": ["<uuid>", ...]}} only those clients
 */
final class AdminAccess
{
    public const FULL = 'full';

    public const LIMITED = 'limited';

    /** Permissions a limited admin can be granted (key => label). Add new ones here. */
    public const PERMISSIONS = ['reports' => 'Ver reportes'];

    public static function isFull(AdminUser $admin): bool
    {
        return $admin->access === self::FULL;
    }

    /**
     * Clients whose reports the admin can see: null = every client (also assessments without a
     * client), a list = only those, [] = none.
     *
     * @return list<string>|null
     */
    public static function reportClientIds(AdminUser $admin): ?array
    {
        if (self::isFull($admin)) {
            return null;
        }
        $reports = is_array($admin->permissions) ? ($admin->permissions['reports'] ?? null) : null;
        if (! is_array($reports)) {
            return [];
        }
        if (($reports['scope'] ?? null) === 'all') {
            return null;
        }
        if (($reports['scope'] ?? null) === 'clients' && is_array($reports['clientIds'] ?? null)) {
            return array_values(array_filter($reports['clientIds'], 'is_string'));
        }

        return [];
    }

    /** Can the admin see the reports of this client's assessments? (null = without a client) */
    public static function canViewReportsOf(AdminUser $admin, ?string $clientId): bool
    {
        $ids = self::reportClientIds($admin);

        return $ids === null || ($clientId !== null && in_array($clientId, $ids, true));
    }

    /**
     * Data the UI receives about an admin's access.
     *
     * @return array{full: bool, reports: array{all: bool, clientIds: list<string>}|null}
     */
    public static function toArray(AdminUser $admin): array
    {
        if (self::isFull($admin)) {
            return ['full' => true, 'reports' => null];
        }
        $ids = self::reportClientIds($admin);
        $granted = $ids === null || $ids !== [];

        return ['full' => false, 'reports' => $granted ? ['all' => $ids === null, 'clientIds' => $ids ?? []] : null];
    }

    /**
     * Reads and validates the access fields of the users form: `access`, `permissions` (list of
     * keys), `reportsScope` ('all'|'clients') and `reportsClientIds`.
     *
     * @return array{ok: true, access: string, permissions: ?array}|array{ok: false, errors: array<string, string>}
     */
    public static function fromInput(array $input): array
    {
        $access = $input['access'] ?? null;
        if ($access === self::FULL) {
            return ['ok' => true, 'access' => self::FULL, 'permissions' => null];
        }
        if ($access !== self::LIMITED) {
            return ['ok' => false, 'errors' => ['access' => 'Elija un nivel de acceso.']];
        }

        $keys = array_values(array_intersect(array_keys(self::PERMISSIONS), (array) ($input['permissions'] ?? [])));
        if (! $keys) {
            return ['ok' => false, 'errors' => ['permissions' => 'Elija al menos un permiso.']];
        }

        $permissions = [];
        if (in_array('reports', $keys, true)) {
            if (($input['reportsScope'] ?? null) === 'all') {
                $permissions['reports'] = ['scope' => 'all'];
            } else {
                $ids = array_values(array_unique(array_filter((array) ($input['reportsClientIds'] ?? []), 'is_string')));
                if (! $ids) {
                    return ['ok' => false, 'errors' => ['reportsClientIds' => 'Elija al menos un cliente.']];
                }
                if (count($ids) > 500 || Client::whereKey($ids)->count() !== count($ids)) {
                    return ['ok' => false, 'errors' => ['reportsClientIds' => 'Uno de los clientes elegidos no existe.']];
                }
                $permissions['reports'] = ['scope' => 'clients', 'clientIds' => $ids];
            }
        }

        return ['ok' => true, 'access' => self::LIMITED, 'permissions' => $permissions];
    }
}

<?php
declare(strict_types=1);

namespace App\Models;

final class Role
{
    public const CONFIG_GRANT = 'config.write';

    public const DEFS = [
        'owner' => [
            'label' => 'Owner',
            'desc' => 'Accesso completo, incluse impostazioni globali del server.',
            'admin' => true,
            'grants' => ['*', 'config.write'],
        ],
        'admin' => [
            'label' => 'Admin',
            'desc' => 'Gestisce utenti, ruoli, audit e job. Non tocca la configurazione globale.',
            'admin' => true,
            'grants' => ['users.read', 'users.write', 'roles.read', 'roles.write', 'audit.read', 'audit.export', 'jobs.read'],
        ],
        'operator' => [
            'label' => 'Operatore',
            'desc' => 'Legge utenti e audit, non puo modificarli.',
            'admin' => false,
            'grants' => ['users.read', 'audit.read', 'jobs.read'],
        ],
        'user' => [
            'label' => 'Utente',
            'desc' => 'Accesso solo ai propri dati.',
            'admin' => false,
            'grants' => [],
        ],
    ];

    public const DEFAULT = 'user';

    public static function all(): array
    {
        return self::DEFS;
    }

    public static function exists(string $role): bool
    {
        return isset(self::DEFS[$role]);
    }

    public static function normalize(string $role): ?string
    {
        $role = strtolower(trim($role));
        return self::exists($role) ? $role : null;
    }

    public static function isAdmin(?string $role): bool
    {
        if ($role === null) return false;
        return (bool)(self::DEFS[$role]['admin'] ?? false);
    }

    public static function grants(string $role): array
    {
        $r = self::normalize($role);
        if ($r === null) return [];
        return (array)(self::DEFS[$r]['grants'] ?? []);
    }

    public static function grantsMatrix(): array
    {
        $all = [];
        foreach (self::DEFS as $d) {
            foreach ($d['grants'] as $g) $all[$g] = $g;
        }
        ksort($all);
        return array_values($all);
    }
}

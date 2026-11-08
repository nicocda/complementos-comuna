<?php

namespace App\Support;

/**
 * Single source of truth for roles and permissions.
 *
 * Permissions are prefixed by module ("modulo.accion") so new internal
 * modules can add their own without touching existing ones.
 */
final class Permissions
{
    public const USERS_MANAGE = 'usuarios.gestionar';

    public const FLEET_VIEW = 'flota.ver';

    public const FLEET_MANAGE = 'flota.gestionar';

    public const REPAIRS_VIEW_ALL = 'reparaciones.ver_todas';

    public const REPAIRS_REPORT = 'reparaciones.reportar';

    public const REPAIRS_ASSESS = 'reparaciones.informar';

    public const REPAIRS_APPROVE = 'reparaciones.aprobar';

    public const REPAIRS_TRACK = 'reparaciones.seguimiento';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPERIOR = 'superior';

    public const ROLE_SUPERVISOR = 'encargado';

    public const ROLE_DRIVER = 'conductor';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::USERS_MANAGE,
            self::FLEET_VIEW,
            self::FLEET_MANAGE,
            self::REPAIRS_VIEW_ALL,
            self::REPAIRS_REPORT,
            self::REPAIRS_ASSESS,
            self::REPAIRS_APPROVE,
            self::REPAIRS_TRACK,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_ADMIN => self::all(),
            self::ROLE_SUPERIOR => [
                self::FLEET_VIEW,
                self::REPAIRS_VIEW_ALL,
                self::REPAIRS_APPROVE,
            ],
            self::ROLE_SUPERVISOR => [
                self::FLEET_VIEW,
                self::FLEET_MANAGE,
                self::REPAIRS_VIEW_ALL,
                self::REPAIRS_REPORT,
                self::REPAIRS_ASSESS,
                self::REPAIRS_TRACK,
            ],
            self::ROLE_DRIVER => [
                self::REPAIRS_REPORT,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            self::ROLE_ADMIN => 'Administrador',
            self::ROLE_SUPERIOR => 'Superior',
            self::ROLE_SUPERVISOR => 'Encargado',
            self::ROLE_DRIVER => 'Conductor',
        ];
    }
}

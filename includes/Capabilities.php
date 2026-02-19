<?php
declare(strict_types=1);

namespace PlatzStatus;

final class Capabilities
{
    /**
     * Custom capability used across admin pages, meta boxes and admin_post actions.
     * Keep this stable once in production.
     */
    public const CAP = 'platzstatus_manage';

    /**
     * Role(s) that should have access.
     * You can add more later (e.g. 'editor'), but start strict.
     */
    private const ROLES = ['administrator'];

    public static function activate(): void
    {
        self::addCaps();
    }

    public static function deactivate(): void
    {
        // Optional: keep caps on deactivate to avoid locking yourself out by accident.
        // If you really want to remove them, uncomment:
        // self::removeCaps();
    }

    /**
     * Self-heal: ensure caps exist even if activation hook didn't run.
     * Safe to call repeatedly.
     */
    public static function ensure(): void
    {
        self::addCaps();
    }

    private static function addCaps(): void
    {
        foreach (self::ROLES as $roleName) {
            $role = get_role($roleName);
            if (!$role) {
                continue;
            }

            if (!$role->has_cap(self::CAP)) {
                $role->add_cap(self::CAP);
            }
        }
    }

    private static function removeCaps(): void
    {
        foreach (self::ROLES as $roleName) {
            $role = get_role($roleName);
            if (!$role) {
                continue;
            }

            if ($role->has_cap(self::CAP)) {
                $role->remove_cap(self::CAP);
            }
        }
    }
}

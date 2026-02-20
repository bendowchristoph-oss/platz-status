<?php
declare(strict_types=1);

namespace PlatzStatus;

final class Plugin
{
    /**
     * Backward compatible entrypoint.
     * platz-status.php erwartet Plugin::boot().
     */
    public static function boot(): void
    {
        self::register();
    }

    /**
     * Register hooks.
     */
    public static function register(): void
    {
        // Core hooks (falls ihr hier später mehr braucht)
        add_action('init', [self::class, 'init']);

        if (is_admin()) {
            add_action('init', static function (): void {
                // Diese Calls müssen existieren in eurem Plugin – falls nicht, rausnehmen.
                if (class_exists(\PlatzStatus\Admin\ImpactMetaBoxes::class)) {
                    \PlatzStatus\Admin\ImpactMetaBoxes::register();
                }
                if (class_exists(\PlatzStatus\Admin\TournamentMetaBoxes::class)) {
                    \PlatzStatus\Admin\TournamentMetaBoxes::register();
                }
                if (class_exists(\PlatzStatus\Admin\TournamentScorecardController::class)) {
                    \PlatzStatus\Admin\TournamentScorecardController::register();
                }
                if (class_exists(\PlatzStatus\Admin\TournamentPrizesController::class)) {
                    \PlatzStatus\Admin\TournamentPrizesController::register();
                }
            }, 20);
        }
    }

    public static function init(): void
    {
        // intentionally minimal
    }
}

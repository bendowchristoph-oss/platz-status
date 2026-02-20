<?php
declare(strict_types=1);

namespace PlatzStatus;

final class Plugin
{
    public static function register(): void
    {
        // Core hooks
        add_action('init', [self::class, 'init']);

        if (is_admin()) {
            add_action('init', static function (): void {
                \PlatzStatus\Admin\ImpactMetaBoxes::register();
                \PlatzStatus\Admin\TournamentMetaBoxes::register();
                \PlatzStatus\Admin\TournamentScorecardController::register();

                // NEW: Nebenpreise/Wertungen
                \PlatzStatus\Admin\TournamentPrizesController::register();
            }, 20);
        }
    }

    public static function init(): void
    {
        // intentionally minimal; other components register via their own hooks
    }
}

<?php
declare(strict_types=1);

namespace PlatzStatus;

final class Plugin
{
    public static function boot(): void
    {
        /**
         * Shared (ADMIN + PUBLIC): immer laden, damit Klassen verfügbar sind,
         * bevor Admin-MetaBoxen/Controller darauf zugreifen.
         */
        self::requireIfExists('includes/Services/Engine.php');
        self::requireIfExists('includes/Services/TournamentOptions.php');
        self::requireIfExists('includes/Services/ResultsRepository.php');

        // Admin
        if (is_admin()) {
            self::requireIfExists('includes/Admin/SettingsPage.php');
            self::requireIfExists('includes/Admin/ImpactMetaBoxes.php');
            self::requireIfExists('includes/Admin/AlbatrosImportPage.php');
            self::requireIfExists('includes/Admin/TournamentMetaBoxes.php');
            self::requireIfExists('includes/Admin/TournamentParticipantsController.php');
            self::requireIfExists('includes/Admin/TournamentScorecardController.php');

            if (class_exists(\PlatzStatus\Admin\SettingsPage::class)) {
                \PlatzStatus\Admin\SettingsPage::register();
            }
            if (class_exists(\PlatzStatus\Admin\ImpactMetaBoxes::class)) {
                \PlatzStatus\Admin\ImpactMetaBoxes::register();
            }
            if (class_exists(\PlatzStatus\Admin\AlbatrosImportPage::class)) {
                \PlatzStatus\Admin\AlbatrosImportPage::register();
            }
            if (class_exists(\PlatzStatus\Admin\TournamentMetaBoxes::class)) {
                \PlatzStatus\Admin\TournamentMetaBoxes::register();
            }
            if (class_exists(\PlatzStatus\Admin\TournamentParticipantsController::class)) {
                \PlatzStatus\Admin\TournamentParticipantsController::register();
            }
            if (class_exists(\PlatzStatus\Admin\TournamentScorecardController::class)) {
                \PlatzStatus\Admin\TournamentScorecardController::register();
            }
        }

        // Public
        self::requireIfExists('includes/Public/Shortcodes.php');
        if (class_exists(\PlatzStatus\PublicSide\Shortcodes::class)) {
            \PlatzStatus\PublicSide\Shortcodes::register();
        }
    }

    private static function requireIfExists(string $relPath): void
    {
        $file = PLATZ_STATUS_PATH . ltrim($relPath, '/');
        if (is_readable($file)) {
            require_once $file;
        }
    }
}

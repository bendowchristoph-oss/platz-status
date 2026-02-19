<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;
use PlatzStatus\Services\TournamentOptions;

/**
 * Placeholder für Nebenpreise/Wertungen (Longest Drive, Nearest to the Pin, etc.).
 * Aktuell nur als "saubere" leere Hülle, damit das Plugin nicht crasht,
 * und wir später hier die UI + Speicherung ergänzen können.
 */
final class TournamentPrizesController
{
    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'addMetaBoxes']);
        // save / admin_post kommt später, wenn wir Felder haben
    }

    public static function addMetaBoxes(): void
    {
        $pt = TournamentOptions::impactPostType();

        add_meta_box(
            'ps_tournament_prizes',
            'Nebenpreise & Wertungen',
            [self::class, 'renderBox'],
            $pt,
            'normal',
            'default'
        );
    }

    public static function renderBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            echo '<p>Keine Berechtigung.</p>';
            return;
        }

        if (!TournamentOptions::isTournament($post->ID)) {
            echo '<p class="description">Aktiviere zuerst oben „Dieses Ereignis ist ein Turnier“.</p>';
            return;
        }

        echo '<p class="description">Hier kommen Nebenpreise (Nearest to the Pin, Longest Drive etc.) und Wertungen rein. (Noch nicht implementiert.)</p>';
    }
}

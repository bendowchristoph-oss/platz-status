<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;
use PlatzStatus\Services\TournamentOptions;

final class TournamentMetaBoxes
{
    private const NONCE_ACTION = 'ps_save_tournament_options';
    private const NONCE_NAME   = 'ps_tournament_nonce';
    private const ACTION_SAVE  = 'ps_save_tournament_options';
    private const PAGE_SLUG    = 'platzstatus-tournament-options';

    // Nebenpreise: berechtigte Gruppen als CSV im Post-Meta, z.B. "guest,member"
    private const META_SIDEPRIZE_ELIGIBLE_GROUPS = 'ps_sideprize_eligible_groups';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensureCaps'], 1);

        add_action('add_meta_boxes', [self::class, 'addMetaBox']);

        // Separate admin page (stable form submit)
        add_action('admin_menu', [self::class, 'registerAdminPage']);

        // Save handler for the separate page
        add_action('admin_post_' . self::ACTION_SAVE, [self::class, 'handleSave']);
    }

    public static function ensureCaps(): void
    {
        if (class_exists(\PlatzStatus\Capabilities::class)) {
            Capabilities::ensure();
        }
    }

    public static function addMetaBox(): void
    {
        $pt = TournamentOptions::impactPostType();

        add_meta_box(
            'ps_tournament_options',
            'Turnier-Optionen',
            [self::class, 'renderMetaBox'],
            $pt,
            'side',
            'default'
        );
    }

    public static function registerAdminPage(): void
    {
        // hidden page (no menu entry), opened via link from metabox
        add_submenu_page(
            null,
            'Turnier-Optionen',
            'Turnier-Optionen',
            Capabilities::CAP,
            self::PAGE_SLUG,
            [self::class, 'renderAdminPage']
        );
    }

    public static function renderMetaBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            echo '<p>Keine Berechtigung.</p>';
            return;
        }

        $postId = (int) $post->ID;

        $optionsUrl = add_query_arg(
            ['page' => self::PAGE_SLUG, 'post_id' => $postId],
            admin_url('admin.php')
        );

        $enabled      = (int) get_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, true) === 1;
        $isTournament = TournamentOptions::isTournament($postId);
        $holes        = TournamentOptions::holes($postId);

        $scorecardsUrl = add_query_arg(
            ['page' => 'platzstatus-scorecards', 'tournament_post_id' => $postId, 'round_id' => 1],
            admin_url('admin.php')
        );

        echo '<p style="margin:0 0 10px 0;">Status: '
            . ($isTournament ? '<strong>Turnier</strong>' : '<strong>Kein Turnier</strong>')
            . ' • Löcher: <strong>' . esc_html((string)$holes) . '</strong>'
            . ' • Scorecards: ' . ($enabled ? '<strong>an</strong>' : '<strong>aus</strong>')
            . '</p>';

        echo '<p style="margin:0 0 8px 0;">'
            . '<a class="button button-primary" style="width:100%; text-align:center;" href="'
            . esc_url($optionsUrl)
            . '">Turnier-Optionen bearbeiten</a></p>';

        if ($isTournament && $enabled) {
            echo '<p style="margin:0 0 8px 0;">'
                . '<a class="button" style="width:100%; text-align:center;" href="'
                . esc_url($scorecardsUrl)
                . '">Scorecards öffnen</a></p>';
        } else {
            echo '<p class="description" style="margin:0 0 8px 0;">'
                . 'Scorecards-Link erscheint erst, wenn Turnier + Loch-für-Loch aktiviert ist.'
                . '</p>';
        }

        echo '<p class="description" style="margin-top:8px;">'
            . 'Speichern erfolgt über die separate Seite (stabil im Block-Editor).'
            . '</p>';
    }

    private static function parseEligibleGroups(int $postId): array
    {
        $raw = (string) get_post_meta($postId, self::META_SIDEPRIZE_ELIGIBLE_GROUPS, true);
        if ($raw === '') {
            // backward compatible: bisher nur Gäste => Default guest
            return ['guest'];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $parts = array_map('strtolower', $parts);

        $allowed = ['guest', 'member'];
        $groups  = array_values(array_unique(array_intersect($parts, $allowed)));

        return $groups !== [] ? $groups : ['guest'];
    }

    private static function storeEligibleGroups(int $postId, array $groups): void
    {
        $groups = array_map('strtolower', array_map('trim', $groups));
        $allowed = ['guest', 'member'];

        $groups = array_values(array_unique(array_intersect($groups, $allowed)));
        if ($groups === []) {
            $groups = ['guest'];
        }

        update_post_meta($postId, self::META_SIDEPRIZE_ELIGIBLE_GROUPS, implode(',', $groups));
    }

    public static function renderAdminPage(): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            wp_die('Keine Berechtigung.');
        }

        $postId = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        if ($postId <= 0) {
            wp_die('Ungültige post_id.');
        }

        $pt = TournamentOptions::impactPostType();
        if (get_post_type($postId) !== $pt) {
            wp_die('Falscher Post Type.');
        }

        $isTournament = TournamentOptions::isTournament($postId);
        $holes = TournamentOptions::holes($postId);
        if ($holes !== 9 && $holes !== 18) {
            $holes = 18;
        }
        $enableScorecards = (int) get_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, true) === 1;

        $eligibleGroups = self::parseEligibleGroups($postId);
        $guestsEnabled  = in_array('guest', $eligibleGroups, true);
        $membersEnabled = in_array('member', $eligibleGroups, true);

        $actionUrl = admin_url('admin-post.php');
        $backUrl = add_query_arg(['post' => $postId, 'action' => 'edit'], admin_url('post.php'));

        echo '<div class="wrap">';
        echo '<h1>Turnier-Optionen</h1>';
        echo '<p><a href="' . esc_url($backUrl) . '">&larr; zurück zum Ereignis</a></p>';

        if (!empty($_GET['ps_saved'])) {
            echo '<div class="notice notice-success"><p><strong>Gespeichert.</strong></p></div>';
        }

        echo '<form method="post" action="' . esc_url($actionUrl) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr((string)$postId) . '">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">Turnier</th><td>';
        echo '<label><input type="checkbox" name="ps_is_tournament" value="1" ' . checked($isTournament, true, false) . '> Dieses Ereignis ist ein Turnier</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Löcher</th><td>';
        echo '<select name="ps_holes">';
        echo '<option value="9" ' . selected($holes, 9, false) . '>9</option>';
        echo '<option value="18" ' . selected($holes, 18, false) . '>18</option>';
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Scorecards</th><td>';
        echo '<label><input type="checkbox" name="ps_enable_scorecards" value="1" ' . checked($enableScorecards, true, false) . '> Loch-für-Loch Erfassung</label>';
        echo '<p class="description">Nur aktiv, wenn Turnier aktiv ist.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Nebenpreise</th><td>';
        echo '<label><input type="checkbox" name="ps_sideprize_groups[]" value="guest" ' . checked($guestsEnabled, true, false) . '> Gäste</label><br>';
        echo '<label><input type="checkbox" name="ps_sideprize_groups[]" value="member" ' . checked($membersEnabled, true, false) . '> Mitglieder</label>';
        echo '<p class="description">Welche Gruppen dürfen Nebenpreise gewinnen?</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button('Speichern');
        echo '</form>';
        echo '</div>';
    }

    public static function handleSave(): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            wp_die('Keine Berechtigung.');
        }

        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($postId <= 0) {
            wp_die('Ungültige post_id.');
        }

        $pt = TournamentOptions::impactPostType();
        if (get_post_type($postId) !== $pt) {
            wp_die('Falscher Post Type.');
        }

        if (empty($_POST[self::NONCE_NAME]) || !wp_verify_nonce((string) $_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
            wp_die('Nonce ungültig.');
        }

        $isTournament = !empty($_POST['ps_is_tournament']) ? 1 : 0;
        update_post_meta($postId, TournamentOptions::META_IS_TOURNAMENT, $isTournament);

        $holes = isset($_POST['ps_holes']) ? (int) $_POST['ps_holes'] : 18;
        if ($holes !== 9 && $holes !== 18) {
            $holes = 18;
        }
        update_post_meta($postId, TournamentOptions::META_HOLES, $holes);

        $enableScorecards = (!empty($_POST['ps_enable_scorecards']) && $isTournament === 1) ? 1 : 0;
        update_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, $enableScorecards);

        $groups = isset($_POST['ps_sideprize_groups']) && is_array($_POST['ps_sideprize_groups'])
            ? $_POST['ps_sideprize_groups']
            : ['guest'];

        self::storeEligibleGroups($postId, $groups);

        $url = add_query_arg(
            ['page' => self::PAGE_SLUG, 'post_id' => $postId, 'ps_saved' => '1'],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}

<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;
use PlatzStatus\Services\TournamentOptions;

final class TournamentPrizesController
{
    private const NONCE_ACTION = 'ps_save_tournament_prizes';
    private const NONCE_NAME   = 'ps_tournament_prizes_nonce';
    private const ACTION_SAVE  = 'ps_save_tournament_prizes';
    private const PAGE_SLUG    = 'platzstatus-tournament-prizes';

    // Turnierweite Gruppen-Auswahl aus TournamentMetaBoxes.php
    private const META_TOURNAMENT_ELIGIBLE_GROUPS = 'ps_sideprize_eligible_groups';

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'addMetaBoxes']);

        // hidden admin page (stable submit)
        add_action('admin_menu', [self::class, 'registerAdminPage']);

        // save handler
        add_action('admin_post_' . self::ACTION_SAVE, [self::class, 'handleSave']);
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

    public static function registerAdminPage(): void
    {
        add_submenu_page(
            null,
            'Nebenpreise & Wertungen',
            'Nebenpreise & Wertungen',
            Capabilities::CAP,
            self::PAGE_SLUG,
            [self::class, 'renderAdminPage']
        );
    }

    public static function renderBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            echo '<p>Keine Berechtigung.</p>';
            return;
        }

        $postId = (int) $post->ID;

        if (!TournamentOptions::isTournament($postId)) {
            echo '<p class="description">Aktiviere zuerst oben „Dieses Ereignis ist ein Turnier“.</p>';
            return;
        }

        $url = add_query_arg(
            ['page' => self::PAGE_SLUG, 'post_id' => $postId],
            admin_url('admin.php')
        );

        echo '<p class="description">Pflege Nebenpreise (Nearest to the Pin, Longest Drive etc.) und Wertungen.</p>';
        echo '<p style="margin:0 0 8px 0;">'
            . '<a class="button button-primary" href="' . esc_url($url) . '">Nebenpreise bearbeiten</a>'
            . '</p>';
        echo '<p class="description" style="margin-top:8px;">Speichern erfolgt über die separate Seite (stabil im Block-Editor).</p>';
    }

    private static function tournamentEligibleGroups(int $postId): array
    {
        $raw = (string) get_post_meta($postId, self::META_TOURNAMENT_ELIGIBLE_GROUPS, true);
        if ($raw === '') {
            return ['guest']; // backward compatible default
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $parts = array_map('strtolower', $parts);

        $allowed = ['guest', 'member'];
        $groups  = array_values(array_unique(array_intersect($parts, $allowed)));

        return $groups !== [] ? $groups : ['guest'];
    }

    private static function parsePrizeEligibleGroups(?string $json, array $fallback): array
    {
        if (!$json) {
            return $fallback;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $fallback;
        }

        $groups = $data['eligible_groups'] ?? null;
        if (!is_array($groups)) {
            return $fallback;
        }

        $groups = array_map('strtolower', array_map('trim', $groups));
        $allowed = ['guest', 'member'];
        $groups = array_values(array_unique(array_intersect($groups, $allowed)));

        return $groups !== [] ? $groups : $fallback;
    }

    private static function buildRestrictionsJson(array $groups): string
    {
        $groups = array_map('strtolower', array_map('trim', $groups));
        $allowed = ['guest', 'member'];
        $groups = array_values(array_unique(array_intersect($groups, $allowed)));

        if ($groups === []) {
            $groups = ['guest'];
        }

        return (string) wp_json_encode(['eligible_groups' => $groups], JSON_UNESCAPED_UNICODE);
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

        if (!TournamentOptions::isTournament($postId)) {
            wp_die('Dieses Ereignis ist nicht als Turnier markiert.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gc_prizes';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, scope, hole_no, unit, label, restrictions_json, is_active, sort_order
                 FROM {$table}
                 WHERE tournament_post_id = %d
                 ORDER BY sort_order ASC, id ASC",
                $postId
            ),
            ARRAY_A
        );

        $tournamentGroups = self::tournamentEligibleGroups($postId);
        $tGuestsEnabled  = in_array('guest', $tournamentGroups, true);
        $tMembersEnabled = in_array('member', $tournamentGroups, true);

        $actionUrl = admin_url('admin-post.php');
        $backUrl = add_query_arg(['post' => $postId, 'action' => 'edit'], admin_url('post.php'));

        echo '<div class="wrap">';
        echo '<h1>Nebenpreise &amp; Wertungen</h1>';
        echo '<p><a href="' . esc_url($backUrl) . '">&larr; zurück zum Ereignis</a></p>';

        if (!empty($_GET['ps_saved'])) {
            echo '<div class="notice notice-success"><p><strong>Gespeichert.</strong></p></div>';
        }

        echo '<div class="notice notice-info"><p>';
        echo '<strong>Turnier-Berechtigung:</strong> ';
        echo $tGuestsEnabled ? 'Gäste' : '';
        echo ($tGuestsEnabled && $tMembersEnabled) ? ' + ' : '';
        echo $tMembersEnabled ? 'Mitglieder' : '';
        echo ' — Nebenpreise können hier pro Preis weiter eingeschränkt werden.';
        echo '</p></div>';

        echo '<form method="post" action="' . esc_url($actionUrl) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr((string)$postId) . '">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        echo '<h2>Bestehende Nebenpreise</h2>';

        if (empty($rows)) {
            echo '<p class="description">Noch keine Nebenpreise angelegt.</p>';
        } else {
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th style="width:60px;">ID</th>';
            echo '<th>Label</th>';
            echo '<th style="width:120px;">Typ</th>';
            echo '<th style="width:110px;">Scope</th>';
            echo '<th style="width:80px;">Loch</th>';
            echo '<th style="width:90px;">Einheit</th>';
            echo '<th style="width:170px;">Berechtigt</th>';
            echo '<th style="width:90px;">Aktiv</th>';
            echo '<th style="width:90px;">Sort</th>';
            echo '</tr></thead><tbody>';

            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $prizeGroups = self::parsePrizeEligibleGroups(
                    is_string($r['restrictions_json']) ? $r['restrictions_json'] : null,
                    $tournamentGroups
                );

                // clamp to tournament groups
                $prizeGroups = array_values(array_intersect($prizeGroups, $tournamentGroups));
                if ($prizeGroups === []) {
                    $prizeGroups = $tournamentGroups;
                }

                $pGuestsEnabled  = in_array('guest', $prizeGroups, true);
                $pMembersEnabled = in_array('member', $prizeGroups, true);

                echo '<tr>';
                echo '<td>' . esc_html((string)$id) . '<input type="hidden" name="prize_id[]" value="' . esc_attr((string)$id) . '"></td>';

                echo '<td><input type="text" class="regular-text" name="label[' . esc_attr((string)$id) . ']" value="' . esc_attr((string)$r['label']) . '"></td>';

                echo '<td><input type="text" name="type[' . esc_attr((string)$id) . ']" value="' . esc_attr((string)$r['type']) . '" style="width:100%;"></td>';

                echo '<td><select name="scope[' . esc_attr((string)$id) . ']">';
                $scope = (string)$r['scope'];
                echo '<option value="hole" ' . selected($scope, 'hole', false) . '>hole</option>';
                echo '<option value="tournament" ' . selected($scope, 'tournament', false) . '>tournament</option>';
                echo '</select></td>';

                $holeNo = $r['hole_no'] !== null ? (int) $r['hole_no'] : 0;
                echo '<td><input type="number" min="1" max="18" name="hole_no[' . esc_attr((string)$id) . ']" value="' . esc_attr($holeNo > 0 ? (string)$holeNo : '') . '" style="width:70px;"></td>';

                echo '<td><input type="text" name="unit[' . esc_attr((string)$id) . ']" value="' . esc_attr((string)$r['unit']) . '" style="width:80px;"></td>';

                echo '<td>';
                echo '<label style="display:block; margin-bottom:4px;">'
                    . '<input type="checkbox" name="groups[' . esc_attr((string)$id) . '][]" value="guest" '
                    . checked($pGuestsEnabled, true, false)
                    . ($tGuestsEnabled ? '' : ' disabled')
                    . '> Gäste</label>';
                echo '<label style="display:block;">'
                    . '<input type="checkbox" name="groups[' . esc_attr((string)$id) . '][]" value="member" '
                    . checked($pMembersEnabled, true, false)
                    . ($tMembersEnabled ? '' : ' disabled')
                    . '> Mitglieder</label>';
                echo '</td>';

                $active = (int) $r['is_active'] === 1;
                echo '<td><label><input type="checkbox" name="is_active[' . esc_attr((string)$id) . ']" value="1" ' . checked($active, true, false) . '> ja</label></td>';

                $sort = (int) $r['sort_order'];
                echo '<td><input type="number" name="sort_order[' . esc_attr((string)$id) . ']" value="' . esc_attr((string)$sort) . '" style="width:70px;"></td>';

                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<hr>';

        echo '<h2>Neuen Nebenpreis hinzufügen</h2>';
        echo '<table class="form-table"><tbody>';

        echo '<tr><th>Label</th><td><input type="text" class="regular-text" name="new_label" value=""></td></tr>';
        echo '<tr><th>Typ</th><td><input type="text" name="new_type" value="NTP" class="regular-text"> <span class="description">z.B. NTP, LD, LD_LADIES …</span></td></tr>';

        echo '<tr><th>Scope</th><td><select name="new_scope">';
        echo '<option value="hole">hole</option>';
        echo '<option value="tournament">tournament</option>';
        echo '</select></td></tr>';

        echo '<tr><th>Loch (optional)</th><td><input type="number" min="1" max="18" name="new_hole_no" value="" style="width:90px;"> <span class="description">nur bei Scope=hole</span></td></tr>';
        echo '<tr><th>Einheit</th><td><input type="text" name="new_unit" value="text" class="regular-text"> <span class="description">cm|m|count|text</span></td></tr>';

        echo '<tr><th>Berechtigt</th><td>';
        echo '<label style="display:block; margin-bottom:4px;">'
            . '<input type="checkbox" name="new_groups[]" value="guest" '
            . checked($tGuestsEnabled, true, false)
            . ($tGuestsEnabled ? '' : ' disabled')
            . '> Gäste</label>';
        echo '<label style="display:block;">'
            . '<input type="checkbox" name="new_groups[]" value="member" '
            . checked($tMembersEnabled, true, false)
            . ($tMembersEnabled ? '' : ' disabled')
            . '> Mitglieder</label>';
        echo '<p class="description">Default entspricht der Turnier-Berechtigung.</p>';
        echo '</td></tr>';

        echo '<tr><th>Aktiv</th><td><label><input type="checkbox" name="new_is_active" value="1" checked> ja</label></td></tr>';
        echo '<tr><th>Sortierung</th><td><input type="number" name="new_sort_order" value="10" style="width:90px;"></td></tr>';

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

        global $wpdb;
        $table = $wpdb->prefix . 'gc_prizes';

        $tournamentGroups = self::tournamentEligibleGroups($postId);

        // Update existing
        $ids = isset($_POST['prize_id']) && is_array($_POST['prize_id']) ? $_POST['prize_id'] : [];
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }

            $label = isset($_POST['label'][$id]) ? sanitize_text_field((string)$_POST['label'][$id]) : '';
            $type  = isset($_POST['type'][$id]) ? sanitize_text_field((string)$_POST['type'][$id]) : '';
            $scope = isset($_POST['scope'][$id]) ? sanitize_text_field((string)$_POST['scope'][$id]) : 'hole';
            $unit  = isset($_POST['unit'][$id]) ? sanitize_text_field((string)$_POST['unit'][$id]) : 'text';

            if ($scope !== 'hole' && $scope !== 'tournament') {
                $scope = 'hole';
            }

            $holeNo = isset($_POST['hole_no'][$id]) ? (int) $_POST['hole_no'][$id] : 0;
            if ($scope === 'tournament') {
                $holeNo = 0;
            }
            if ($holeNo < 1 || $holeNo > 18) {
                $holeNo = 0;
            }

            $isActive = !empty($_POST['is_active'][$id]) ? 1 : 0;
            $sortOrder = isset($_POST['sort_order'][$id]) ? (int) $_POST['sort_order'][$id] : 10;

            $groups = isset($_POST['groups'][$id]) && is_array($_POST['groups'][$id]) ? $_POST['groups'][$id] : $tournamentGroups;
            // clamp to tournament groups
            $groups = array_values(array_intersect(array_map('strtolower', $groups), $tournamentGroups));
            if ($groups === []) {
                $groups = $tournamentGroups;
            }

            $restrictionsJson = self::buildRestrictionsJson($groups);

            $wpdb->update(
                $table,
                [
                    'label' => $label,
                    'type'  => $type,
                    'scope' => $scope,
                    'hole_no' => ($holeNo > 0 ? $holeNo : null),
                    'unit'  => $unit,
                    'restrictions_json' => $restrictionsJson,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ],
                ['id' => $id, 'tournament_post_id' => $postId],
                ['%s','%s','%s','%d','%s','%s','%d','%d'],
                ['%d','%d']
            );
        }

        // Insert new (optional)
        $newLabel = isset($_POST['new_label']) ? sanitize_text_field((string)$_POST['new_label']) : '';
        if ($newLabel !== '') {
            $newType = isset($_POST['new_type']) ? sanitize_text_field((string)$_POST['new_type']) : 'NTP';
            $newScope = isset($_POST['new_scope']) ? sanitize_text_field((string)$_POST['new_scope']) : 'hole';
            if ($newScope !== 'hole' && $newScope !== 'tournament') {
                $newScope = 'hole';
            }

            $newHoleNo = isset($_POST['new_hole_no']) ? (int) $_POST['new_hole_no'] : 0;
            if ($newScope === 'tournament') {
                $newHoleNo = 0;
            }
            if ($newHoleNo < 1 || $newHoleNo > 18) {
                $newHoleNo = 0;
            }

            $newUnit = isset($_POST['new_unit']) ? sanitize_text_field((string)$_POST['new_unit']) : 'text';
            $newIsActive = !empty($_POST['new_is_active']) ? 1 : 0;
            $newSort = isset($_POST['new_sort_order']) ? (int) $_POST['new_sort_order'] : 10;

            $newGroups = isset($_POST['new_groups']) && is_array($_POST['new_groups']) ? $_POST['new_groups'] : $tournamentGroups;
            $newGroups = array_values(array_intersect(array_map('strtolower', $newGroups), $tournamentGroups));
            if ($newGroups === []) {
                $newGroups = $tournamentGroups;
            }

            $restrictionsJson = self::buildRestrictionsJson($newGroups);

            $wpdb->insert(
                $table,
                [
                    'tournament_post_id' => $postId,
                    'type' => $newType,
                    'scope' => $newScope,
                    'hole_no' => ($newHoleNo > 0 ? $newHoleNo : null),
                    'unit' => $newUnit,
                    'label' => $newLabel,
                    'restrictions_json' => $restrictionsJson,
                    'is_active' => $newIsActive,
                    'sort_order' => $newSort,
                ],
                ['%d','%s','%s','%d','%s','%s','%s','%d','%d']
            );
        }

        $url = add_query_arg(
            ['page' => self::PAGE_SLUG, 'post_id' => $postId, 'ps_saved' => '1'],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}

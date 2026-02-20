<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;
use PlatzStatus\Services\TournamentOptions;

final class TournamentMetaBoxes
{
    private const NONCE_ACTION = 'ps_save_tournament_options';
    private const NONCE_NAME   = 'ps_tournament_nonce';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensureCaps'], 1);
        add_action('add_meta_boxes', [self::class, 'addMetaBox']);
        add_action('save_post', [self::class, 'savePost'], 10, 2);
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
            [self::class, 'renderBox'],
            $pt,
            'side',
            'default'
        );
    }

    public static function renderBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            echo '<p>Keine Berechtigung.</p>';
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $postId = (int) $post->ID;

        $isTournament = TournamentOptions::isTournament($postId);

        $holes = TournamentOptions::holes($postId);
        if ($holes !== 9 && $holes !== 18) {
            $holes = 18;
        }

        $enableScorecards = (int) get_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, true) === 1;
        ?>
        <p style="margin:0 0 10px 0;">
            <label>
                <input type="checkbox" name="ps_is_tournament" value="1" <?php checked($isTournament, true); ?>>
                <strong>Dieses Ereignis ist ein Turnier</strong>
            </label>
        </p>

        <p style="margin:0 0 10px 0;">
            <label for="ps_holes"><strong>Löcher</strong></label><br>
            <select id="ps_holes" name="ps_holes" style="width:100%;">
                <option value="9"  <?php selected($holes, 9);  ?>>9</option>
                <option value="18" <?php selected($holes, 18); ?>>18</option>
            </select>
        </p>

        <p style="margin:0;">
            <label>
                <input
                    type="checkbox"
                    name="ps_enable_scorecards"
                    value="1"
                    <?php checked($enableScorecards, true); ?>
                >
                Loch-für-Loch Erfassung
            </label>
        </p>

        <?php if (!$isTournament): ?>
            <p class="description" style="margin-top:8px;">
                Hinweis: Wenn „Turnier“ nicht aktiv ist, werden Scorecards beim Speichern automatisch deaktiviert.
            </p>
        <?php endif; ?>
        <?php
    }

    public static function savePost(int $postId, \WP_Post $post): void
    {
        $pt = TournamentOptions::impactPostType();
        if (($post->post_type ?? '') !== $pt) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $ownNonceOk = !empty($_POST[self::NONCE_NAME])
            && wp_verify_nonce((string) $_POST[self::NONCE_NAME], self::NONCE_ACTION);

        $coreNonceOk = !empty($_POST['_wpnonce'])
            && wp_verify_nonce((string) $_POST['_wpnonce'], 'update-post_' . $postId);

        $restNonceOk = !empty($_SERVER['HTTP_X_WP_NONCE'])
            && wp_verify_nonce((string) $_SERVER['HTTP_X_WP_NONCE'], 'wp_rest');

        $isRest = defined('REST_REQUEST') && REST_REQUEST;

        if (($isRest && !$restNonceOk) || (!$isRest && !$ownNonceOk && !$coreNonceOk)) {
            return;
        }

        if (!current_user_can(Capabilities::CAP)) {
            return;
        }

        $isTournament = !empty($_POST['ps_is_tournament']) ? 1 : 0;
        update_post_meta($postId, TournamentOptions::META_IS_TOURNAMENT, $isTournament);

        $holes = isset($_POST['ps_holes']) ? (int) $_POST['ps_holes'] : 18;
        if ($holes !== 9 && $holes !== 18) {
            $holes = 18;
        }
        update_post_meta($postId, TournamentOptions::META_HOLES, $holes);

        $postedEnable = array_key_exists('ps_enable_scorecards', $_POST);

        if ($postedEnable) {
            $enableScorecards = (!empty($_POST['ps_enable_scorecards']) && $isTournament === 1) ? 1 : 0;
            update_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, $enableScorecards);
        } else {
            if ($isTournament !== 1) {
                update_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, 0);
            }
        }
    }
}

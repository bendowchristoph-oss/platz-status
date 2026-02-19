<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;
use PlatzStatus\Services\TournamentOptions;

final class TournamentMetaBoxes
{
    private const NONCE_ACTION = 'ps_save_tournament_options';
    private const NONCE_NAME   = 'ps_tournament_nonce';

    public static function debugNotice(): void
    {
        global $pagenow;

        if ($pagenow !== 'post.php' || empty($_GET['post'])) {
            return;
        }

        $postId = (int) $_GET['post'];
        if ($postId <= 0) {
            return;
        }

        $ping  = (int) get_post_meta($postId, '_ps_debug_savepost_ping', true);
        $abort = (string) get_post_meta($postId, '_ps_debug_savepost_abort', true);

        if (!$ping && !$abort) {
            return;
        }

        echo '<div class="notice notice-info"><p><strong>PlatzStatus Debug:</strong> savePost ping='
            . esc_html($ping ? (string)$ping : '—')
            . ', status=' . esc_html($abort ?: '—')
            . '</p></div>';
    }

    public static function register(): void
    {
        // Caps self-heal (falls activation hook nicht sauber lief)
        add_action('init', [self::class, 'ensureCaps'], 1);

        add_action('add_meta_boxes', [self::class, 'addMetaBox']);
        add_action('save_post', [self::class, 'savePost'], 10, 2);
        add_action('admin_notices', [self::class, 'debugNotice']);

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
      update_post_meta($postId, '_ps_debug_savepost_ping', time());

      if (defined('WP_DEBUG') && WP_DEBUG) {
          error_log('[PlatzStatus] savePost() postId=' . $postId
              . ' post_type=' . (get_post_type($postId) ?: 'null')
              . ' DOING_AUTOSAVE=' . (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ? '1' : '0')
              . ' nonce_present=' . (!empty($_POST[self::NONCE_NAME]) ? '1' : '0')
              . ' core_nonce_present=' . (!empty($_POST['_wpnonce']) ? '1' : '0')
              . ' user=' . (get_current_user_id() ?: 0)
          );
      }


        $pt = TournamentOptions::impactPostType();
        if (($post->post_type ?? '') !== $pt) {
          update_post_meta($postId, '_ps_debug_savepost_abort', 'grund');


            return;
        }

        // Autosave / Revisionen ignorieren
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
          update_post_meta($postId, '_ps_debug_savepost_abort', 'grund');


            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
          update_post_meta($postId, '_ps_debug_savepost_abort', 'grund');


            return;
        }

        // Nonce prüfen (eigener Nonce + Fallback auf Core "update-post" Nonce)
        $ownNonceOk = !empty($_POST[self::NONCE_NAME])
            && wp_verify_nonce((string) $_POST[self::NONCE_NAME], self::NONCE_ACTION);

        $coreNonceOk = !empty($_POST['_wpnonce'])
            && wp_verify_nonce((string) $_POST['_wpnonce'], 'update-post_' . $postId);

        if (!$ownNonceOk && !$coreNonceOk) {
          update_post_meta($postId, '_ps_debug_savepost_abort', 'nonce_failed');

            return;
        }


        // Capability prüfen
        if (!current_user_can(Capabilities::CAP)) {
          update_post_meta($postId, '_ps_debug_savepost_abort', 'grund');


            return;
        }

        // 1) Turnier-Flag (Checkbox -> wenn nicht gesetzt, ist es 0)
        $isTournament = !empty($_POST['ps_is_tournament']) ? 1 : 0;
        update_post_meta($postId, TournamentOptions::META_IS_TOURNAMENT, $isTournament);

        // 2) Löcher nur 9/18
        $holes = isset($_POST['ps_holes']) ? (int) $_POST['ps_holes'] : 18;
        if ($holes !== 9 && $holes !== 18) {
            $holes = 18;
        }
        update_post_meta($postId, TournamentOptions::META_HOLES, $holes);

        // 3) Scorecards: Checkbox aus POST lesen, aber erzwinge 0 wenn kein Turnier
        $enableScorecards = (!empty($_POST['ps_enable_scorecards']) && $isTournament === 1) ? 1 : 0;
        update_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, $enableScorecards);

        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('[PlatzStatus] savePost() ABORT: <GRUND>'); }
        update_post_meta($postId, '_ps_debug_savepost_abort', 'ok_saved');

    }
}

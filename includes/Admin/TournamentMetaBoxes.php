<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;
use PlatzStatus\Services\TournamentOptions;

final class TournamentMetaBoxes
{
    private const NONCE_ACTION = 'ps_save_tournament_options';
    private const NONCE_NAME   = 'ps_tournament_nonce';
    private const ACTION       = 'ps_save_tournament_options';

    public static function register(): void
    {
        add_action('init', [self::class, 'ensureCaps'], 1);
        add_action('add_meta_boxes', [self::class, 'addMetaBox']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
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

        $postId = (int) $post->ID;

        $isTournament = TournamentOptions::isTournament($postId);

        $holes = TournamentOptions::holes($postId);
        if ($holes !== 9 && $holes !== 18) {
            $holes = 18;
        }

        $enableScorecards = (int) get_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, true) === 1;

        if (!empty($_GET['ps_saved'])) {
            echo '<p style="margin:0 0 10px 0; color:#1d7f2a;"><strong>Gespeichert.</strong></p>';
        }

        // Nonce + post_id im bestehenden WP-Hauptformular (kein nested form!)
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $postId); ?>">

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

        <p style="margin:0 0 10px 0;">
            <label>
                <input type="checkbox" name="ps_enable_scorecards" value="1" <?php checked($enableScorecards, true); ?>>
                Loch-für-Loch Erfassung
            </label>
        </p>

        <?php if (!$isTournament): ?>
            <p class="description" style="margin:0 0 10px 0;">
                Hinweis: Wenn „Turnier“ nicht aktiv ist, werden Scorecards beim Speichern automatisch deaktiviert.
            </p>
        <?php endif; ?>

        <p style="margin:0;">
            <button
                type="submit"
                class="button button-primary"
                style="width:100%;"
                formaction="<?php echo esc_url(admin_url('admin-post.php?action=' . self::ACTION)); ?>"
                formmethod="post"
            >
                Turnier-Optionen speichern
            </button>
        </p>
        <?php
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

        $url = add_query_arg(
            ['post' => $postId, 'action' => 'edit', 'ps_saved' => '1'],
            admin_url('post.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}

<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;
use PlatzStatus\Services\TournamentOptions;
use PlatzStatus\Services\ResultsRepository;
use PlatzStatus\Services\Engine;

final class TournamentScorecardController
{
    private const PAGE_SLUG = 'platzstatus-scorecards';
    private const NONCE_ACTION = 'ps_save_scorecard';
    private const NONCE_NAME = 'ps_scorecard_nonce';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_ps_save_scorecard', [self::class, 'handleSave']);
        add_action('add_meta_boxes', [self::class, 'addMetaBox']);
    }

    /**
     * URL helper, wird von anderen Controllern (z.B. ParticipantsController) genutzt.
     */
    public static function scorecardUrl(int $tournamentPostId, int $roundId = 0): string
    {
        $args = [
            'page' => self::PAGE_SLUG,
            'tournament_post_id' => $tournamentPostId,
        ];
        if ($roundId > 0) {
            $args['round_id'] = $roundId;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    public static function addMenu(): void
    {
        add_submenu_page(
            null,
            'Scorecards',
            'Scorecards',
            Capabilities::CAP,
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function addMetaBox(): void
    {
        // MetaBox hängt am Post-Type des "Ereignis"
        $pt = Engine::impactPostType();

        add_meta_box(
            'ps_scorecards_link',
            'Scorecards (Loch-für-Loch)',
            [self::class, 'renderMetaBox'],
            $pt,
            'side',
            'default'
        );
    }

    public static function renderMetaBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            echo '<p>Keine Berechtigung.</p>';
            return;
        }

        $postId = (int)$post->ID;

        if (!TournamentOptions::isTournament($postId)) {
            echo '<p class="description">Aktiviere zuerst „Dieses Ereignis ist ein Turnier“.</p>';
            return;
        }

        $enabled = (int) get_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, true) === 1;
        if (!$enabled) {
            echo '<p class="description">Aktiviere „Loch-für-Loch Erfassung“, um Scorecards zu nutzen.</p>';
            return;
        }

        $url = self::scorecardUrl($postId);

        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Scorecards öffnen</a></p>';
        echo '<p class="description">Hier trägst du pro Teilnehmer die Lochdaten (Strokes etc.) ein.</p>';
    }

    public static function renderPage(): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            wp_die('Keine Berechtigung.');
        }

        $postId = isset($_GET['tournament_post_id']) ? (int) $_GET['tournament_post_id'] : 0;
        if ($postId <= 0) {
            wp_die('Fehlende Turnier-ID.');
        }

        if (!TournamentOptions::isTournament($postId)) {
            wp_die('Dieses Ereignis ist kein Turnier.');
        }

        $enabled = (int) get_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, true) === 1;
        if (!$enabled) {
            wp_die('Scorecards sind für dieses Turnier nicht aktiviert.');
        }

        $holes = TournamentOptions::holes($postId); // 9 oder 18
        $rounds = ResultsRepository::getRounds($postId);

        $selectedRoundId = isset($_GET['round_id']) ? (int) $_GET['round_id'] : 0;
        if ($selectedRoundId <= 0 && !empty($rounds)) {
            $selectedRoundId = (int)($rounds[0]['id'] ?? 0);
        }

        $selectedRound = null;
        foreach ($rounds as $r) {
            if ((int)($r['id'] ?? 0) === $selectedRoundId) {
                $selectedRound = $r;
                break;
            }
        }

        $scores = ($selectedRoundId > 0) ? ResultsRepository::getHoleScores($selectedRoundId) : [];

        $rows = [];
        for ($h = 1; $h <= $holes; $h++) {
            $rows[$h] = [
                'strokes' => '',
                'putts' => '',
                'penalties' => '',
                'stableford_points' => '',
            ];
        }

        foreach ($scores as $s) {
            $hn = (int)($s['hole_no'] ?? 0);
            if ($hn < 1 || $hn > $holes) continue;

            $rows[$hn] = [
                'strokes' => isset($s['strokes']) ? (string)$s['strokes'] : '',
                'putts' => isset($s['putts']) ? (string)$s['putts'] : '',
                'penalties' => isset($s['penalties']) ? (string)$s['penalties'] : '',
                'stableford_points' => isset($s['stableford_points']) ? (string)$s['stableford_points'] : '',
            ];
        }

        $backUrl = get_edit_post_link($postId, '');
        $actionUrl = admin_url('admin-post.php');

        ?>
        <div class="wrap">
            <h1>Scorecards</h1>

            <p>
                <a href="<?php echo esc_url($backUrl); ?>">&larr; zurück zum Ereignis</a>
            </p>

            <div style="background:#fff;border:1px solid #ccd0d4;padding:14px;border-radius:8px;max-width:1200px;">
                <p class="description" style="margin-top:0;">
                    Turnier: <strong><?php echo esc_html(get_the_title($postId)); ?></strong> ·
                    Löcher: <strong><?php echo esc_html((string)$holes); ?></strong>
                </p>

                <?php if (empty($rounds)): ?>
                    <p class="description">Keine Teilnehmer vorhanden. Füge zuerst Teilnehmer in der Quick-Erfassung hinzu.</p>
                <?php else: ?>
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:14px;">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                        <input type="hidden" name="tournament_post_id" value="<?php echo esc_attr((string)$postId); ?>">
                        <label>
                            <strong>Teilnehmer</strong><br>
                            <select name="round_id" onchange="this.form.submit();" style="min-width:320px;">
                                <?php foreach ($rounds as $r): ?>
                                    <?php
                                        $rid = (int)($r['id'] ?? 0);
                                        $label = (($r['player_type'] ?? '') === 'member')
                                            ? ('Mitglied #' . (string)($r['member_user_id'] ?? ''))
                                            : (string)($r['guest_name'] ?? 'Gast');
                                        if (!empty($r['guest_club'])) $label .= ' (' . $r['guest_club'] . ')';
                                    ?>
                                    <option value="<?php echo esc_attr((string)$rid); ?>" <?php selected($rid, $selectedRoundId); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <noscript><button class="button">Wechseln</button></noscript>
                    </form>

                    <?php if (!$selectedRound): ?>
                        <p class="description">Ungültiger Teilnehmer (round_id).</p>
                    <?php else: ?>
                        <form method="post" action="<?php echo esc_url($actionUrl); ?>">
                            <input type="hidden" name="action" value="ps_save_scorecard">
                            <input type="hidden" name="tournament_post_id" value="<?php echo esc_attr((string)$postId); ?>">
                            <input type="hidden" name="round_id" value="<?php echo esc_attr((string)$selectedRoundId); ?>">

                            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                            <table class="widefat striped" style="max-width:900px;">
                                <thead>
                                    <tr>
                                        <th style="width:70px;">Loch</th>
                                        <th style="width:140px;">Strokes</th>
                                        <th style="width:140px;">Putts</th>
                                        <th style="width:140px;">Penalties</th>
                                        <th style="width:160px;">Stableford</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($h = 1; $h <= $holes; $h++): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html((string)$h); ?></strong></td>
                                            <td><input type="number" name="holes[<?php echo esc_attr((string)$h); ?>][strokes]" value="<?php echo esc_attr($rows[$h]['strokes']); ?>" min="0" step="1" class="small-text"></td>
                                            <td><input type="number" name="holes[<?php echo esc_attr((string)$h); ?>][putts]" value="<?php echo esc_attr($rows[$h]['putts']); ?>" min="0" step="1" class="small-text"></td>
                                            <td><input type="number" name="holes[<?php echo esc_attr((string)$h); ?>][penalties]" value="<?php echo esc_attr($rows[$h]['penalties']); ?>" min="0" step="1" class="small-text"></td>
                                            <td><input type="number" name="holes[<?php echo esc_attr((string)$h); ?>][stableford_points]" value="<?php echo esc_attr($rows[$h]['stableford_points']); ?>" min="0" step="1" class="small-text"></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>

                            <p style="margin-top:12px;">
                                <button type="submit" class="button button-primary">Scorecard speichern</button>
                            </p>

                            <p class="description">Leere Felder werden als NULL gespeichert (nicht 0).</p>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function handleSave(): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            wp_die('Keine Berechtigung.');
        }

        if (empty($_POST[self::NONCE_NAME]) || !wp_verify_nonce((string)$_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
            wp_die('Ungültiger Nonce.');
        }

        $postId = isset($_POST['tournament_post_id']) ? (int) $_POST['tournament_post_id'] : 0;
        $roundId = isset($_POST['round_id']) ? (int) $_POST['round_id'] : 0;

        if ($postId <= 0 || $roundId <= 0) {
            wp_die('Fehlende Parameter (Turnier/Teilnehmer).');
        }

        if (!TournamentOptions::isTournament($postId)) {
            wp_die('Dieses Ereignis ist kein Turnier.');
        }

        $enabled = (int) get_post_meta($postId, TournamentOptions::META_ENABLE_SCORECARDS, true) === 1;
        if (!$enabled) {
            wp_die('Scorecards sind nicht aktiviert.');
        }

        $holesCount = TournamentOptions::holes($postId);

        $holes = $_POST['holes'] ?? [];
        if (!is_array($holes)) $holes = [];

        $toNullableInt = static function($v): ?int {
            $s = trim((string)$v);
            if ($s === '') return null;
            if (!preg_match('/^\d+$/', $s)) return null;
            return (int)$s;
        };

        $anyData = false;

        for ($h = 1; $h <= $holesCount; $h++) {
            $row = $holes[$h] ?? null;
            if (!is_array($row)) $row = [];

            $strokes = $toNullableInt($row['strokes'] ?? '');
            $putts   = $toNullableInt($row['putts'] ?? '');
            $pens    = $toNullableInt($row['penalties'] ?? '');
            $stbf    = $toNullableInt($row['stableford_points'] ?? '');

            if ($strokes === null && $putts === null && $pens === null && $stbf === null) {
                ResultsRepository::deleteHoleScore($roundId, $h);
                continue;
            }

            $anyData = true;

            ResultsRepository::upsertHoleScore($roundId, $h, [
                'strokes' => $strokes,
                'putts' => $putts,
                'penalties' => $pens,
                'stableford_points' => $stbf,
            ]);
        }

        ResultsRepository::setRoundHasHoleData($roundId, $anyData);

        wp_safe_redirect(self::scorecardUrl($postId, $roundId) . '&saved=1');
        exit;
    }
}

<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;
use PlatzStatus\Services\TournamentOptions;
use PlatzStatus\Services\ResultsRepository;

final class TournamentParticipantsController
{
    private const NONCE_ACTION = 'ps_save_rounds';
    private const NONCE_NAME   = 'ps_rounds_nonce';
    private const ACTION       = 'ps_save_rounds';

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'addMetaBoxes']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
    }

    public static function addMetaBoxes(): void
    {
        // MetaBox hängt am Post-Type des "Ereignis"
        $pt = TournamentOptions::impactPostType();

        add_meta_box(
            'ps_tournament_rounds',
            'Teilnehmer & Ergebnisse (Quick)',
            [self::class, 'renderBox'],
            $pt,
            'normal',
            'high'
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

        $rounds = ResultsRepository::getRounds($postId);
        $actionUrl = admin_url('admin-post.php');

        // Scorecard-Link ist optional – aber niemals fatal.
        $scorecardControllerOk =
            class_exists(\PlatzStatus\Admin\TournamentScorecardController::class)
            && method_exists(\PlatzStatus\Admin\TournamentScorecardController::class, 'scorecardUrl');

        ?>
        <p class="description">
            Quick-Erfassung: Totals (Stableford / Brutto / Netto) und Status.
            Scorecards sind pro Teilnehmer über „Scorecard“ verfügbar.
        </p>

        <form method="post" action="<?php echo esc_url($actionUrl); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="tournament_post_id" value="<?php echo esc_attr((string)$postId); ?>">

            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

            <h4>Gast hinzufügen</h4>
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px;">
                <label>
                    <div>Name</div>
                    <input type="text" name="add_guest_name" value="" class="regular-text" placeholder="Max Mustermann">
                </label>
                <label>
                    <div>Club (optional)</div>
                    <input type="text" name="add_guest_club" value="" class="regular-text" placeholder="GC Beispiel e.V.">
                </label>
                <button type="submit" class="button">Gast hinzufügen</button>
            </div>

            <h4>Teilnehmerliste</h4>

            <?php if (empty($rounds)): ?>
                <p class="description">Noch keine Teilnehmer.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Spieler</th>
                            <th style="width:120px;">Stableford</th>
                            <th style="width:120px;">Brutto</th>
                            <th style="width:120px;">Netto</th>
                            <th style="width:90px;">Status</th>
                            <th style="width:90px;">Final</th>
                            <th style="width:140px;">Scorecard</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rounds as $r): ?>
                            <?php
                                $rid = (int) ($r['id'] ?? 0);

                                $label = (($r['player_type'] ?? '') === 'member')
                                    ? ('Mitglied #' . (string)($r['member_user_id'] ?? ''))
                                    : (string)($r['guest_name'] ?? 'Gast');

                                if (!empty($r['guest_club'])) {
                                    $label .= ' (' . (string)$r['guest_club'] . ')';
                                }

                                $scoreUrl = '';
                                if ($scorecardControllerOk && $rid > 0) {
                                    $scoreUrl = \PlatzStatus\Admin\TournamentScorecardController::scorecardUrl($postId, $rid);
                                }
                            ?>
                            <tr>
                                <td><?php echo esc_html($label); ?></td>
                                <td>
                                    <input
                                        type="number"
                                        name="rounds[<?php echo esc_attr((string)$rid); ?>][stableford_total]"
                                        value="<?php echo esc_attr((string)($r['stableford_total'] ?? '')); ?>"
                                        class="small-text"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        name="rounds[<?php echo esc_attr((string)$rid); ?>][strokes_gross_total]"
                                        value="<?php echo esc_attr((string)($r['strokes_gross_total'] ?? '')); ?>"
                                        class="small-text"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        name="rounds[<?php echo esc_attr((string)$rid); ?>][strokes_net_total]"
                                        value="<?php echo esc_attr((string)($r['strokes_net_total'] ?? '')); ?>"
                                        class="small-text"
                                    >
                                </td>
                                <td>
                                    <select name="rounds[<?php echo esc_attr((string)$rid); ?>][status]">
                                        <?php foreach (['OK','DNS','DQ','NR'] as $st): ?>
                                            <option value="<?php echo esc_attr($st); ?>" <?php selected((string)($r['status'] ?? 'OK'), $st); ?>>
                                                <?php echo esc_html($st); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="text-align:center;">
                                    <input
                                        type="checkbox"
                                        name="rounds[<?php echo esc_attr((string)$rid); ?>][is_final]"
                                        value="1"
                                        <?php checked((int)($r['is_final'] ?? 0), 1); ?>
                                    >
                                </td>
                                <td>
                                    <?php if ($scoreUrl !== ''): ?>
                                        <a class="button" href="<?php echo esc_url($scoreUrl); ?>">Scorecard</a>
                                    <?php else: ?>
                                        <span class="description">Controller fehlt</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:10px;">
                    <button type="submit" class="button button-primary">Ergebnisse speichern</button>
                </p>
            <?php endif; ?>
        </form>
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
        if ($postId <= 0) {
            wp_die('Fehlende Turnier-ID.');
        }

        if (!TournamentOptions::isTournament($postId)) {
            wp_die('Dieses Ereignis ist kein Turnier.');
        }

        $toNullableInt = static function ($v): ?int {
            $s = trim((string)$v);
            if ($s === '') return null;
            if (!preg_match('/^-?\d+$/', $s)) return null;
            return (int)$s;
        };

        // Add guest if provided
        $guestName = isset($_POST['add_guest_name']) ? sanitize_text_field((string)$_POST['add_guest_name']) : '';
        $guestClub = isset($_POST['add_guest_club']) ? sanitize_text_field((string)$_POST['add_guest_club']) : '';
        if ($guestName !== '') {
            ResultsRepository::insertGuestRound($postId, $guestName, $guestClub);
        }

        // Update totals
        $rounds = $_POST['rounds'] ?? [];
        if (is_array($rounds)) {
            foreach ($rounds as $roundId => $fields) {
                $roundId = (int) $roundId;
                if ($roundId <= 0 || !is_array($fields)) {
                    continue;
                }

                $status = isset($fields['status']) ? strtoupper(trim((string)$fields['status'])) : 'OK';
                if (!in_array($status, ['OK','DNS','DQ','NR'], true)) {
                    $status = 'OK';
                }

                $payload = [
                    'stableford_total'      => $toNullableInt($fields['stableford_total'] ?? null),
                    'strokes_gross_total'   => $toNullableInt($fields['strokes_gross_total'] ?? null),
                    'strokes_net_total'     => $toNullableInt($fields['strokes_net_total'] ?? null),
                    'status'                => $status,
                    'is_final'              => !empty($fields['is_final']) ? 1 : 0,
                ];

                ResultsRepository::updateRoundTotals($roundId, $payload);
            }
        }

        // Back to edit screen
        wp_safe_redirect(add_query_arg(
            ['post' => $postId, 'action' => 'edit', 'ps_saved' => 1],
            admin_url('post.php')
        ));
        exit;
    }
}

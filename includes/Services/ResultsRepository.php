<?php
declare(strict_types=1);

namespace PlatzStatus\Services;

final class ResultsRepository
{
    public static function getRounds(int $tournamentPostId): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'gc_rounds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$t} WHERE tournament_post_id=%d ORDER BY id ASC", $tournamentPostId),
            ARRAY_A
        );
    }

    public static function insertGuestRound(int $tournamentPostId, string $name, string $club = ''): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'gc_rounds';

        $name = trim($name);
        if ($name === '') return;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($t, [
            'tournament_post_id' => $tournamentPostId,
            'player_type' => 'guest',
            'guest_name' => $name,
            'guest_club' => trim($club),
            'status' => 'OK',
            'data_source' => 'ui',
        ], ['%d','%s','%s','%s','%s','%s']);
    }

    public static function updateRoundTotals(int $roundId, array $fields): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'gc_rounds';

        $allowed = [
            'stableford_total','strokes_gross_total','strokes_net_total',
            'status','is_final'
        ];

        $data = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) continue;

            $val = $fields[$key];

            if (in_array($key, ['stableford_total','strokes_gross_total','strokes_net_total'], true)) {
                $data[$key] = ($val === '' || $val === null) ? null : (int) $val;
            } elseif ($key === 'is_final') {
                $data[$key] = ((int)$val === 1) ? 1 : 0;
            } elseif ($key === 'status') {
                $s = strtoupper(sanitize_key((string)$val));
                if (!in_array($s, ['OK','DNS','DQ','NR'], true)) $s = 'OK';
                $data[$key] = $s;
            }
        }

        if (empty($data)) return;

        // Proper NULLs: build query manually
        $set = [];
        $args = [];
        foreach ($data as $k => $v) {
            if ($v === null) {
                $set[] = "{$k}=NULL";
            } else {
                $set[] = "{$k}=%s";
                $args[] = (string)$v;
            }
        }
        $args[] = $roundId;

        $sql = "UPDATE {$t} SET " . implode(',', $set) . " WHERE id=%d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query($wpdb->prepare($sql, ...$args));
    }

    // -------------------------
    // Hole scores (gc_hole_scores)
    // -------------------------

    /**
     * Returns hole rows for a round: each row contains
     * round_id, hole_no, strokes, putts, penalties, stableford_points
     */
    public static function getHoleScores(int $roundId): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'gc_hole_scores';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$t} WHERE round_id=%d ORDER BY hole_no ASC", $roundId),
            ARRAY_A
        );
    }

    /**
     * Upsert a single hole row (NULL-safe).
     * $data keys: strokes, putts, penalties, stableford_points => ?int
     */
    public static function upsertHoleScore(int $roundId, int $holeNo, array $data): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'gc_hole_scores';

        $holeNo = max(1, min(18, $holeNo));

        $strokes = array_key_exists('strokes', $data) ? $data['strokes'] : null;
        $putts   = array_key_exists('putts', $data) ? $data['putts'] : null;
        $pens    = array_key_exists('penalties', $data) ? $data['penalties'] : null;
        $stbf    = array_key_exists('stableford_points', $data) ? $data['stableford_points'] : null;

        $toNullableInt = static function($v): ?int {
            if ($v === null) return null;
            if ($v === '') return null;
            return (int)$v;
        };

        $strokes = $toNullableInt($strokes);
        $putts   = $toNullableInt($putts);
        $pens    = $toNullableInt($pens);
        $stbf    = $toNullableInt($stbf);

        // Use INSERT ... ON DUPLICATE KEY UPDATE with explicit NULL support
        $sql = "
            INSERT INTO {$t}
                (round_id, hole_no, strokes, putts, penalties, stableford_points)
            VALUES
                (%d, %d, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                strokes = VALUES(strokes),
                putts = VALUES(putts),
                penalties = VALUES(penalties),
                stableford_points = VALUES(stableford_points)
        ";

        $valStrokes = ($strokes === null) ? null : (string)$strokes;
        $valPutts   = ($putts === null) ? null : (string)$putts;
        $valPens    = ($pens === null) ? null : (string)$pens;
        $valStbf    = ($stbf === null) ? null : (string)$stbf;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query($wpdb->prepare(
            $sql,
            $roundId,
            $holeNo,
            $valStrokes,
            $valPutts,
            $valPens,
            $valStbf
        ));
    }

    public static function deleteHoleScore(int $roundId, int $holeNo): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'gc_hole_scores';
        $holeNo = max(1, min(18, $holeNo));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete($t, [
            'round_id' => $roundId,
            'hole_no' => $holeNo,
        ], ['%d','%d']);
    }

    public static function setRoundHasHoleData(int $roundId, bool $hasData): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'gc_rounds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update($t, [
            'has_hole_data' => $hasData ? 1 : 0,
            'data_source' => 'ui',
        ], [
            'id' => $roundId,
        ], ['%d','%s'], ['%d']);
    }
}

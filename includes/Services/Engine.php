<?php
declare(strict_types=1);

namespace PlatzStatus\Services;

final class Engine
{
    // Options
    private const OPTION_STATUS    = 'platzstatus_status_catalog';
    private const OPTION_DEFAULTS  = 'platzstatus_defaults';
    private const OPTION_IMPACT_PT = 'platzstatus_impact_post_type';

    // Meta keys (must match ImpactMetaBoxes.php)
    private const META_ALL_DAY      = 'ps_all_day';
    private const META_START_UTC    = 'ps_start_utc';
    private const META_END_UTC      = 'ps_end_utc';
    private const META_PRIORITY     = 'ps_priority';
    private const META_EFFECTS      = 'ps_effects';
    private const META_REC_ENABLED  = 'ps_recurrence_enabled';
    private const META_REC_RULE     = 'ps_recurrence_rule';
    private const META_ADVISORY     = 'ps_advisory_only';

    public static function targets(): array
    {
        return [
            'holes_1_9' => 'Bahnen 1–9',
            'holes_10_18' => 'Bahnen 10–18',
            'drivingrange' => 'Drivingrange',
            'ecarts' => 'E-Carts',
        ];
    }

    public static function statusCatalogActive(): array
    {
        $raw = (array) get_option(self::OPTION_STATUS, []);
        $out = [];
        foreach ($raw as $slug => $data) {
            if (!is_string($slug) || $slug === '') continue;
            $active = !empty($data['active']);
            if (!$active) continue;
            $out[$slug] = [
                'label' => isset($data['label']) ? (string)$data['label'] : $slug,
                'severity' => isset($data['severity']) ? (int)$data['severity'] : 0,
            ];
        }
        return $out;
    }

    public static function defaults(): array
    {
        $d = (array) get_option(self::OPTION_DEFAULTS, []);
        $fallback = [
            'holes_1_9'    => 'open',
            'holes_10_18'  => 'open',
            'drivingrange' => 'open',
            'ecarts'       => 'ecarts_allowed',
        ];
        return array_merge($fallback, $d);
    }

    public static function impactPostType(): string
    {
        return (string) get_option(self::OPTION_IMPACT_PT, 'ereignis');
    }

    public static function resolveNow(): array
    {
        $ver = (int) get_option('platzstatus_cache_version', 1);
        $cacheKey = 'platzstatus_now_v' . $ver;
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return $cached;

        $tz = wp_timezone();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rangeStartUtc = $nowUtc->modify('-1 day');
        $rangeEndUtc   = $nowUtc->modify('+8 days');

        $impacts = self::queryImpacts($rangeStartUtc, $rangeEndUtc);

        $catalog  = self::statusCatalogActive();
        $defaults = self::defaults();
        $targets  = array_keys(self::targets());

        $result = [];
        foreach ($targets as $t) {
            $result[$t] = self::defaultStatusPayload($t, $defaults, $catalog);
        }

        foreach ($impacts as $impact) {
            if (!empty($impact['advisory_only'])) continue;

            foreach (self::expandOccurrences($impact, $rangeStartUtc, $rangeEndUtc, $tz) as $occ) {
                foreach ($impact['effects'] as $eff) {
                    $t = $eff['target'];
                    if (!isset($result[$t])) continue;

                    [$effStartUtc, $effEndUtc] = self::effectWindowForOccurrence($occ['start_utc'], $occ['end_utc'], $eff, $tz);
                    if (!self::overlapsInstant($effStartUtc, $effEndUtc, $nowUtc)) continue;

                    $candidate = self::candidateFromEffect($impact, $eff, $catalog);
                    if (!$candidate) continue;

                    $result[$t] = self::pickBetter($result[$t], $candidate);
                }
            }
        }

        set_transient($cacheKey, $result, 60);
        return $result;
    }

    public static function resolvePreviewDays(int $days = 7): array
    {
        $ver = (int) get_option('platzstatus_cache_version', 1);
        $cacheKey = 'platzstatus_preview_' . $days . '_v' . $ver;
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return $cached;

        $days = max(1, min(14, $days));
        $tz = wp_timezone();

        $todayLocal = new \DateTimeImmutable('today', $tz);
        $startLocal = $todayLocal;
        $endLocal   = $todayLocal->modify('+' . ($days - 1) . ' days');

        $rangeStartUtc = $startLocal->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $rangeEndUtc   = $endLocal->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'));

        $impacts  = self::queryImpacts($rangeStartUtc, $rangeEndUtc);
        $catalog  = self::statusCatalogActive();
        $defaults = self::defaults();
        $targets  = array_keys(self::targets());

        $matrix = [];
        for ($i = 0; $i < $days; $i++) {
            $dLocal = $todayLocal->modify("+{$i} days");
            $dayKey = $dLocal->format('Y-m-d');
            $matrix[$dayKey] = ['date_local' => $dLocal, 'targets' => []];
            foreach ($targets as $t) {
                $matrix[$dayKey]['targets'][$t] = self::defaultStatusPayload($t, $defaults, $catalog);
            }
        }

        foreach ($impacts as $impact) {
            if (!empty($impact['advisory_only'])) continue;

            foreach (self::expandOccurrences($impact, $rangeStartUtc, $rangeEndUtc, $tz) as $occ) {
                for ($i = 0; $i < $days; $i++) {
                    $dLocal = $todayLocal->modify("+{$i} days");
                    $dayStartUtc = $dLocal->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'));
                    $dayEndUtc   = $dLocal->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'));
                    $dayKey = $dLocal->format('Y-m-d');

                    foreach ($impact['effects'] as $eff) {
                        $t = $eff['target'];
                        if (!isset($matrix[$dayKey]['targets'][$t])) continue;

                        [$effStartUtc, $effEndUtc] = self::effectWindowForOccurrence($occ['start_utc'], $occ['end_utc'], $eff, $tz);
                        if (!self::overlapsRange($effStartUtc, $effEndUtc, $dayStartUtc, $dayEndUtc)) continue;

                        $candidate = self::candidateFromEffect($impact, $eff, $catalog);
                        if (!$candidate) continue;

                        $matrix[$dayKey]['targets'][$t] = self::pickBetter($matrix[$dayKey]['targets'][$t], $candidate);
                    }
                }
            }
        }

        set_transient($cacheKey, $matrix, 60);
        return $matrix;
    }

    private static function queryImpacts(\DateTimeImmutable $rangeStartUtc, \DateTimeImmutable $rangeEndUtc): array
    {
        $pt = self::impactPostType();

        $q = new \WP_Query([
            'post_type' => $pt,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'relation' => 'AND',
                    [
                        'key' => self::META_START_UTC,
                        'value' => $rangeEndUtc->format(\DateTimeInterface::ATOM),
                        'compare' => '<=',
                        'type' => 'CHAR',
                    ],
                    [
                        'key' => self::META_END_UTC,
                        'value' => $rangeStartUtc->format(\DateTimeInterface::ATOM),
                        'compare' => '>=',
                        'type' => 'CHAR',
                    ],
                ],
                [
                    'key' => self::META_REC_ENABLED,
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        $out = [];

        foreach ((array)$q->posts as $p) {
            if (!$p instanceof \WP_Post) continue;

            $start = (string) get_post_meta($p->ID, self::META_START_UTC, true);
            $end   = (string) get_post_meta($p->ID, self::META_END_UTC, true);
            if ($start === '' || $end === '') continue;

            $effects = get_post_meta($p->ID, self::META_EFFECTS, true);
            if (!is_array($effects)) $effects = [];

            $normEffects = [];
            foreach ($effects as $row) {
                if (!is_array($row)) continue;

                $t = isset($row['target']) ? (string)$row['target'] : '';
                $st = isset($row['status']) ? (string)$row['status'] : '';
                $reason = isset($row['reason']) ? (string)$row['reason'] : '';

                $timeMode = isset($row['time_mode']) ? (string)$row['time_mode'] : 'inherit';
                if (!in_array($timeMode, ['inherit', 'custom'], true)) $timeMode = 'inherit';

                $startTime = isset($row['start_time']) ? (string)$row['start_time'] : '';
                $endTime   = isset($row['end_time']) ? (string)$row['end_time'] : '';

                if ($t === '' || $st === '') continue;

                if ($timeMode === 'custom') {
                    if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                        $timeMode = 'inherit';
                        $startTime = '';
                        $endTime = '';
                    }
                } else {
                    $startTime = '';
                    $endTime = '';
                }

                $normEffects[] = [
                    'target' => $t,
                    'status' => $st,
                    'reason' => $reason,
                    'time_mode' => $timeMode,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ];
            }
            if (empty($normEffects)) continue;

            $priority = (int) get_post_meta($p->ID, self::META_PRIORITY, true);
            if ($priority === 0) $priority = 10;

            $recEnabled = (int) get_post_meta($p->ID, self::META_REC_ENABLED, true) === 1;
            $rule = get_post_meta($p->ID, self::META_REC_RULE, true);
            if (!is_array($rule)) $rule = [];

            $out[] = [
                'id' => (int) $p->ID,
                'title' => get_the_title($p->ID),
                'start_utc' => $start,
                'end_utc' => $end,
                'priority' => $priority,
                'effects' => $normEffects,
                'rec_enabled' => $recEnabled,
                'rec_rule' => $rule,
                'advisory_only' => (int) get_post_meta($p->ID, self::META_ADVISORY, true) === 1,
            ];
        }

        return $out;
    }

    private static function expandOccurrences(array $impact, \DateTimeImmutable $rangeStartUtc, \DateTimeImmutable $rangeEndUtc, \DateTimeZone $tz): array
    {
        $baseStartUtc = self::dtUtc((string)($impact['start_utc'] ?? ''));
        $baseEndUtc   = self::dtUtc((string)($impact['end_utc'] ?? ''));
        if (!$baseStartUtc || !$baseEndUtc) return [];

        if (empty($impact['rec_enabled'])) {
            return [[ 'start_utc' => $baseStartUtc, 'end_utc' => $baseEndUtc ]];
        }

        $rule = is_array($impact['rec_rule'] ?? null) ? (array)$impact['rec_rule'] : [];
        $freq = isset($rule['freq']) ? (string)$rule['freq'] : 'weekly';
        $interval = isset($rule['interval']) ? max(1, (int)$rule['interval']) : 1;

        $until = isset($rule['until']) ? (string)$rule['until'] : '';
        $untilLocal = null;
        if ($until && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
            $untilLocal = new \DateTimeImmutable($until . ' 23:59:59', $tz);
        }

        $baseStartLocal = $baseStartUtc->setTimezone($tz);
        $durationSec = max(60, $baseEndUtc->getTimestamp() - $baseStartUtc->getTimestamp());
        $startTime = $baseStartLocal->format('H:i:s');

        $rangeStartLocal = $rangeStartUtc->setTimezone($tz)->setTime(0, 0, 0);
        $rangeEndLocal   = $rangeEndUtc->setTimezone($tz)->setTime(23, 59, 59);

        $anchorDate = $baseStartLocal->format('Y-m-d');

        $out = [];
        $cursor = $rangeStartLocal;

        while ($cursor <= $rangeEndLocal) {
            if ($untilLocal && $cursor->setTime(23, 59, 59) > $untilLocal) break;

            $dateStr = $cursor->format('Y-m-d');

            if (self::dateMatchesRecurrence($freq, $rule, $interval, $dateStr, $anchorDate, $tz)) {
                $occStartLocal = new \DateTimeImmutable($dateStr . ' ' . $startTime, $tz);
                $occEndLocal   = $occStartLocal->modify('+' . $durationSec . ' seconds');
                $occStartUtc = $occStartLocal->setTimezone(new \DateTimeZone('UTC'));
                $occEndUtc   = $occEndLocal->setTimezone(new \DateTimeZone('UTC'));

                if (self::overlapsRange($occStartUtc, $occEndUtc, $rangeStartUtc, $rangeEndUtc)) {
                    $out[] = ['start_utc' => $occStartUtc, 'end_utc' => $occEndUtc];
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        if (self::overlapsRange($baseStartUtc, $baseEndUtc, $rangeStartUtc, $rangeEndUtc)) {
            $out[] = ['start_utc' => $baseStartUtc, 'end_utc' => $baseEndUtc];
        }

        return $out;
    }

    private static function dateMatchesRecurrence(string $freq, array $rule, int $interval, string $dateStr, string $anchorDateStr, \DateTimeZone $tz): bool
    {
        $date   = new \DateTimeImmutable($dateStr . ' 12:00:00', $tz);
        $anchor = new \DateTimeImmutable($anchorDateStr . ' 12:00:00', $tz);

        $daysAllowed = ['MO','TU','WE','TH','FR','SA','SU'];

        if ($freq === 'weekly') {
            $byday = isset($rule['byday']) && is_array($rule['byday']) ? $rule['byday'] : ['MO'];
            $byday = array_values(array_unique(array_filter($byday, fn($d) => in_array($d, $daysAllowed, true))));
            if (empty($byday)) $byday = ['MO'];

            $anchorWeekStart = $anchor->modify('monday this week')->setTime(0, 0, 0);
            $dateWeekStart   = $date->modify('monday this week')->setTime(0, 0, 0);
            $weeksDiff = (int) floor(($dateWeekStart->getTimestamp() - $anchorWeekStart->getTimestamp()) / 604800);

            if ($weeksDiff < 0) return false;
            if (($weeksDiff % $interval) !== 0) return false;

            $weekday = strtoupper($date->format('D'));
            $map = ['MON'=>'MO','TUE'=>'TU','WED'=>'WE','THU'=>'TH','FRI'=>'FR','SAT'=>'SA','SUN'=>'SU'];
            $code = $map[$weekday] ?? 'MO';

            return in_array($code, $byday, true);
        }

        $pos = isset($rule['month_pos']) ? (string)$rule['month_pos'] : '1';
        if (!in_array($pos, ['1','2','3','4','-1'], true)) $pos = '1';

        $md = isset($rule['month_day']) ? (string)$rule['month_day'] : 'MO';
        if (!in_array($md, $daysAllowed, true)) $md = 'MO';

        $anchorIndex = ((int)$anchor->format('Y')) * 12 + (int)$anchor->format('n');
        $dateIndex   = ((int)$date->format('Y')) * 12 + (int)$date->format('n');
        $monthsDiff  = $dateIndex - $anchorIndex;

        if ($monthsDiff < 0) return false;
        if (($monthsDiff % $interval) !== 0) return false;

        $targetDate = self::nthWeekdayOfMonth((int)$date->format('Y'), (int)$date->format('n'), $md, (int)$pos, $tz);
        return $targetDate === $dateStr;
    }

    private static function nthWeekdayOfMonth(int $year, int $month, string $weekdayCode, int $pos, \DateTimeZone $tz): string
    {
        $map = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];
        $want = $map[$weekdayCode] ?? 1;

        if ($pos === -1) {
            $lastDay = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
            $lastDay = $lastDay->modify('last day of this month')->setTime(12, 0, 0);
            while ((int)$lastDay->format('N') !== $want) $lastDay = $lastDay->modify('-1 day');
            return $lastDay->format('Y-m-d');
        }

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 12:00:00', $year, $month), $tz);
        while ((int)$first->format('N') !== $want) $first = $first->modify('+1 day');

        $target = $first->modify('+' . (($pos - 1) * 7) . ' days');
        if ((int)$target->format('n') !== $month) return '0000-00-00';
        return $target->format('Y-m-d');
    }

    private static function effectWindowForOccurrence(\DateTimeImmutable $occStartUtc, \DateTimeImmutable $occEndUtc, array $eff, \DateTimeZone $tz): array
    {
        $mode = isset($eff['time_mode']) ? (string)$eff['time_mode'] : 'inherit';
        if ($mode !== 'custom') return [$occStartUtc, $occEndUtc];

        $st = isset($eff['start_time']) ? (string)$eff['start_time'] : '';
        $en = isset($eff['end_time']) ? (string)$eff['end_time'] : '';

        if (!preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $en)) {
            return [$occStartUtc, $occEndUtc];
        }

        $occLocalDate = $occStartUtc->setTimezone($tz)->format('Y-m-d');

        try {
            $startLocal = new \DateTimeImmutable($occLocalDate . ' ' . $st . ':00', $tz);
            $endLocal   = new \DateTimeImmutable($occLocalDate . ' ' . $en . ':00', $tz);
            if ($endLocal <= $startLocal) $endLocal = $endLocal->modify('+1 day');

            $startUtc = $startLocal->setTimezone(new \DateTimeZone('UTC'));
            $endUtc   = $endLocal->setTimezone(new \DateTimeZone('UTC'));

            if ($endUtc <= $startUtc) return [$occStartUtc, $occEndUtc];
            return [$startUtc, $endUtc];
        } catch (\Throwable $e) {
            return [$occStartUtc, $occEndUtc];
        }
    }

    private static function candidateFromEffect(array $impact, array $eff, array $catalog): ?array
    {
        $slug = (string)($eff['status'] ?? '');
        if ($slug === '' || !isset($catalog[$slug])) return null;

        return [
            'status_slug' => $slug,
            'status_label' => $catalog[$slug]['label'],
            'severity' => (int)$catalog[$slug]['severity'],
            'priority' => (int)($impact['priority'] ?? 10),
            'reason' => (string)($eff['reason'] ?? ''),
            'source_title' => (string)($impact['title'] ?? ''),
            'source_id' => (int)($impact['id'] ?? 0),
            'is_default' => false,
        ];
    }

    private static function defaultStatusPayload(string $target, array $defaults, array $catalog): array
    {
        $slug = $defaults[$target] ?? 'open';
        if (!isset($catalog[$slug])) {
            $first = array_key_first($catalog);
            if ($first) $slug = $first;
        }

        $label = $catalog[$slug]['label'] ?? $slug;
        $sev = (int)($catalog[$slug]['severity'] ?? 0);

        return [
            'status_slug' => $slug,
            'status_label' => $label,
            'severity' => $sev,
            'priority' => -1,
            'reason' => '',
            'source_title' => '',
            'source_id' => 0,
            'is_default' => true,
        ];
    }

    private static function pickBetter(array $current, array $candidate): array
    {
        if (($candidate['priority'] ?? 0) > ($current['priority'] ?? 0)) return $candidate;
        if (($candidate['priority'] ?? 0) < ($current['priority'] ?? 0)) return $current;

        if (($candidate['severity'] ?? 0) > ($current['severity'] ?? 0)) return $candidate;
        if (($candidate['severity'] ?? 0) < ($current['severity'] ?? 0)) return $current;

        return $current;
    }

    private static function dtUtc(string $atom): ?\DateTimeImmutable
    {
        try {
            $dt = new \DateTimeImmutable($atom);
            return $dt->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function overlapsInstant(\DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc, \DateTimeImmutable $instantUtc): bool
    {
        return ($startUtc <= $instantUtc) && ($instantUtc < $endUtc);
    }

    private static function overlapsRange(\DateTimeImmutable $aStart, \DateTimeImmutable $aEnd, \DateTimeImmutable $bStart, \DateTimeImmutable $bEnd): bool
    {
        return ($aStart <= $bEnd) && ($aEnd >= $bStart);
    }
}

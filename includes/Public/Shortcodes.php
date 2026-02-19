<?php
declare(strict_types=1);

namespace PlatzStatus\PublicSide;

use PlatzStatus\Services\Engine;

final class Shortcodes
{
    /** Targets, für die ein Ereignis Sinn macht */
    private const EVENT_TARGETS = ['holes_1_9', 'holes_10_18'];

    public static function register(): void
    {
        add_shortcode('platzstatus_now', [self::class, 'now']);
        add_shortcode('platzstatus_table', [self::class, 'table']);
        add_shortcode('platzstatus_target', [self::class, 'target']);
    }

    /**
     * Einzel-Card für genau ein Target.
     * Beispiel: [platzstatus_target target="holes_1_9" wrap_class="ps-now--single" class="my-card"]
     */
    public static function target(array $atts = []): string
    {
        $atts = shortcode_atts([
            'target' => '',
            'wrap_class' => '',
            'class' => '',
        ], $atts, 'platzstatus_target');

        $targetsAll = Engine::targets();
        $t = sanitize_key((string)$atts['target']);
        if ($t === '' || !isset($targetsAll[$t])) {
            return '';
        }

        $wrapClass = self::sanitizeClassList((string)$atts['wrap_class']);
        $cardClass = self::sanitizeClassList((string)$atts['class']);

        $data = Engine::resolveNow();
        $s = $data[$t] ?? null;
        if (!is_array($s)) return '';

        $statusSlug  = esc_attr((string)($s['status_slug'] ?? ''));
        $sev         = (int)($s['severity'] ?? 0);
        $reason      = trim((string)($s['reason'] ?? ''));
        $statusLabel = (string)($s['status_label'] ?? '');
        $label       = (string)($targetsAll[$t] ?? $t);

        $subText = ($reason !== '') ? $reason : $statusLabel;

        $html = '<div class="ps ps-now' . ($wrapClass ? ' ' . esc_attr($wrapClass) : '') . '">';
        $html .= sprintf(
            '<div class="ps-card %6$s ps-target--%1$s ps-status--%2$s ps-sev-%3$d" data-target="%1$s">
                <div class="ps-dot" aria-hidden="true"></div>
                <div class="ps-text">
                    <div class="ps-title">%4$s</div>
                    <div class="ps-sub">%5$s</div>
                </div>
            </div>',
            esc_attr((string)$t),
            $statusSlug,
            $sev,
            esc_html($label),
            esc_html($subText),
            $cardClass ? esc_attr($cardClass) : ''
        );
        $html .= '</div>';

        return $html;
    }

    /**
     * NOW:
     * Optional: targets="holes_1_9,holes_10_18" und wrap_class="ps-now--home"
     */
    public static function now(array $atts = []): string
    {
        $atts = shortcode_atts([
            'targets' => '',
            'wrap_class' => '',
        ], $atts, 'platzstatus_now');

        $targetsAll = Engine::targets();
        $data = Engine::resolveNow();

        $wantedTargets = self::parseTargetsList((string)$atts['targets'], array_keys($targetsAll));
        if ($wantedTargets === []) {
            $wantedTargets = array_keys($targetsAll);
        }

        $wrapClass = self::sanitizeClassList((string)$atts['wrap_class']);

        $html = '<div class="ps ps-now' . ($wrapClass ? ' ' . esc_attr($wrapClass) : '') . '">';

        foreach ($wantedTargets as $slug) {
            $label = $targetsAll[$slug] ?? $slug;
            $s = $data[$slug] ?? null;
            if (!is_array($s)) continue;

            $statusSlug  = esc_attr((string)($s['status_slug'] ?? ''));
            $sev         = (int)($s['severity'] ?? 0);
            $reason      = trim((string)($s['reason'] ?? ''));
            $statusLabel = (string)($s['status_label'] ?? '');

            // Subline: reason bevorzugt, sonst status_label
            $subText = ($reason !== '') ? $reason : $statusLabel;

            $titleHtml = '<span class="ps-title-main">' . esc_html((string)$label) . '</span>';

            $html .= sprintf(
                '<div class="ps-card ps-target--%1$s ps-status--%2$s ps-sev-%3$d" data-target="%1$s">
                    <div class="ps-dot" aria-hidden="true"></div>
                    <div class="ps-text">
                        <div class="ps-title">%4$s</div>
                        <div class="ps-sub">%5$s</div>
                    </div>
                </div>',
                esc_attr((string)$slug),
                $statusSlug,
                $sev,
                $titleHtml,
                esc_html($subText)
            );
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * TABLE (DIV Grid):
     * Optional: targets="holes_1_9,holes_10_18,ecarts" und wrap_class="ps-table--home"
     */
    public static function table(array $atts = []): string
    {
        $atts = shortcode_atts([
            'days' => '7',
            'targets' => '',
            'wrap_class' => '',
        ], $atts, 'platzstatus_table');

        $days = (int)$atts['days'];
        $days = max(1, min(14, $days));

        $targetsAll = Engine::targets();
        $wantedTargets = self::parseTargetsList((string)$atts['targets'], array_keys($targetsAll));
        if ($wantedTargets === []) {
            $wantedTargets = array_keys($targetsAll);
        }

        $wrapClass = self::sanitizeClassList((string)$atts['wrap_class']);

        $matrix  = Engine::resolvePreviewDays($days);
        $tz      = wp_timezone();

        $html  = '<div class="ps ps-table-wrap' . ($wrapClass ? ' ' . esc_attr($wrapClass) : '') . '">';

        // Header
        $html .= '<div class="ps-table-head">';
        $html .= '<div class="ps-col ps-col-date">Datum</div>';
        $html .= '<div class="ps-col ps-col-event">Ereignis</div>';
        foreach ($wantedTargets as $slug) {
            $label = $targetsAll[$slug] ?? $slug;
            $html .= '<div class="ps-col ps-col-' . esc_attr((string)$slug) . '">' . esc_html((string)$label) . '</div>';
        }
        $html .= '</div>';

        // Rows
        foreach ($matrix as $dayKey => $row) {
            /** @var \DateTimeImmutable $dLocal */
            $dLocal = $row['date_local']->setTimezone($tz);
            $ts = $dLocal->getTimestamp();

            $weekday  = wp_date('l', $ts);
            $dateMain = wp_date('d.m.Y', $ts);

            // Ereignisse nur aus 1–9 und 10–18 (unabhängig davon, ob Spalten gefiltert sind)
            $dayEvents = self::collectDayEventsForTargets($row, self::EVENT_TARGETS);
            $dayEventText = $dayEvents !== [] ? implode(' · ', $dayEvents) : '—';

            $html .= '<div class="ps-row">';

            $html .= '
                <div class="ps-cell ps-cell-date">
                    <div class="ps-date-weekday">' . esc_html($weekday) . ',</div>
                    <div class="ps-date-main">' . esc_html($dateMain) . '</div>
                </div>
            ';

            $html .= '
                <div class="ps-cell ps-cell-event">
                    <span class="ps-eventtext">' . esc_html($dayEventText) . '</span>
                </div>
            ';

            foreach ($wantedTargets as $tSlug) {
                $s = $row['targets'][$tSlug] ?? null;

                if (!is_array($s)) {
                    $html .= '<div class="ps-cell"></div>';
                    continue;
                }

                $statusSlug  = esc_attr((string)($s['status_slug'] ?? ''));
                $sev         = (int)($s['severity'] ?? 0);
                $statusLabel = (string)($s['status_label'] ?? '');
                $reason      = trim((string)($s['reason'] ?? ''));

                $main = $statusLabel;
                if ($reason !== '') {
                    $main .= ' — ' . $reason;
                }

                $html .= sprintf(
                    '<div class="ps-cell ps-status--%1$s ps-sev-%2$d"><span class="ps-celltext">%3$s</span></div>',
                    $statusSlug,
                    $sev,
                    esc_html($main)
                );
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /** Aggregiert Events nur aus bestimmten Targets (unique). */
    private static function collectDayEventsForTargets(array $row, array $targetSlugs): array
    {
        $out = [];
        $targets = $row['targets'] ?? [];
        if (!is_array($targets)) return [];

        foreach ($targetSlugs as $tSlug) {
            $s = $targets[$tSlug] ?? null;
            if (!is_array($s)) continue;

            $t = self::pickEventTitle($s);
            if ($t !== '') $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    /** Robust: Event-/Ereignis-Titel aus mehreren Keys lesen. */
    private static function pickEventTitle(array $s): string
    {
        $keys = [
            'event_title',
            'impact_title',
            'impact_name',
            'event_name',
            'title',
            'source_title',
            'impact_post_title',
        ];

        foreach ($keys as $k) {
            if (!empty($s[$k]) && is_string($s[$k])) {
                $t = trim($s[$k]);
                if ($t !== '') return $t;
            }
        }

        return '';
    }

    private static function parseTargetsList(string $csv, array $allowedKeys): array
    {
        $csv = trim($csv);
        if ($csv === '') return [];

        $allowed = array_fill_keys($allowedKeys, true);

        $parts = preg_split('/\s*,\s*/', $csv);
        if (!is_array($parts)) return [];

        $out = [];
        foreach ($parts as $p) {
            $k = sanitize_key((string)$p);
            if ($k !== '' && isset($allowed[$k])) {
                $out[] = $k;
            }
        }

        return array_values(array_unique($out));
    }

    private static function sanitizeClassList(string $classes): string
    {
        $classes = trim($classes);
        if ($classes === '') return '';

        $parts = preg_split('/\s+/', $classes);
        if (!is_array($parts)) return '';

        $out = [];
        foreach ($parts as $c) {
            $c = sanitize_html_class((string)$c);
            if ($c !== '') $out[] = $c;
        }

        return implode(' ', array_values(array_unique($out)));
    }
}

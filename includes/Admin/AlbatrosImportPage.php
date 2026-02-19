<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;

final class AlbatrosImportPage
{
    // Idempotency keys
    private const META_NR  = 'ps_albatros_nr';
    private const META_DGV = 'ps_albatros_dgv';

    // Additional tournament meta (new)
    private const META_ROUNDS        = 'ps_albatros_rounds';
    private const META_FORMAT        = 'ps_albatros_format';
    private const META_OPEN          = 'ps_albatros_open';
    private const META_HOLES         = 'ps_albatros_holes';
    private const META_REG_RAW       = 'ps_albatros_reg_raw';
    private const META_REG_START_UTC = 'ps_albatros_reg_start_utc';
    private const META_REG_END_UTC   = 'ps_albatros_reg_end_utc';

    // must match ImpactMetaBoxes.php / Engine.php meta keys
    private const META_ALL_DAY     = 'ps_all_day';
    private const META_START_UTC   = 'ps_start_utc';
    private const META_END_UTC     = 'ps_end_utc';
    private const META_PRIORITY    = 'ps_priority';
    private const META_EFFECTS     = 'ps_effects';
    private const META_ADVISORY    = 'ps_advisory_only';
    private const META_SHOW_HOME   = 'ps_show_on_home';

    private const OPTION_IMPACT_PT = 'platzstatus_impact_post_type';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
    }

    public static function addMenu(): void
    {
        // Settings → Albatros Import
        add_submenu_page(
            'options-general.php',
            'Albatros Import',
            'Albatros Import',
            Capabilities::CAP,
            'platzstatus-albatros-import',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            wp_die('Keine Berechtigung.');
        }

        $notice = '';
        $rowsPreview = [];
        $stats = ['create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0];

        // debug info
        $debug = [
            'delim' => '',
            'header_cols' => 0,
            'first_row_cols' => 0,
            'mismatch_rows' => 0,
        ];

        $action = isset($_POST['ps_alb_action']) ? (string)$_POST['ps_alb_action'] : '';

        if ($action !== '') {
            if (empty($_POST['ps_alb_nonce']) || !wp_verify_nonce((string)$_POST['ps_alb_nonce'], 'ps_alb_import')) {
                $notice = self::notice('Nonce ungültig. Bitte Seite neu laden.', 'error');
            } else {
                if (empty($_FILES['ps_alb_csv']) || !isset($_FILES['ps_alb_csv']['tmp_name']) || !is_uploaded_file($_FILES['ps_alb_csv']['tmp_name'])) {
                    $notice = self::notice('Keine CSV/TSV Datei hochgeladen.', 'error');
                } else {
                    $tmp = (string)$_FILES['ps_alb_csv']['tmp_name'];
                    $raw = file_get_contents($tmp);

                    if ($raw === false || trim($raw) === '') {
                        $notice = self::notice('Datei ist leer oder konnte nicht gelesen werden.', 'error');
                    } else {
                        $raw = self::stripUtf8Bom($raw);

                        $parsed = self::parseCsvLike($raw);
                        if (!$parsed['ok']) {
                            $notice = self::notice($parsed['error'] ?? 'CSV konnte nicht geparst werden.', 'error');
                        } else {
                            $debug['delim'] = $parsed['delim'] === "\t" ? 'TAB' : $parsed['delim'];
                            $debug['header_cols'] = is_array($parsed['header']) ? count($parsed['header']) : 0;
                            $debug['first_row_cols'] = isset($parsed['rows'][0]) && is_array($parsed['rows'][0]) ? count($parsed['rows'][0]) : 0;
                            $debug['mismatch_rows'] = (int)($parsed['mismatch_rows'] ?? 0);

                            // Guard: comma CSV with unquoted commas (common: DGV like "4,90E+11")
                            if ($parsed['delim'] === ',' && $debug['mismatch_rows'] > 0) {
                                $notice = self::notice(
                                    'CSV wirkt komma-separiert, aber Zeilen haben eine andere Spaltenanzahl als der Header. ' .
                                    'Das passiert häufig durch Werte wie "4,90E+11" (Komma im Feld ohne Quotes). ' .
                                    'Bitte exportiere als TSV (Tab) oder CSV mit Semikolon oder stelle sicher, dass Felder mit Komma korrekt gequotet sind.',
                                    'error'
                                );
                            } else {
                                $mapped = self::mapRows($parsed['header'], $parsed['rows']);
                                if (!$mapped['ok']) {
                                    $notice = self::notice($mapped['error'] ?? 'Spalten konnten nicht gemappt werden.', 'error');
                                } else {
                                    $records = $mapped['records'];

                                    if ($action === 'preview') {
                                        [$rowsPreview, $stats] = self::buildPreview($records);
                                        $notice = self::notice('Vorschau erstellt. (Noch nichts importiert.)', 'success');
                                    } elseif ($action === 'import') {
                                        [$rowsPreview, $stats, $importNotice] = self::doImport($records);
                                        $notice = $importNotice;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        ?>
        <div class="wrap">
            <h1>Albatros Import</h1>

            <p class="description">
                Importiert Turniere/Ereignisse idempotent anhand der Spalte <strong>Nr.</strong> (Meta: <code><?php echo esc_html(self::META_NR); ?></code>).
                Sperren werden als Effekte je Bereich geschrieben (Status-Slug: <code>closed</code>).
            </p>

            <p class="description">
                <strong>Custom-Zeiten</strong> werden <em>nur</em> gesetzt, wenn beide Sperr-Felder reine Uhrzeiten sind (z. B. <code>10:00</code> / <code>14:00</code>).
                Bei Text wie „Kanonenstart …“ wird automatisch <em>vom Ereignis</em> geerbt (inherit).
            </p>

            <p class="description">
                Zusätzlich importiert: <strong>Anzahl Runden</strong>, <strong>Wettspielart</strong>, <strong>Offenes Turnier</strong>, <strong>Anmeldefenster</strong> (roh + parsed UTC),
                <strong>9/18 Loch</strong>.
            </p>

            <?php if ($notice): ?>
                <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>

            <div style="background:#fff; border:1px solid #ccd0d4; padding:16px; border-radius:8px; max-width:1100px;">
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('ps_alb_import', 'ps_alb_nonce'); ?>

                    <p>
                        <label><strong>CSV/TSV Datei</strong></label><br>
                        <input type="file" name="ps_alb_csv" accept=".csv,.tsv,.txt,text/csv,text/plain" required>
                    </p>

                    <p class="description" style="margin-top:0;">
                        Unterstützt Tab, Semikolon oder Komma als Trennzeichen. Kopfzeile wird benötigt.
                        <br>Empfehlung: <strong>TSV (Tab)</strong> oder <strong>CSV mit Semikolon</strong>.
                    </p>

                    <p style="display:flex; gap:10px; align-items:center;">
                        <button class="button" type="submit" name="ps_alb_action" value="preview">Dry Run (Vorschau)</button>
                        <button class="button button-primary" type="submit" name="ps_alb_action" value="import"
                                onclick="return confirm('Import wirklich ausführen? (Upsert)');">
                            Import ausführen
                        </button>
                    </p>
                </form>
            </div>

            <?php if ($action !== '' && $debug['delim'] !== ''): ?>
                <div style="margin-top:14px; max-width:1100px; background:#fff; border:1px solid #ccd0d4; padding:12px; border-radius:8px;">
                    <strong>Parser-Debug:</strong>
                    Delimiter: <code><?php echo esc_html((string)$debug['delim']); ?></code> ·
                    Header-Spalten: <code><?php echo esc_html((string)$debug['header_cols']); ?></code> ·
                    Erste Datenzeile-Spalten: <code><?php echo esc_html((string)$debug['first_row_cols']); ?></code> ·
                    Mismatch-Zeilen: <code><?php echo esc_html((string)$debug['mismatch_rows']); ?></code>
                </div>
            <?php endif; ?>

            <?php if (!empty($rowsPreview)): ?>
                <h2 style="margin-top:22px;">Ergebnis</h2>

                <p>
                    <strong>Create:</strong> <?php echo esc_html((string)$stats['create']); ?> ·
                    <strong>Update:</strong> <?php echo esc_html((string)$stats['update']); ?> ·
                    <strong>Skip:</strong> <?php echo esc_html((string)$stats['skip']); ?> ·
                    <strong>Errors:</strong> <?php echo esc_html((string)$stats['errors']); ?>
                </p>

                <table class="widefat striped" style="max-width: 1600px;">
                    <thead>
                    <tr>
                        <th style="width:90px;">Action</th>
                        <th style="width:70px;">Nr.</th>
                        <th>Turniername</th>
                        <th style="width:110px;">9/18</th>
                        <th style="width:90px;">Runden</th>
                        <th style="width:130px;">Wettspiel</th>
                        <th style="width:70px;">Offen</th>
                        <th style="width:160px;">Datum</th>
                        <th style="width:160px;">Event</th>
                        <th style="width:240px;">Anmeldung</th>
                        <th style="width:320px;">Effekte</th>
                        <th style="width:110px;">WP Post</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rowsPreview as $r): ?>
                        <tr>
                            <td><code><?php echo esc_html($r['action']); ?></code></td>
                            <td><?php echo esc_html($r['nr']); ?></td>
                            <td><?php echo esc_html($r['title']); ?></td>
                            <td><?php echo esc_html((string)($r['holes'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($r['rounds'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($r['format'] ?? '')); ?></td>
                            <td><?php echo esc_html(!empty($r['open']) ? 'Ja' : 'Nein'); ?></td>
                            <td><?php echo esc_html($r['date']); ?></td>
                            <td><?php echo esc_html($r['event_window']); ?></td>
                            <td><?php echo esc_html((string)($r['reg_window'] ?? '')); ?></td>
                            <td><?php echo esc_html($r['effects']); ?></td>
                            <td>
                                <?php if (!empty($r['post_id'])): ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int)$r['post_id'], '')); ?>">
                                        #<?php echo esc_html((string)$r['post_id']); ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        </div>
        <?php
    }

    // ---------------------------
    // Import core
    // ---------------------------

    private static function doImport(array $records): array
    {
        $rowsPreview = [];
        $stats = ['create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0];

        $pt = self::impactPostType();
        $tz = wp_timezone();

        foreach ($records as $rec) {
            $previewRow = self::previewRowFromRecord($rec);

            try {
                $nr = trim((string)($rec['nr'] ?? ''));
                $name = trim((string)($rec['turniername'] ?? ''));

                if ($nr === '' || $name === '') {
                    $stats['skip']++;
                    $rowsPreview[] = $previewRow;
                    continue;
                }

                $startDate = trim((string)($rec['startdatum'] ?? ''));
                $startTime = trim((string)($rec['turnierbeginn_zeit'] ?? ''));
                $endTime   = trim((string)($rec['endzeit'] ?? ''));

                [$startUtc, $endUtc, $eventWindowText] = self::buildEventWindowUtc($startDate, $startTime, $endTime, $tz);
                $previewRow['event_window'] = $eventWindowText;

                if ($startUtc === '' || $endUtc === '') {
                    $previewRow['action'] = 'skip';
                    $previewRow['effects'] = 'Ungültiges Datum/Zeit';
                    $stats['skip']++;
                    $rowsPreview[] = $previewRow;
                    continue;
                }

                $effects = self::buildEffectsFromRecord($rec);
                $previewRow['effects'] = self::effectsHuman($effects);

                // additional tournament fields
                $rounds = self::toInt($rec['anzahl_runden'] ?? '');
                $format = trim((string)($rec['wettspielart'] ?? ''));
                $open   = self::parseYesNo($rec['offenes_turnier'] ?? '');
                $holes  = self::parseHoles($rec['holes_9_18'] ?? '');
                $regRaw = trim((string)($rec['anmeldung_beginn_ende'] ?? ''));

                $previewRow['rounds'] = $rounds > 0 ? $rounds : '';
                $previewRow['format'] = $format;
                $previewRow['open']   = $open ? 1 : 0;
                $previewRow['holes']  = $holes > 0 ? $holes : '';
                $previewRow['reg_window'] = $regRaw;

                [$regStartUtc, $regEndUtc] = self::parseRegistrationWindowUtc($regRaw, $tz);

                $existingId = self::findPostByNr($pt, $nr);

                $postarr = [
                    'post_type' => $pt,
                    'post_status' => 'publish',
                    'post_title' => $name,
                    'post_content' => '',
                ];

                if ($existingId > 0) {
                    $postarr['ID'] = $existingId;
                    $postId = wp_update_post($postarr, true);
                    $previewRow['action'] = 'update';
                    $stats['update']++;
                } else {
                    $postId = wp_insert_post($postarr, true);
                    $previewRow['action'] = 'create';
                    $stats['create']++;
                }

                if (is_wp_error($postId) || (int)$postId <= 0) {
                    $previewRow['action'] = 'error';
                    $previewRow['effects'] = 'WP insert/update Fehler';
                    $stats['errors']++;
                    $rowsPreview[] = $previewRow;
                    continue;
                }

                $postId = (int)$postId;
                $previewRow['post_id'] = $postId;

                // Core meta
                update_post_meta($postId, self::META_ALL_DAY, 0);
                update_post_meta($postId, self::META_START_UTC, $startUtc);
                update_post_meta($postId, self::META_END_UTC, $endUtc);

                // Tournament default priority (good starting point)
                update_post_meta($postId, self::META_PRIORITY, 50);

                update_post_meta($postId, self::META_ADVISORY, 0);
                update_post_meta($postId, self::META_SHOW_HOME, 0);

                update_post_meta($postId, self::META_EFFECTS, $effects);

                // Idempotency
                update_post_meta($postId, self::META_NR, $nr);

                $dgv = trim((string)($rec['dgv_turniernummer'] ?? ''));
                if ($dgv !== '') {
                    update_post_meta($postId, self::META_DGV, $dgv);
                }

                // Additional tournament meta
                if ($rounds > 0) {
                    update_post_meta($postId, self::META_ROUNDS, $rounds);
                } else {
                    delete_post_meta($postId, self::META_ROUNDS);
                }

                if ($format !== '') {
                    update_post_meta($postId, self::META_FORMAT, $format);
                } else {
                    delete_post_meta($postId, self::META_FORMAT);
                }

                update_post_meta($postId, self::META_OPEN, $open ? 1 : 0);

                if ($holes > 0) {
                    update_post_meta($postId, self::META_HOLES, $holes);
                } else {
                    delete_post_meta($postId, self::META_HOLES);
                }

                if ($regRaw !== '') {
                    update_post_meta($postId, self::META_REG_RAW, $regRaw);
                } else {
                    delete_post_meta($postId, self::META_REG_RAW);
                }

                if ($regStartUtc !== '' && $regEndUtc !== '') {
                    update_post_meta($postId, self::META_REG_START_UTC, $regStartUtc);
                    update_post_meta($postId, self::META_REG_END_UTC, $regEndUtc);
                } else {
                    delete_post_meta($postId, self::META_REG_START_UTC);
                    delete_post_meta($postId, self::META_REG_END_UTC);
                }

                $rowsPreview[] = $previewRow;
            } catch (\Throwable $e) {
                $previewRow['action'] = 'error';
                $previewRow['effects'] = 'Exception: ' . $e->getMessage();
                $stats['errors']++;
                $rowsPreview[] = $previewRow;
            }
        }

        self::bumpCacheVersion();

        $notice = self::notice(
            sprintf(
                'Import abgeschlossen. Create: %d, Update: %d, Skip: %d, Errors: %d',
                $stats['create'],
                $stats['update'],
                $stats['skip'],
                $stats['errors']
            ),
            ($stats['errors'] > 0) ? 'warning' : 'success'
        );

        return [$rowsPreview, $stats, $notice];
    }

    private static function buildPreview(array $records): array
    {
        $rowsPreview = [];
        $stats = ['create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0];

        $pt = self::impactPostType();
        $tz = wp_timezone();

        foreach ($records as $rec) {
            $nr = trim((string)($rec['nr'] ?? ''));
            $name = trim((string)($rec['turniername'] ?? ''));

            $row = self::previewRowFromRecord($rec);
            $row['nr'] = $nr;
            $row['title'] = $name;

            if ($nr === '' || $name === '') {
                $stats['skip']++;
                $rowsPreview[] = $row;
                continue;
            }

            $startDate = trim((string)($rec['startdatum'] ?? ''));
            $startTime = trim((string)($rec['turnierbeginn_zeit'] ?? ''));
            $endTime   = trim((string)($rec['endzeit'] ?? ''));

            [, , $eventWindowText] = self::buildEventWindowUtc($startDate, $startTime, $endTime, $tz);
            $row['event_window'] = $eventWindowText;

            $effects = self::buildEffectsFromRecord($rec);
            $row['effects'] = self::effectsHuman($effects);

            $row['rounds'] = self::toInt($rec['anzahl_runden'] ?? '') ?: '';
            $row['format'] = trim((string)($rec['wettspielart'] ?? ''));
            $row['open']   = self::parseYesNo($rec['offenes_turnier'] ?? '') ? 1 : 0;
            $row['holes']  = self::parseHoles($rec['holes_9_18'] ?? '') ?: '';
            $row['reg_window'] = trim((string)($rec['anmeldung_beginn_ende'] ?? ''));

            $existingId = self::findPostByNr($pt, $nr);
            if ($existingId > 0) {
                $row['action'] = 'update';
                $row['post_id'] = $existingId;
                $stats['update']++;
            } else {
                $row['action'] = 'create';
                $stats['create']++;
            }

            $rowsPreview[] = $row;
        }

        return [$rowsPreview, $stats];
    }

    private static function previewRowFromRecord(array $rec): array
    {
        return [
            'action' => 'skip',
            'nr' => (string)($rec['nr'] ?? ''),
            'title' => (string)($rec['turniername'] ?? ''),
            'date' => (string)($rec['startdatum'] ?? ''),
            'event_window' => '—',
            'reg_window' => (string)($rec['anmeldung_beginn_ende'] ?? ''),
            'holes' => '',
            'rounds' => '',
            'format' => '',
            'open' => 0,
            'effects' => '—',
            'post_id' => 0,
        ];
    }

    private static function buildEffectsFromRecord(array $rec): array
    {
        // Tee 1  -> holes_1_9
        // Tee 10 -> holes_10_18
        $effects = [];

        $t1FromRaw = (string)($rec['beginn_sperre_tee_1'] ?? '');
        $t1ToRaw   = (string)($rec['ende_sperre_tee_1'] ?? '');
        $effects = array_merge($effects, self::effectFromSperreFields('holes_1_9', $t1FromRaw, $t1ToRaw));

        $t10FromRaw = (string)($rec['beginn_sperre_tee_10'] ?? '');
        $t10ToRaw   = (string)($rec['ende_sperre_tee_10'] ?? '');
        $effects = array_merge($effects, self::effectFromSperreFields('holes_10_18', $t10FromRaw, $t10ToRaw));

        return $effects;
    }

    /**
     * Key logic:
     * - Custom-Zeit nur wenn beide Felder reine Uhrzeit sind (z. B. "10:00" / "14:00").
     * - Bei Text ("Kanonenstart ...") -> inherit (vom Ereignis).
     */
    private static function effectFromSperreFields(string $target, string $fromRaw, string $toRaw): array
    {
        $fromRaw = trim($fromRaw);
        $toRaw   = trim($toRaw);

        if (self::isFrei($fromRaw) && self::isFrei($toRaw)) {
            return [];
        }

        $fromTime = self::extractTimeHHMM($fromRaw);
        $toTime   = self::extractTimeHHMM($toRaw);

        $isPureFrom = self::isPureTimeField($fromRaw);
        $isPureTo   = self::isPureTimeField($toRaw);

        // ONLY if both fields are pure times -> custom
        if ($fromTime !== '' && $toTime !== '' && $isPureFrom && $isPureTo) {
            return [[
                'target' => $target,
                'status' => 'closed',
                'reason' => '',
                'time_mode' => 'custom',
                'start_time' => $fromTime,
                'end_time' => $toTime,
            ]];
        }

        // Otherwise: inherit + keep text as reason (best effort)
        $reason = self::pickReasonText($fromRaw, $toRaw);

        // If it was basically empty/frei, skip
        if ($reason === '' && (self::isFrei($fromRaw) || self::isFrei($toRaw))) {
            return [];
        }

        return [[
            'target' => $target,
            'status' => 'closed',
            'reason' => $reason,
            'time_mode' => 'inherit',
            'start_time' => '',
            'end_time' => '',
        ]];
    }

    private static function buildEventWindowUtc(string $dateRaw, string $startTimeRaw, string $endTimeRaw, \DateTimeZone $tz): array
    {
        $dateIso = self::parseDateToIso(trim($dateRaw));
        $startHHMM = self::extractTimeHHMM(trim($startTimeRaw));
        $endHHMM   = self::extractTimeHHMM(trim($endTimeRaw));

        if ($dateIso === '' || $startHHMM === '' || $endHHMM === '') {
            return ['', '', '—'];
        }

        try {
            $startLocal = new \DateTimeImmutable($dateIso . ' ' . $startHHMM . ':00', $tz);
            $endLocal   = new \DateTimeImmutable($dateIso . ' ' . $endHHMM . ':00', $tz);

            if ($endLocal <= $startLocal) {
                // crosses midnight
                $endLocal = $endLocal->modify('+1 day');
            }

            $startUtc = $startLocal->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
            $endUtc   = $endLocal->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);

            return [$startUtc, $endUtc, $startLocal->format('H:i') . '–' . $endLocal->format('H:i')];
        } catch (\Throwable $e) {
            return ['', '', '—'];
        }
    }

    /**
     * Parses: "02.01.2026 08:00 - 01.02.2026 10:00"
     * Returns UTC ISO strings or empty.
     */
    private static function parseRegistrationWindowUtc(string $raw, \DateTimeZone $tz): array
    {
        $raw = trim($raw);
        if ($raw === '') return ['', ''];

        $raw = preg_replace('/\s+/', ' ', $raw);

        // dd.mm.yyyy hh:mm - dd.mm.yyyy hh:mm
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4})\s+(\d{1,2}:\d{2})\s*-\s*(\d{2}\.\d{2}\.\d{4})\s+(\d{1,2}:\d{2})$/', $raw, $m)) {
            $d1 = self::parseDateToIso($m[1]);
            $t1 = self::extractTimeHHMM($m[2]);
            $d2 = self::parseDateToIso($m[3]);
            $t2 = self::extractTimeHHMM($m[4]);

            if ($d1 && $t1 && $d2 && $t2) {
                try {
                    $startLocal = new \DateTimeImmutable($d1 . ' ' . $t1 . ':00', $tz);
                    $endLocal   = new \DateTimeImmutable($d2 . ' ' . $t2 . ':00', $tz);

                    $startUtc = $startLocal->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
                    $endUtc   = $endLocal->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);

                    return [$startUtc, $endUtc];
                } catch (\Throwable $e) {
                    return ['', ''];
                }
            }
        }

        return ['', ''];
    }

    private static function impactPostType(): string
    {
        return (string) get_option(self::OPTION_IMPACT_PT, 'ereignis');
    }

    private static function findPostByNr(string $postType, string $nr): int
    {
        $nr = trim($nr);
        if ($nr === '') return 0;

        $q = new \WP_Query([
            'post_type' => $postType,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [[
                'key' => self::META_NR,
                'value' => $nr,
                'compare' => '=',
            ]],
        ]);

        if (!empty($q->posts) && is_array($q->posts)) {
            return (int)$q->posts[0];
        }
        return 0;
    }

    private static function effectsHuman(array $effects): string
    {
        if (empty($effects)) return '—';

        $parts = [];
        foreach ($effects as $e) {
            $t = (string)($e['target'] ?? '');
            $mode = (string)($e['time_mode'] ?? 'inherit');
            $st = (string)($e['start_time'] ?? '');
            $en = (string)($e['end_time'] ?? '');
            $r  = trim((string)($e['reason'] ?? ''));

            $time = ($mode === 'custom' && $st && $en) ? "{$st}–{$en}" : 'vom Ereignis';
            $txt = $t . ': closed (' . $time . ')';
            if ($r !== '') $txt .= ' — ' . $r;
            $parts[] = $txt;
        }
        return implode(' | ', $parts);
    }

    private static function bumpCacheVersion(): void
    {
        $v = (int) get_option('platzstatus_cache_version', 1);
        update_option('platzstatus_cache_version', $v + 1, false);
    }

    // ---------------------------
    // Parsing / mapping
    // ---------------------------

    private static function parseCsvLike(string $raw): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $raw);
        if (!is_array($lines)) {
            return ['ok' => false, 'error' => 'Zeilen konnten nicht gelesen werden.'];
        }

        $lines = array_values(array_filter($lines, static fn($l) => trim((string)$l) !== ''));
        if (count($lines) < 2) {
            return ['ok' => false, 'error' => 'Zu wenige Zeilen (Kopfzeile + Daten erforderlich).'];
        }

        $first = (string)$lines[0];

        $delim = "\t";
        if (strpos($first, "\t") !== false) {
            $delim = "\t";
        } elseif (strpos($first, ';') !== false) {
            $delim = ';';
        } elseif (strpos($first, ',') !== false) {
            $delim = ',';
        }

        $headerRaw = self::splitLine($first, $delim);
        $header = array_map([self::class, 'normalizeHeader'], $headerRaw);

        $rows = [];
        $mismatch = 0;

        for ($i = 1; $i < count($lines); $i++) {
            $cols = self::splitLine((string)$lines[$i], $delim);
            $cols = array_map('trim', $cols);

            if (count($cols) !== count($header)) {
                $mismatch++;
            }

            // normalize to header length
            if (count($cols) < count($header)) {
                $cols = array_merge($cols, array_fill(0, count($header) - count($cols), ''));
            } elseif (count($cols) > count($header)) {
                $cols = array_slice($cols, 0, count($header));
            }

            $rows[] = $cols;
        }

        return [
            'ok' => true,
            'header' => $header,
            'rows' => $rows,
            'delim' => $delim,
            'mismatch_rows' => $mismatch,
        ];
    }

    private static function mapRows(array $header, array $rows): array
    {
        $need = [
            'nr',
            'turniername',
            'dgv_turniernummer',
            'startdatum',
            'turnierbeginn_zeit',
            'endzeit',

            'anzahl_runden',
            'wettspielart',
            'offenes_turnier',
            'anmeldung_beginn_ende',
            'holes_9_18',

            'beginn_sperre_tee_1',
            'ende_sperre_tee_1',
            'beginn_sperre_tee_10',
            'ende_sperre_tee_10',
        ];

        $idx = array_flip($header);

        foreach ($need as $k) {
            if (!isset($idx[$k])) {
                return ['ok' => false, 'error' => "Spalte fehlt: {$k} (Header-Erkennung)"];
            }
        }

        $out = [];
        foreach ($rows as $cols) {
            if (!is_array($cols)) continue;

            $out[] = [
                'nr' => (string)$cols[$idx['nr']],
                'turniername' => (string)$cols[$idx['turniername']],
                'dgv_turniernummer' => (string)$cols[$idx['dgv_turniernummer']],
                'startdatum' => (string)$cols[$idx['startdatum']],
                'turnierbeginn_zeit' => (string)$cols[$idx['turnierbeginn_zeit']],
                'endzeit' => (string)$cols[$idx['endzeit']],

                'anzahl_runden' => (string)$cols[$idx['anzahl_runden']],
                'wettspielart' => (string)$cols[$idx['wettspielart']],
                'offenes_turnier' => (string)$cols[$idx['offenes_turnier']],
                'anmeldung_beginn_ende' => (string)$cols[$idx['anmeldung_beginn_ende']],
                'holes_9_18' => (string)$cols[$idx['holes_9_18']],

                'beginn_sperre_tee_1' => (string)$cols[$idx['beginn_sperre_tee_1']],
                'ende_sperre_tee_1' => (string)$cols[$idx['ende_sperre_tee_1']],
                'beginn_sperre_tee_10' => (string)$cols[$idx['beginn_sperre_tee_10']],
                'ende_sperre_tee_10' => (string)$cols[$idx['ende_sperre_tee_10']],
            ];
        }

        return ['ok' => true, 'records' => $out];
    }

    private static function normalizeHeader(string $h): string
    {
        $h = trim($h);
        $h = mb_strtolower($h);
        $h = preg_replace('/\s+/', ' ', $h);

        $map = [
            'nr.' => 'nr',
            'nr' => 'nr',
            'turniername' => 'turniername',
            'dgv turniernummer' => 'dgv_turniernummer',
            'startdatum' => 'startdatum',
            'turnierbeginn zeit' => 'turnierbeginn_zeit',
            'turnierbeginn uhrzeit' => 'turnierbeginn_zeit',
            'turnierbeginn' => 'turnierbeginn_zeit',
            'enzeit' => 'endzeit',
            'endzeit' => 'endzeit',
            'ende' => 'endzeit',

            'anzahl runden' => 'anzahl_runden',
            'wettspielart' => 'wettspielart',
            'offenes turnier' => 'offenes_turnier',
            'anmeldung beginn & ende' => 'anmeldung_beginn_ende',
            'anmeldung beginn und ende' => 'anmeldung_beginn_ende',
            'anmeldung beginn & ende ' => 'anmeldung_beginn_ende',
            '9/18 loch' => 'holes_9_18',

            'beginn sperre tee 1' => 'beginn_sperre_tee_1',
            'ende sperre tee 1' => 'ende_sperre_tee_1',
            'beginn sperre tee 10' => 'beginn_sperre_tee_10',
            'ende sperre tee 10' => 'ende_sperre_tee_10',
        ];

        return $map[$h] ?? sanitize_key(str_replace(' ', '_', $h));
    }

    private static function splitLine(string $line, string $delim): array
    {
        // Use CSV parser for ; and , (quotes possible). For TSV without quotes, explode is fine.
        if (strpos($line, '"') !== false || $delim !== "\t") {
            $arr = str_getcsv($line, $delim, '"', "\\");
            return is_array($arr) ? $arr : [];
        }
        return explode($delim, $line);
    }

    // ---------------------------
    // Utilities
    // ---------------------------

    private static function stripUtf8Bom(string $s): string
    {
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            return substr($s, 3);
        }
        return $s;
    }

    private static function parseDateToIso(string $dateRaw): string
    {
        $dateRaw = trim($dateRaw);

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateRaw, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
            return $dateRaw;
        }

        return '';
    }

    private static function extractTimeHHMM(string $raw): string
    {
        $raw = trim((string)$raw);
        if (self::isFrei($raw)) return '';

        if (preg_match('/\b(\d{1,2}):(\d{2})(?::\d{2})?\b/', $raw, $m)) {
            $hh = (int)$m[1];
            $mm = (int)$m[2];
            if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) return '';
            return sprintf('%02d:%02d', $hh, $mm);
        }
        return '';
    }

    private static function isPureTimeField(string $raw): bool
    {
        return (bool) preg_match('/^\s*\d{1,2}:\d{2}(:\d{2})?\s*$/', trim($raw));
    }

    private static function isFrei(string $raw): bool
    {
        $raw = trim(mb_strtolower((string)$raw));
        return $raw === 'frei' || $raw === 'free' || $raw === '—' || $raw === '-' || $raw === '';
    }

    private static function pickReasonText(string $a, string $b): string
    {
        $a = trim((string)$a);
        $b = trim((string)$b);

        if (self::isPureTimeField($a)) $a = '';
        if (self::isPureTimeField($b)) $b = '';

        if (self::isFrei($a)) $a = '';
        if (self::isFrei($b)) $b = '';

        $txt = $a !== '' ? $a : $b;
        $txt = wp_strip_all_tags($txt);
        $txt = preg_replace('/\s+/', ' ', (string)$txt);
        $txt = trim((string)$txt);

        if (strlen($txt) > 220) {
            $txt = mb_substr($txt, 0, 217) . '...';
        }

        return $txt;
    }

    private static function parseYesNo($raw): bool
    {
        $v = trim(mb_strtolower((string)$raw));
        if ($v === 'ja' || $v === 'yes' || $v === '1' || $v === 'true') return true;
        return false;
    }

    private static function parseHoles($raw): int
    {
        $v = trim((string)$raw);
        $n = (int)$v;
        return in_array($n, [9, 18], true) ? $n : 0;
    }

    private static function toInt($raw): int
    {
        $v = trim((string)$raw);
        if ($v === '') return 0;
        // normalize "1,0" etc.
        $v = str_replace(',', '.', $v);
        $f = (float)$v;
        return (int)round($f);
    }

    private static function notice(string $text, string $type = 'info'): string
    {
        $type = in_array($type, ['success', 'info', 'warning', 'error'], true) ? $type : 'info';
        return '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($text) . '</p></div>';
    }
}

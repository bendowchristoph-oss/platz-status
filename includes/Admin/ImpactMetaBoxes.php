<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;

final class ImpactMetaBoxes
{
    // Options
    private const OPTION_STATUS    = 'platzstatus_status_catalog';
    private const OPTION_IMPACT_PT = 'platzstatus_impact_post_type';

    // Meta keys
    private const META_ALL_DAY      = 'ps_all_day';
    private const META_START_UTC    = 'ps_start_utc'; // ISO8601 UTC string
    private const META_END_UTC      = 'ps_end_utc';   // ISO8601 UTC string
    private const META_PRIORITY     = 'ps_priority';
    private const META_EFFECTS      = 'ps_effects';   // array
    private const META_ADVISORY     = 'ps_advisory_only';
    private const META_SHOW_HOME    = 'ps_show_on_home';
    private const META_REC_ENABLED  = 'ps_recurrence_enabled';
    private const META_REC_RULE     = 'ps_recurrence_rule'; // array

    private static function impactPostType(): string
    {
        return (string) get_option(self::OPTION_IMPACT_PT, 'ereignis');
    }

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'addMetaBoxes']);
        add_action('save_post', [self::class, 'savePost'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
    }

    public static function addMetaBoxes(): void
    {
        $pt = self::impactPostType();

        add_meta_box(
            'ps_time',
            'Platzstatus: Zeitraum',
            [self::class, 'renderTimeBox'],
            $pt,
            'normal',
            'high'
        );

        add_meta_box(
            'ps_effects',
            'Platzstatus: Wirkung',
            [self::class, 'renderEffectsBox'],
            $pt,
            'normal',
            'high'
        );

        add_meta_box(
            'ps_priority',
            'Platzstatus: Priorität',
            [self::class, 'renderPriorityBox'],
            $pt,
            'normal',
            'default'
        );

        add_meta_box(
            'ps_recurrence',
            'Platzstatus: Wiederholung',
            [self::class, 'renderRecurrenceBox'],
            $pt,
            'normal',
            'default'
        );

        add_meta_box(
            'ps_display',
            'Platzstatus: Anzeige',
            [self::class, 'renderDisplayBox'],
            $pt,
            'side',
            'default'
        );
    }

    public static function enqueueAdminAssets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || empty($screen->post_type) || $screen->post_type !== self::impactPostType()) {
            return;
        }

        $js = <<<JS
(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  function toggleAllDay(box){
    var allDay = qs('input[name="ps_all_day"]', box);
    var dateRow = qs('[data-ps="date-row"]', box);
    var timeRow = qs('[data-ps="time-row"]', box);
    if(!allDay || !dateRow || !timeRow) return;
    if(allDay.checked){
      dateRow.style.display = '';
      timeRow.style.display = 'none';
    } else {
      dateRow.style.display = 'none';
      timeRow.style.display = '';
    }
  }

  function initAllDay(){
    var box = document.getElementById('ps_time');
    if(!box) return;
    toggleAllDay(box);
    var allDay = qs('input[name="ps_all_day"]', box);
    if(allDay){
      allDay.addEventListener('change', function(){ toggleAllDay(box); });
    }
  }

  function syncRowTimeMode(row){
    var modeSel = qs('select[data-ps-time-mode]', row);
    if(!modeSel) return;
    var panel = qs('[data-ps="row-time-panel"]', row);
    if(!panel) return;
    panel.style.display = (modeSel.value === 'custom') ? '' : 'none';
  }

  function initEffects(){
    var box = document.getElementById('ps_effects');
    if(!box) return;

    var tbody = qs('tbody[data-ps="effects-body"]', box);
    var addBtn = qs('button[data-ps="effects-add"]', box);
    var tpl = qs('template[data-ps="effects-template"]', box);

    function reindex(){
      var rows = qsa('tr[data-ps="row"]', tbody);
      rows.forEach(function(row, i){
        row.setAttribute('data-index', String(i));
        qsa('[data-ps-name]', row).forEach(function(el){
          var name = el.getAttribute('data-ps-name');
          el.setAttribute('name', name.replace('__i__', String(i)));
        });
        syncRowTimeMode(row);
      });
    }

    function removeRow(e){
      var btn = e.target.closest('button[data-ps="effects-remove"]');
      if(!btn) return;
      e.preventDefault();
      var row = btn.closest('tr[data-ps="row"]');
      if(row) row.remove();
      reindex();
    }

    function addRow(e){
      e.preventDefault();
      if(!tpl || !tbody) return;
      var frag = tpl.content.cloneNode(true);
      tbody.appendChild(frag);
      reindex();
    }

    function onChange(e){
      var row = e.target.closest('tr[data-ps="row"]');
      if(!row) return;
      if(e.target && e.target.matches('select[data-ps-time-mode]')){
        syncRowTimeMode(row);
      }
    }

    if(tbody){
      tbody.addEventListener('click', removeRow);
      tbody.addEventListener('change', onChange);
    }
    if(addBtn){
      addBtn.addEventListener('click', addRow);
    }
    reindex();
  }

  function initRecurrence(){
    var box = document.getElementById('ps_recurrence');
    if(!box) return;

    function sync(){
      var enabled = qs('input[name="ps_recurrence_enabled"]', box);
      var panel = qs('[data-ps="rec-panel"]', box);
      if(!enabled || !panel) return;
      panel.style.display = enabled.checked ? '' : 'none';

      var freq = qs('select[name="ps_recurrence[freq]"]', box);
      var weekly = qs('[data-ps="rec-weekly"]', box);
      var monthly = qs('[data-ps="rec-monthly"]', box);
      if(freq && weekly && monthly){
        weekly.style.display = (freq.value === 'weekly') ? '' : 'none';
        monthly.style.display = (freq.value === 'monthly') ? '' : 'none';
      }
    }

    box.addEventListener('change', function(e){
      if(e.target && (e.target.name === 'ps_recurrence_enabled' || e.target.name === 'ps_recurrence[freq]')){
        sync();
      }
    });

    sync();
  }

  document.addEventListener('DOMContentLoaded', function(){
    initAllDay();
    initEffects();
    initRecurrence();
  });
})();
JS;

        wp_register_script('platzstatus-admin-inline', '', [], '1.1.0', true);
        wp_enqueue_script('platzstatus-admin-inline');
        wp_add_inline_script('platzstatus-admin-inline', $js);
    }

    private static function activeStatuses(): array
    {
        $statuses = (array) get_option(self::OPTION_STATUS, []);
        $out = [];
        foreach ($statuses as $slug => $data) {
            $slug = is_string($slug) ? $slug : '';
            if ($slug === '' || $slug === '_new') continue;

            $label = isset($data['label']) ? (string) $data['label'] : $slug;
            $severity = isset($data['severity']) ? (int) $data['severity'] : 0;
            $active = !empty($data['active']) ? 1 : 0;

            $allowed = [];
            if (isset($data['allowed_targets']) && is_array($data['allowed_targets'])) {
                $allowed = array_values(array_filter($data['allowed_targets'], 'is_string'));
            }

            if ($active) {
                $out[$slug] = [
                    'label' => $label,
                    'severity' => $severity,
                    'allowed_targets' => $allowed, // leer = für alle
                ];
            }
        }
        return $out;
    }

    private static function targets(): array
    {
        return [
            'holes_1_9' => 'Bahnen 1–9',
            'holes_10_18' => 'Bahnen 10–18',
            'drivingrange' => 'Drivingrange',
            'ecarts' => 'E-Carts',
        ];
    }

    public static function renderTimeBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            echo '<p>Keine Berechtigung.</p>';
            return;
        }

        wp_nonce_field('ps_save_impact', 'ps_nonce');

        $allDay = (int) get_post_meta($post->ID, self::META_ALL_DAY, true) === 1;

        $startUtc = (string) get_post_meta($post->ID, self::META_START_UTC, true);
        $endUtc   = (string) get_post_meta($post->ID, self::META_END_UTC, true);

        $tz = wp_timezone();
        $startLocal = '';
        $endLocal = '';
        $startDate = '';
        $endDate = '';

        try {
            if ($startUtc) {
                $dt = new \DateTimeImmutable($startUtc);
                $dt = $dt->setTimezone($tz);
                $startLocal = $dt->format('Y-m-d\TH:i');
                $startDate = $dt->format('Y-m-d');
            }
            if ($endUtc) {
                $dt = new \DateTimeImmutable($endUtc);
                $dt = $dt->setTimezone($tz);
                $endLocal = $dt->format('Y-m-d\TH:i');
                $endDate = $dt->format('Y-m-d');
            }
        } catch (\Throwable $e) {
            // leave empty
        }

        ?>
        <p>
            <label>
                <input type="checkbox" name="ps_all_day" value="1" <?php checked($allDay); ?>>
                Ganztägig
            </label>
        </p>

        <div data-ps="date-row" style="display:none;">
            <p>
                <label>Start (Datum)</label><br>
                <input type="date" name="ps_start_date" value="<?php echo esc_attr($startDate); ?>" />
            </p>
            <p>
                <label>Ende (Datum)</label><br>
                <input type="date" name="ps_end_date" value="<?php echo esc_attr($endDate); ?>" />
            </p>
            <p class="description">Ganztägig setzt intern 00:00 bis 23:59:59 (lokale Zeit).</p>
        </div>

        <div data-ps="time-row">
            <p>
                <label>Start (Datum + Uhrzeit)</label><br>
                <input type="datetime-local" name="ps_start_dt" value="<?php echo esc_attr($startLocal); ?>" />
            </p>
            <p>
                <label>Ende (Datum + Uhrzeit)</label><br>
                <input type="datetime-local" name="ps_end_dt" value="<?php echo esc_attr($endLocal); ?>" />
            </p>
        </div>
        <?php
    }

    public static function renderPriorityBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) return;

        $priority = (int) get_post_meta($post->ID, self::META_PRIORITY, true);
        if ($priority === 0) $priority = 10;

        ?>
        <p>
            <label>Priorität (höher gewinnt)</label><br>
            <input type="number" name="ps_priority" value="<?php echo esc_attr((string)$priority); ?>" min="0" step="1">
        </p>
        <p class="description">Beispiel: Standard 10, Turnier 50, Frost/Unwetter 90.</p>
        <?php
    }

    public static function renderDisplayBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) return;

        $adv = (int) get_post_meta($post->ID, self::META_ADVISORY, true) === 1;
        $home = (int) get_post_meta($post->ID, self::META_SHOW_HOME, true) === 1;

        ?>
        <p>
            <label>
                <input type="checkbox" name="ps_advisory_only" value="1" <?php checked($adv); ?>>
                Nur Hinweis (ändert keinen Status)
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="ps_show_on_home" value="1" <?php checked($home); ?>>
                Auf Startseite anzeigen
            </label>
        </p>
        <?php
    }

    public static function renderEffectsBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            echo '<p>Keine Berechtigung.</p>';
            return;
        }

        $targets = self::targets();
        $statuses = self::activeStatuses();

        $effects = get_post_meta($post->ID, self::META_EFFECTS, true);
        if (!is_array($effects)) $effects = [];

        ?>
        <p class="description">
            Lege fest, welchen Status dieses Ereignis pro Bereich setzt (optional mit Grund).
            Optional kannst du pro Bereich eigene Uhrzeiten setzen (z. B. 1–9 10–14 Uhr, 10–18 12–16 Uhr).
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width: 16%;">Bereich</th>
                    <th style="width: 18%;">Status</th>
                    <th style="width: 22%;">Grund (optional)</th>
                    <th style="width: 26%;">Zeit je Bereich</th>
                    <th style="width: 1%;"></th>
                </tr>
            </thead>
            <tbody data-ps="effects-body">
                <?php if (empty($effects)): ?>
                    <tr data-ps="row" data-index="0">
                        <?php self::renderEffectRow(0, $targets, $statuses, [
                            'target' => 'holes_1_9',
                            'status' => 'open',
                            'reason' => '',
                            'time_mode' => 'inherit',
                            'start_time' => '',
                            'end_time' => '',
                        ]); ?>
                    </tr>
                <?php else: ?>
                    <?php foreach (array_values($effects) as $i => $row): ?>
                        <tr data-ps="row" data-index="<?php echo esc_attr((string)$i); ?>">
                            <?php self::renderEffectRow($i, $targets, $statuses, $row); ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <p style="margin-top:10px;">
            <button type="button" class="button" data-ps="effects-add">+ Bereich hinzufügen</button>
        </p>

        <template data-ps="effects-template">
            <tr data-ps="row" data-index="__i__">
                <?php self::renderEffectRow('__i__', $targets, $statuses, [
                    'target' => 'holes_1_9',
                    'status' => 'open',
                    'reason' => '',
                    'time_mode' => 'inherit',
                    'start_time' => '',
                    'end_time' => '',
                ]); ?>
            </tr>
        </template>

        <?php
    }

    private static function renderEffectRow($i, array $targets, array $statuses, $row): void
    {
        $target = is_array($row) && isset($row['target']) ? (string)$row['target'] : 'holes_1_9';
        $status = is_array($row) && isset($row['status']) ? (string)$row['status'] : 'open';
        $reason = is_array($row) && isset($row['reason']) ? (string)$row['reason'] : '';

        $timeMode = is_array($row) && isset($row['time_mode']) ? (string)$row['time_mode'] : 'inherit';
        if (!in_array($timeMode, ['inherit', 'custom'], true)) $timeMode = 'inherit';

        $startTime = is_array($row) && isset($row['start_time']) ? (string)$row['start_time'] : '';
        $endTime   = is_array($row) && isset($row['end_time']) ? (string)$row['end_time'] : '';

        ?>
        <td>
            <select data-ps-name="ps_effects[__i__][target]">
                <?php foreach ($targets as $slug => $label): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($target, $slug); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select data-ps-name="ps_effects[__i__][status]">
                <?php foreach ($statuses as $slug => $data): ?>
                    <?php
                        $allowed = $data['allowed_targets'] ?? [];
                        $ok = empty($allowed) || in_array($target, $allowed, true);
                        if (!$ok) continue;
                    ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($status, $slug); ?>>
                        <?php echo esc_html($data['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text"
                   class="widefat"
                   data-ps-name="ps_effects[__i__][reason]"
                   value="<?php echo esc_attr($reason); ?>"
                   placeholder="z. B. Frost, Turnier, Wintergrüns">
        </td>
        <td>
            <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                <label style="display:inline-flex; gap:6px; align-items:center;">
                    <span style="min-width:60px;">Zeit</span>
                    <select data-ps-name="ps_effects[__i__][time_mode]" data-ps-time-mode>
                        <option value="inherit" <?php selected($timeMode, 'inherit'); ?>>vom Ereignis</option>
                        <option value="custom" <?php selected($timeMode, 'custom'); ?>>eigene Zeit</option>
                    </select>
                </label>

                <div data-ps="row-time-panel" style="<?php echo ($timeMode === 'custom') ? '' : 'display:none;'; ?>">
                    <label style="display:inline-flex; gap:6px; align-items:center; margin-right:10px;">
                        <span>von</span>
                        <input type="time"
                               data-ps-name="ps_effects[__i__][start_time]"
                               value="<?php echo esc_attr($startTime); ?>"
                               step="60"
                               style="max-width:120px;">
                    </label>

                    <label style="display:inline-flex; gap:6px; align-items:center;">
                        <span>bis</span>
                        <input type="time"
                               data-ps-name="ps_effects[__i__][end_time]"
                               value="<?php echo esc_attr($endTime); ?>"
                               step="60"
                               style="max-width:120px;">
                    </label>

                    <div class="description" style="margin-top:4px;">
                        Wenn „bis“ &le; „von“ ist, läuft die Sperre über Mitternacht.
                    </div>
                </div>
            </div>
        </td>
        <td>
            <button type="button" class="button-link-delete" data-ps="effects-remove" title="Entfernen">✕</button>
        </td>
        <?php
    }

    public static function renderRecurrenceBox(\WP_Post $post): void
    {
        if (!current_user_can(Capabilities::CAP)) return;

        $enabled = (int) get_post_meta($post->ID, self::META_REC_ENABLED, true) === 1;
        $rule = get_post_meta($post->ID, self::META_REC_RULE, true);
        if (!is_array($rule)) $rule = [];

        $freq = $rule['freq'] ?? 'weekly';
        $interval = isset($rule['interval']) ? (int)$rule['interval'] : 1;
        $until = isset($rule['until']) ? (string)$rule['until'] : '';
        $byday = isset($rule['byday']) && is_array($rule['byday']) ? $rule['byday'] : ['MO'];
        $month_pos = $rule['month_pos'] ?? '1';
        $month_day = $rule['month_day'] ?? 'MO';

        $days = [
            'MO' => 'Mo', 'TU' => 'Di', 'WE' => 'Mi', 'TH' => 'Do', 'FR' => 'Fr', 'SA' => 'Sa', 'SU' => 'So'
        ];

        ?>
        <p>
            <label>
                <input type="checkbox" name="ps_recurrence_enabled" value="1" <?php checked($enabled); ?>>
                Wiederholt sich
            </label>
        </p>

        <div data-ps="rec-panel" style="display:none; border:1px solid #ddd; padding:12px; border-radius:6px;">
            <p>
                <label>Häufigkeit</label><br>
                <select name="ps_recurrence[freq]">
                    <option value="weekly" <?php selected($freq, 'weekly'); ?>>Wöchentlich</option>
                    <option value="monthly" <?php selected($freq, 'monthly'); ?>>Monatlich</option>
                </select>
            </p>

            <p>
                <label>Intervall</label><br>
                <input type="number" name="ps_recurrence[interval]" value="<?php echo esc_attr((string)max(1,$interval)); ?>" min="1" step="1">
                <span class="description">z. B. alle 1 Woche / alle 2 Wochen</span>
            </p>

            <div data-ps="rec-weekly">
                <p><strong>Wochentage</strong></p>
                <?php foreach ($days as $code => $label): ?>
                    <label style="margin-right:10px;">
                        <input type="checkbox" name="ps_recurrence[byday][]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $byday, true)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div data-ps="rec-monthly" style="display:none;">
                <p><strong>Monatsmuster</strong></p>
                <p>
                    <label>Position</label><br>
                    <select name="ps_recurrence[month_pos]">
                        <option value="1" <?php selected($month_pos, '1'); ?>>1.</option>
                        <option value="2" <?php selected($month_pos, '2'); ?>>2.</option>
                        <option value="3" <?php selected($month_pos, '3'); ?>>3.</option>
                        <option value="4" <?php selected($month_pos, '4'); ?>>4.</option>
                        <option value="-1" <?php selected($month_pos, '-1'); ?>>letzter</option>
                    </select>
                </p>
                <p>
                    <label>Wochentag</label><br>
                    <select name="ps_recurrence[month_day]">
                        <?php foreach ($days as $code => $label): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($month_day, $code); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>

            <p>
                <label>Bis (optional)</label><br>
                <input type="date" name="ps_recurrence[until]" value="<?php echo esc_attr($until); ?>">
                <span class="description">leer lassen = kein Enddatum</span>
            </p>
        </div>
        <?php
    }

    public static function savePost(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== self::impactPostType()) return;

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($postId)) return;

        if (!current_user_can(Capabilities::CAP)) return;

        if (empty($_POST['ps_nonce']) || !wp_verify_nonce((string)$_POST['ps_nonce'], 'ps_save_impact')) {
            return;
        }

        // -------- Time handling ----------
        $allDay = !empty($_POST['ps_all_day']) ? 1 : 0;
        update_post_meta($postId, self::META_ALL_DAY, $allDay);

        $tz = wp_timezone();

        $startUtc = '';
        $endUtc = '';

        try {
            if ($allDay) {
                $sd = isset($_POST['ps_start_date']) ? (string)$_POST['ps_start_date'] : '';
                $ed = isset($_POST['ps_end_date']) ? (string)$_POST['ps_end_date'] : '';

                if ($sd && $ed) {
                    $startLocal = new \DateTimeImmutable($sd . ' 00:00:00', $tz);
                    $endLocal   = new \DateTimeImmutable($ed . ' 23:59:59', $tz);

                    if ($endLocal <= $startLocal) {
                        $endLocal = $startLocal->modify('+23 hours 59 minutes 59 seconds');
                    }

                    $startUtc = $startLocal->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
                    $endUtc   = $endLocal->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
                }
            } else {
                $s = isset($_POST['ps_start_dt']) ? (string)$_POST['ps_start_dt'] : '';
                $e = isset($_POST['ps_end_dt']) ? (string)$_POST['ps_end_dt'] : '';

                if ($s && $e) {
                    $startLocal = new \DateTimeImmutable($s, $tz);
                    $endLocal   = new \DateTimeImmutable($e, $tz);

                    if ($endLocal <= $startLocal) {
                        $endLocal = $startLocal->modify('+1 hour');
                    }

                    $startUtc = $startLocal->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
                    $endUtc   = $endLocal->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
                }
            }
        } catch (\Throwable $ex) {
            // keep empty
        }

        update_post_meta($postId, self::META_START_UTC, $startUtc);
        update_post_meta($postId, self::META_END_UTC, $endUtc);

        // -------- Priority ----------
        $priority = isset($_POST['ps_priority']) ? (int)$_POST['ps_priority'] : 10;
        if ($priority < 0) $priority = 0;
        update_post_meta($postId, self::META_PRIORITY, $priority);

        // -------- Display flags ----------
        update_post_meta($postId, self::META_ADVISORY, !empty($_POST['ps_advisory_only']) ? 1 : 0);
        update_post_meta($postId, self::META_SHOW_HOME, !empty($_POST['ps_show_on_home']) ? 1 : 0);

        // -------- Effects ----------
        $targets = array_keys(self::targets());
        $statuses = array_keys(self::activeStatuses());

        $effectsIn = $_POST['ps_effects'] ?? [];
        $effectsOut = [];

        $isValidTime = static function(string $t): bool {
            return (bool) preg_match('/^\d{2}:\d{2}$/', $t);
        };

        if (is_array($effectsIn)) {
            $seenTargets = [];
            foreach ($effectsIn as $row) {
                if (!is_array($row)) continue;

                $t = isset($row['target']) ? (string)$row['target'] : '';
                $st = isset($row['status']) ? (string)$row['status'] : '';
                $reason = isset($row['reason']) ? sanitize_text_field((string)$row['reason']) : '';

                if (!in_array($t, $targets, true)) continue;
                if (in_array($t, $seenTargets, true)) continue;
                if (!in_array($st, $statuses, true)) continue;

                $timeMode = isset($row['time_mode']) ? (string)$row['time_mode'] : 'inherit';
                if (!in_array($timeMode, ['inherit', 'custom'], true)) $timeMode = 'inherit';

                $startTime = isset($row['start_time']) ? (string)$row['start_time'] : '';
                $endTime   = isset($row['end_time']) ? (string)$row['end_time'] : '';

                if ($timeMode === 'custom') {
                    if (!$isValidTime($startTime) || !$isValidTime($endTime)) {
                        // invalid custom -> fallback to inherit (do not drop the row)
                        $timeMode = 'inherit';
                        $startTime = '';
                        $endTime = '';
                    }
                } else {
                    $startTime = '';
                    $endTime = '';
                }

                $seenTargets[] = $t;
                $effectsOut[] = [
                    'target' => $t,
                    'status' => $st,
                    'reason' => $reason,
                    'time_mode' => $timeMode,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ];
            }
        }

        update_post_meta($postId, self::META_EFFECTS, $effectsOut);

        // -------- Recurrence ----------
        $recEnabled = !empty($_POST['ps_recurrence_enabled']) ? 1 : 0;
        update_post_meta($postId, self::META_REC_ENABLED, $recEnabled);

        $ruleOut = [];
        if ($recEnabled) {
            $rec = $_POST['ps_recurrence'] ?? [];
            if (is_array($rec)) {
                $freq = isset($rec['freq']) ? (string)$rec['freq'] : 'weekly';
                if (!in_array($freq, ['weekly', 'monthly'], true)) $freq = 'weekly';

                $interval = isset($rec['interval']) ? (int)$rec['interval'] : 1;
                if ($interval < 1) $interval = 1;

                $until = isset($rec['until']) ? (string)$rec['until'] : '';
                if ($until && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
                    $until = '';
                }

                $ruleOut = [
                    'freq' => $freq,
                    'interval' => $interval,
                    'until' => $until,
                ];

                $daysAllowed = ['MO','TU','WE','TH','FR','SA','SU'];

                if ($freq === 'weekly') {
                    $byday = isset($rec['byday']) && is_array($rec['byday']) ? array_values($rec['byday']) : ['MO'];
                    $byday = array_values(array_unique(array_filter($byday, fn($d) => in_array($d, $daysAllowed, true))));
                    if (empty($byday)) $byday = ['MO'];
                    $ruleOut['byday'] = $byday;
                } else { // monthly
                    $pos = isset($rec['month_pos']) ? (string)$rec['month_pos'] : '1';
                    if (!in_array($pos, ['1','2','3','4','-1'], true)) $pos = '1';

                    $md = isset($rec['month_day']) ? (string)$rec['month_day'] : 'MO';
                    if (!in_array($md, $daysAllowed, true)) $md = 'MO';

                    $ruleOut['month_pos'] = $pos;
                    $ruleOut['month_day'] = $md;
                }
            }
        }

        update_post_meta($postId, self::META_REC_RULE, $ruleOut);

        self::bumpCacheVersion();
    }

    private static function bumpCacheVersion(): void
    {
        $v = (int) get_option('platzstatus_cache_version', 1);
        update_option('platzstatus_cache_version', $v + 1, false);
    }
}

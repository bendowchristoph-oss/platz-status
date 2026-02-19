<?php
declare(strict_types=1);

namespace PlatzStatus\Admin;

use PlatzStatus\Capabilities;

final class SettingsPage
{
    private const OPTION_STATUS     = 'platzstatus_status_catalog';
    private const OPTION_DEFAULTS   = 'platzstatus_defaults';
    private const OPTION_ROLES      = 'platzstatus_allowed_roles';
    private const OPTION_IMPACT_PT  = 'platzstatus_impact_post_type';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function addMenu(): void
    {
        add_options_page(
            'Platzstatus',
            'Platzstatus',
            Capabilities::CAP,
            'platzstatus',
            [self::class, 'render']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('platzstatus', self::OPTION_IMPACT_PT, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitizeImpactPostType'],
            'default' => 'ereignis',
        ]);

        register_setting('platzstatus', self::OPTION_STATUS, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeStatusCatalog'],
            'default' => [],
        ]);

        register_setting('platzstatus', self::OPTION_DEFAULTS, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeDefaults'],
            'default' => [],
        ]);

        register_setting('platzstatus', self::OPTION_ROLES, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeRoles'],
            'default' => [],
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            wp_die('Keine Berechtigung.');
        }

        $targets = self::targets();

        $impact_pt = (string) get_option(self::OPTION_IMPACT_PT, 'ereignis');
        $pts = get_post_types(['show_ui' => true], 'objects');

        $statuses = (array) get_option(self::OPTION_STATUS, self::defaultStatuses());
        if (empty($statuses)) {
            $statuses = self::defaultStatuses();
        }

        $defaults = (array) get_option(self::OPTION_DEFAULTS, self::defaultDefaults());
        $defaults = array_merge(self::defaultDefaults(), $defaults);

        $roles = wp_roles()->roles;
        $allowed_roles = (array) get_option(self::OPTION_ROLES, []);

        ?>
        <div class="wrap">
            <h1>Platzstatus</h1>

            <form method="post" action="options.php">
                <?php settings_fields('platzstatus'); ?>

                <h2>Datenquelle</h2>
                <p class="description">
                    Wähle den Post-Type, in dem du deine Ereignisse pflegst. Standard ist <code>ereignis</code>.
                </p>
                <p>
                    <label><strong>Post-Type für Ereignisse</strong></label><br>
                    <select name="<?php echo esc_attr(self::OPTION_IMPACT_PT); ?>">
                        <?php foreach ($pts as $pt): ?>
                            <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($impact_pt, $pt->name); ?>>
                                <?php echo esc_html(($pt->labels->menu_name ?? $pt->label ?? $pt->name) . " ({$pt->name})"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <hr>

                <h2>Status-Katalog</h2>
                <p class="description">
                    Status sind zentral definiert (Slug/Label/Severity). „Gilt für“ ist optional: leer lassen = gilt für alle Bereiche.
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 14%;">Slug</th>
                            <th style="width: 22%;">Label</th>
                            <th style="width: 10%;">Severity</th>
                            <th>Gilt für</th>
                            <th style="width: 8%;">Aktiv</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statuses as $slug => $data): ?>
                            <?php
                            if ($slug === '_new') continue;

                            $label = isset($data['label']) ? (string)$data['label'] : (string)$slug;
                            $severity = isset($data['severity']) ? (int)$data['severity'] : 0;
                            $active = !empty($data['active']) ? 1 : 0;
                            $allowed = (isset($data['allowed_targets']) && is_array($data['allowed_targets'])) ? $data['allowed_targets'] : [];
                            ?>
                            <tr>
                                <td>
                                    <code><?php echo esc_html((string)$slug); ?></code>
                                    <input type="hidden"
                                           name="<?php echo esc_attr(self::OPTION_STATUS); ?>[<?php echo esc_attr((string)$slug); ?>][slug]"
                                           value="<?php echo esc_attr((string)$slug); ?>">
                                </td>
                                <td>
                                    <input type="text"
                                           class="regular-text"
                                           name="<?php echo esc_attr(self::OPTION_STATUS); ?>[<?php echo esc_attr((string)$slug); ?>][label]"
                                           value="<?php echo esc_attr($label); ?>">
                                </td>
                                <td>
                                    <input type="number"
                                           name="<?php echo esc_attr(self::OPTION_STATUS); ?>[<?php echo esc_attr((string)$slug); ?>][severity]"
                                           value="<?php echo esc_attr((string)$severity); ?>"
                                           min="0" step="1">
                                </td>
                                <td>
                                    <?php foreach ($targets as $tKey => $tLabel): ?>
                                        <label style="margin-right:12px; display:inline-block;">
                                            <input type="checkbox"
                                                   name="<?php echo esc_attr(self::OPTION_STATUS); ?>[<?php echo esc_attr((string)$slug); ?>][allowed_targets][]"
                                                   value="<?php echo esc_attr($tKey); ?>"
                                                <?php checked(in_array($tKey, $allowed, true)); ?>>
                                            <?php echo esc_html($tLabel['short']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <div class="description">leer lassen = gilt für alle</div>
                                </td>
                                <td>
                                    <input type="checkbox"
                                           name="<?php echo esc_attr(self::OPTION_STATUS); ?>[<?php echo esc_attr((string)$slug); ?>][active]"
                                           value="1" <?php checked($active, 1); ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- New status row -->
                        <tr>
                            <td>
                                <input type="text"
                                       name="<?php echo esc_attr(self::OPTION_STATUS); ?>[_new][slug]"
                                       placeholder="z. B. open_range_mats"
                                       class="regular-text">
                                <div class="description">Neuer Status (Slug)</div>
                            </td>
                            <td>
                                <input type="text"
                                       name="<?php echo esc_attr(self::OPTION_STATUS); ?>[_new][label]"
                                       placeholder="Label, z. B. Range geöffnet (Matten)"
                                       class="regular-text">
                                <div class="description">Neuer Status (Label)</div>
                            </td>
                            <td>
                                <input type="number"
                                       name="<?php echo esc_attr(self::OPTION_STATUS); ?>[_new][severity]"
                                       value="10" min="0" step="1">
                                <div class="description">Severity</div>
                            </td>
                            <td>
                                <?php foreach ($targets as $tKey => $tLabel): ?>
                                    <label style="margin-right:12px; display:inline-block;">
                                        <input type="checkbox"
                                               name="<?php echo esc_attr(self::OPTION_STATUS); ?>[_new][allowed_targets][]"
                                               value="<?php echo esc_attr($tKey); ?>">
                                        <?php echo esc_html($tLabel['short']); ?>
                                    </label>
                                <?php endforeach; ?>
                                <div class="description">optional</div>
                            </td>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="<?php echo esc_attr(self::OPTION_STATUS); ?>[_new][active]"
                                           value="1" checked>
                                    aktiv
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <hr>

                <h2>Standard-Status je Bereich</h2>
                <p class="description">
                    Wenn kein Ereignis greift, wird dieser Status angezeigt.
                    (Für E-Carts ist der Standard sinnvollerweise „E-Carts erlaubt“.)
                </p>

                <?php
                $activeStatusesForSelect = [];
                foreach ($statuses as $slug => $data) {
                    if ($slug === '_new') continue;
                    if (empty($data['active'])) continue;
                    $activeStatusesForSelect[$slug] = (string)($data['label'] ?? $slug);
                }
                ?>

                <?php foreach ($targets as $key => $meta): ?>
                    <p>
                        <label><strong><?php echo esc_html($meta['label']); ?></strong></label><br>
                        <select name="<?php echo esc_attr(self::OPTION_DEFAULTS); ?>[<?php echo esc_attr($key); ?>]">
                            <?php foreach ($activeStatusesForSelect as $slug => $label): ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($defaults[$key] ?? '', $slug); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                <?php endforeach; ?>

                <hr>

                <h2>Berechtigungen</h2>
                <p class="description">
                    Administratoren dürfen immer Platzstatus verwalten. Zusätzlich kannst du Rollen freischalten.
                </p>

                <?php foreach ($roles as $role_key => $role): ?>
                    <?php if ($role_key === 'administrator') continue; ?>
                    <p>
                        <label>
                            <input type="checkbox"
                                   name="<?php echo esc_attr(self::OPTION_ROLES); ?>[]"
                                   value="<?php echo esc_attr($role_key); ?>"
                                <?php checked(in_array($role_key, $allowed_roles, true)); ?>>
                            <?php echo esc_html($role['name']); ?>
                        </label>
                    </p>
                <?php endforeach; ?>

                <?php submit_button('Einstellungen speichern'); ?>
            </form>
        </div>
        <?php
    }

    // -------------------------
    // Sanitizers
    // -------------------------

    public static function sanitizeImpactPostType($value): string
    {
        $value = is_string($value) ? $value : '';
        $value = sanitize_key($value);
        if ($value === '') return 'ereignis';

        $pts = get_post_types(['show_ui' => true], 'names');
        if (!in_array($value, $pts, true)) {
            return 'ereignis';
        }
        return $value;
    }

    public static function sanitizeStatusCatalog($value): array
    {
        $targets = array_keys(self::targets());
        $out = [];

        if (!is_array($value)) {
            $value = [];
        }

        // Handle existing statuses
        foreach ($value as $slug => $row) {
            if ($slug === '_new') continue;
            if (!is_array($row)) continue;

            $s = isset($row['slug']) ? sanitize_key((string)$row['slug']) : sanitize_key((string)$slug);
            if ($s === '') continue;

            $label = isset($row['label']) ? sanitize_text_field((string)$row['label']) : $s;
            $severity = isset($row['severity']) ? (int)$row['severity'] : 0;
            if ($severity < 0) $severity = 0;

            $active = !empty($row['active']) ? 1 : 0;

            $allowed = [];
            if (isset($row['allowed_targets']) && is_array($row['allowed_targets'])) {
                foreach ($row['allowed_targets'] as $t) {
                    $t = is_string($t) ? $t : '';
                    if ($t !== '' && in_array($t, $targets, true)) {
                        $allowed[] = $t;
                    }
                }
                $allowed = array_values(array_unique($allowed));
            }

            $out[$s] = [
                'label' => $label,
                'severity' => $severity,
                'active' => $active,
                'allowed_targets' => $allowed, // empty = all
            ];
        }

        // Handle "new" status row
        $new = $value['_new'] ?? null;
        if (is_array($new)) {
            $newSlug = isset($new['slug']) ? sanitize_key((string)$new['slug']) : '';
            $newLabel = isset($new['label']) ? sanitize_text_field((string)$new['label']) : '';
            $newSeverity = isset($new['severity']) ? (int)$new['severity'] : 0;
            if ($newSeverity < 0) $newSeverity = 0;

            if ($newSlug !== '' && $newLabel !== '' && !isset($out[$newSlug])) {
                $allowed = [];
                if (isset($new['allowed_targets']) && is_array($new['allowed_targets'])) {
                    foreach ($new['allowed_targets'] as $t) {
                        $t = is_string($t) ? $t : '';
                        if ($t !== '' && in_array($t, $targets, true)) {
                            $allowed[] = $t;
                        }
                    }
                    $allowed = array_values(array_unique($allowed));
                }

                $out[$newSlug] = [
                    'label' => $newLabel,
                    'severity' => $newSeverity,
                    'active' => !empty($new['active']) ? 1 : 0,
                    'allowed_targets' => $allowed,
                ];
            }
        }

        if (empty($out)) {
            $out = self::defaultStatuses();
        }

        return $out;
    }

    public static function sanitizeDefaults($value): array
    {
        $out = self::defaultDefaults();
        $targets = array_keys(self::targets());

        $statuses = (array) get_option(self::OPTION_STATUS, self::defaultStatuses());

        // Build map: slug => [active, allowed_targets]
        $meta = [];
        foreach ($statuses as $slug => $row) {
            if ($slug === '_new') continue;
            $meta[(string)$slug] = [
                'active' => !empty($row['active']),
                'allowed_targets' => (isset($row['allowed_targets']) && is_array($row['allowed_targets']))
                    ? array_values($row['allowed_targets'])
                    : [],
            ];
        }

        if (!is_array($value)) return $out;

        foreach ($targets as $t) {
            $wanted = isset($value[$t]) ? sanitize_key((string)$value[$t]) : $out[$t];

            // must exist + be active
            if (!isset($meta[$wanted]) || !$meta[$wanted]['active']) {
                $out[$t] = $out[$t];
                continue;
            }

            // if allowed_targets is set (non-empty), it must include this target
            $allowedTargets = $meta[$wanted]['allowed_targets'];
            if (!empty($allowedTargets) && !in_array($t, $allowedTargets, true)) {
                $out[$t] = $out[$t];
                continue;
            }

            $out[$t] = $wanted;
        }

        return $out;
    }

    public static function sanitizeRoles($value): array
    {
        $roles = wp_roles()->roles;
        $allowed = [];

        if (is_array($value)) {
            foreach ($value as $role_key) {
                $role_key = is_string($role_key) ? $role_key : '';
                $role_key = sanitize_key($role_key);
                if ($role_key === '' || $role_key === 'administrator') continue;
                if (isset($roles[$role_key])) {
                    $allowed[] = $role_key;
                }
            }
        }

        $allowed = array_values(array_unique($allowed));

        self::syncRoleCapabilities($allowed);

        return $allowed;
    }

    private static function syncRoleCapabilities(array $allowedRoles): void
    {
        $roles = wp_roles()->roles;

        foreach ($roles as $role_key => $_role) {
            $role_obj = get_role($role_key);
            if (!$role_obj) continue;

            if ($role_key === 'administrator' || in_array($role_key, $allowedRoles, true)) {
                $role_obj->add_cap(Capabilities::CAP);
            } else {
                $role_obj->remove_cap(Capabilities::CAP);
            }
        }

        if ($admin = get_role('administrator')) {
            $admin->add_cap(Capabilities::CAP);
        }
    }

    // -------------------------
    // Helpers / Defaults
    // -------------------------

    private static function targets(): array
    {
        return [
            'holes_1_9' => ['label' => 'Bahnen 1–9', 'short' => '1–9'],
            'holes_10_18' => ['label' => 'Bahnen 10–18', 'short' => '10–18'],
            'drivingrange' => ['label' => 'Drivingrange', 'short' => 'Range'],
            'ecarts' => ['label' => 'E-Carts', 'short' => 'E-Carts'],
        ];
    }

    /**
     * Default-Status je Bereich, wenn kein Ereignis greift.
     * Wichtig: E-Carts default = ecarts_allowed (nicht "open").
     */
    private static function defaultDefaults(): array
    {
        return [
            'holes_1_9'    => 'open',
            'holes_10_18'  => 'open',
            'drivingrange' => 'open',
            'ecarts'       => 'ecarts_allowed',
        ];
    }

    private static function defaultStatuses(): array
    {
        return [
            'open' => [
                'label' => 'geöffnet',
                'severity' => 10,
                'active' => 1,
                'allowed_targets' => [], // all
            ],
            'restricted' => [
                'label' => 'eingeschränkt',
                'severity' => 60,
                'active' => 1,
                'allowed_targets' => [], // all
            ],
            'closed' => [
                'label' => 'gesperrt',
                'severity' => 90,
                'active' => 1,
                'allowed_targets' => [], // all
            ],
            'ecarts_allowed' => [
                'label' => 'E-Carts erlaubt',
                'severity' => 10,
                'active' => 1,
                'allowed_targets' => ['ecarts'],
            ],
            'ecarts_forbidden' => [
                'label' => 'E-Carts verboten',
                'severity' => 90,
                'active' => 1,
                'allowed_targets' => ['ecarts'],
            ],
        ];
    }
}

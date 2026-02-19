<?php
/**
 * Plugin Name: Platz Status
 * Description: Golfplatz-Status (1-9, 10-18, Drivingrange, E-Carts) mit Vorschau.
 * Version: 1.0.0
 * Author: Your Name
 */

declare(strict_types=1);

if (!defined('PLATZ_STATUS_BUILD')) {
    define('PLATZ_STATUS_BUILD', '2026-02-17.08');
}

if (!defined('ABSPATH')) {
    exit;
}

define('PLATZ_STATUS_VERSION', '1.0.0');
define('PLATZ_STATUS_PATH', plugin_dir_path(__FILE__));
define('PLATZ_STATUS_URL', plugin_dir_url(__FILE__));

/**
 * Defensive include helper
 */
$ps_require = static function (string $relPath): void {
    $file = PLATZ_STATUS_PATH . ltrim($relPath, '/');
    if (is_readable($file)) {
        require_once $file;
    }
};

// Core
$ps_require('includes/Plugin.php');
$ps_require('includes/Capabilities.php');

// Install / DB schema (muss geladen sein, bevor Activation-Hook Schema::install() aufruft!)
$ps_require('includes/Install/Schema.php');

register_activation_hook(__FILE__, function () {
    // Capabilities
    if (class_exists(\PlatzStatus\Capabilities::class)) {
        \PlatzStatus\Capabilities::activate();
    }

    // DB Tables
    if (class_exists(\PlatzStatus\Install\Schema::class)) {
        \PlatzStatus\Install\Schema::install();
    }
});

register_deactivation_hook(__FILE__, function () {
    if (class_exists(\PlatzStatus\Capabilities::class)) {
        \PlatzStatus\Capabilities::deactivate();
    }
});

add_action('plugins_loaded', function () {
    /**
     * Self-heal 1: Capabilities
     * -> verhindert "Du bist nicht berechtigt", wenn Activation-Hook nicht gelaufen ist
     */
    if (class_exists(\PlatzStatus\Capabilities::class)) {
        \PlatzStatus\Capabilities::ensure();
    }

    /**
     * Boot plugin (register admin pages, meta boxes, shortcodes etc.)
     */
    if (class_exists(\PlatzStatus\Plugin::class)) {
        \PlatzStatus\Plugin::boot();
    }

    /**
     * Self-heal 2: DB Schema
     * -> verhindert "Table ... doesn't exist" (z.B. wp_gc_rounds)
     */
    if (class_exists(\PlatzStatus\Install\Schema::class)) {
        global $wpdb;
        $table = $wpdb->prefix . 'gc_rounds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if ($exists !== $table) {
            \PlatzStatus\Install\Schema::install();
        }
    }
});

<?php
declare(strict_types=1);

namespace PlatzStatus\Install;

final class Schema
{
    public const VERSION_OPTION = 'platzstatus_results_schema_version';
    public const VERSION = 1;

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $sql = [];

        $sql[] = "CREATE TABLE {$p}gc_rounds (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          tournament_post_id BIGINT UNSIGNED NOT NULL,
          player_type VARCHAR(10) NOT NULL, /* member|guest */
          member_user_id BIGINT UNSIGNED NULL,
          guest_name VARCHAR(190) NULL,
          guest_club VARCHAR(190) NULL,
          gender VARCHAR(2) NULL, /* m|w|d */
          tee_set_id BIGINT UNSIGNED NULL,
          hcp_index DECIMAL(4,1) NULL,
          playing_hcp SMALLINT NULL,
          status VARCHAR(5) NOT NULL DEFAULT 'OK', /* OK|DNS|DQ|NR */
          stableford_total SMALLINT NULL,
          strokes_gross_total SMALLINT NULL,
          strokes_net_total SMALLINT NULL,
          out_total SMALLINT NULL,
          in_total SMALLINT NULL,
          has_hole_data TINYINT(1) NOT NULL DEFAULT 0,
          is_final TINYINT(1) NOT NULL DEFAULT 0,
          data_source VARCHAR(10) NOT NULL DEFAULT 'ui', /* ui|csv|albatros|mixed */
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY tournament_post_id (tournament_post_id),
          KEY member_user_id (member_user_id),
          KEY tee_set_id (tee_set_id),
          KEY status (status)
        ) {$charsetCollate};";

        $sql[] = "CREATE TABLE {$p}gc_hole_scores (
          round_id BIGINT UNSIGNED NOT NULL,
          hole_no TINYINT UNSIGNED NOT NULL,
          strokes TINYINT UNSIGNED NULL,
          putts TINYINT UNSIGNED NULL,
          penalties TINYINT UNSIGNED NULL,
          stableford_points TINYINT UNSIGNED NULL,
          PRIMARY KEY (round_id, hole_no),
          KEY hole_no (hole_no)
        ) {$charsetCollate};";

        $sql[] = "CREATE TABLE {$p}gc_tee_sets (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          name VARCHAR(50) NOT NULL, /* Gelb, Rot, ... */
          gender VARCHAR(2) NULL,
          slope SMALLINT NULL,
          cr DECIMAL(4,1) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY name_gender (name, gender)
        ) {$charsetCollate};";

        $sql[] = "CREATE TABLE {$p}gc_tee_holes (
          tee_set_id BIGINT UNSIGNED NOT NULL,
          hole_no TINYINT UNSIGNED NOT NULL,
          par TINYINT UNSIGNED NOT NULL,
          stroke_index TINYINT UNSIGNED NOT NULL,
          length_m SMALLINT NULL,
          PRIMARY KEY (tee_set_id, hole_no),
          KEY hole_no (hole_no)
        ) {$charsetCollate};";

        $sql[] = "CREATE TABLE {$p}gc_divisions (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          tournament_post_id BIGINT UNSIGNED NOT NULL,
          name VARCHAR(190) NOT NULL,
          scoring_mode VARCHAR(12) NOT NULL, /* gross|net|stableford */
          rule_json LONGTEXT NULL,
          sort_order SMALLINT NOT NULL DEFAULT 10,
          PRIMARY KEY (id),
          KEY tournament_post_id (tournament_post_id)
        ) {$charsetCollate};";

        $sql[] = "CREATE TABLE {$p}gc_prizes (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          tournament_post_id BIGINT UNSIGNED NOT NULL,
          type VARCHAR(30) NOT NULL, /* NTP|LD|LD_LADIES|... */
          scope VARCHAR(10) NOT NULL, /* hole|tournament */
          hole_no TINYINT UNSIGNED NULL,
          unit VARCHAR(10) NOT NULL DEFAULT 'text', /* cm|m|count|text */
          label VARCHAR(190) NOT NULL,
          restrictions_json LONGTEXT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          sort_order SMALLINT NOT NULL DEFAULT 10,
          PRIMARY KEY (id),
          KEY tournament_post_id (tournament_post_id),
          KEY type (type)
        ) {$charsetCollate};";

        $sql[] = "CREATE TABLE {$p}gc_prize_results (
          prize_id BIGINT UNSIGNED NOT NULL,
          winner_round_id BIGINT UNSIGNED NOT NULL,
          value VARCHAR(50) NULL,
          note VARCHAR(190) NULL,
          recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (prize_id),
          KEY winner_round_id (winner_round_id)
        ) {$charsetCollate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }
}

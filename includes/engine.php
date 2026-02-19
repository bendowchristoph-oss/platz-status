<?php
if (!defined('ABSPATH')) exit;

class Platz_Status_Engine {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function recalc_place($place_id) {

        $place_id = (int)$place_id;
        if ($place_id <= 0) return;

        $ts = current_time('timestamp');

        $override_active = get_field('override_aktiv', $place_id);

        if ($override_active) {
            update_field('final_status', get_field('override_status', $place_id), $place_id);
            update_field('final_grund', get_field('override_grund', $place_id), $place_id);
            return;
        }

        $active = $this->get_active_impacts($place_id, $ts);

        if (!empty($active)) {
            usort($active, function($a, $b){
                return $b['priority'] <=> $a['priority'];
            });

            $top = $active[0];

            update_field('auto_status', $top['status'], $place_id);
            update_field('auto_grund', $top['grund'], $place_id);
            update_field('final_status', $top['status'], $place_id);
            update_field('final_grund', $top['grund'], $place_id);
            return;
        }

        $default_status = get_field('default_status', $place_id);
        $default_grund  = get_field('default_grund', $place_id);

        update_field('auto_status', '', $place_id);
        update_field('auto_grund', '', $place_id);
        update_field('final_status', $default_status, $place_id);
        update_field('final_grund', $default_grund, $place_id);
    }

    private function get_active_impacts($place_id, $ts) {

        $impacts = get_posts(array(
            'post_type' => 'ereignis_impact',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ));

        if (!$impacts) return array();

        $out = array();

        foreach ($impacts as $impact) {

            $place = get_field('place', $impact->ID);
            if (!is_object($place) || (int)$place->ID !== (int)$place_id) continue;

            $start = get_field('start', $impact->ID);
            $ende  = get_field('ende', $impact->ID);

            if (!$start || !$ende) continue;

            $start_ts = strtotime($start);
            $end_ts   = strtotime($ende);

            if (!($start_ts <= $ts && $ts < $end_ts)) continue;

            $affects = get_field('affects_current', $impact->ID);
            if (!$affects) continue;

            $out[] = array(
                'status' => get_field('status', $impact->ID),
                'grund' => get_field('grund', $impact->ID),
                'priority' => (int)get_field('priority', $impact->ID),
            );
        }

        return $out;
    }
}

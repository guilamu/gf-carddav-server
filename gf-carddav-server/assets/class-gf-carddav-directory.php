<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_Directory {
    /**
     * @var GF_CardDAV_Settings
     */
    private $settings;

    /**
     * @var GF_CardDAV_Logger
     */
    private $logger;

    public function __construct($settings, $logger) {
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    public function gravity_forms_available() {
        return class_exists('GFAPI');
    }

    public function get_form_id() {
        $settings = $this->settings->get_settings();

        return isset($settings['form_id']) ? (int) $settings['form_id'] : 0;
    }

    public function get_active_entries() {
        if (! $this->gravity_forms_available()) {
            return array();
        }

        $gfapi = 'GFAPI';

        $form_id = $this->get_form_id();

        if ($form_id <= 0) {
            return array();
        }

        $search_criteria = array(
            'status' => 'active',
        );
        $sorting         = array(
            'key'       => 'id',
            'direction' => 'ASC',
            'is_numeric'=> true,
        );
        $paging          = array(
            'offset'    => 0,
            'page_size' => apply_filters('gf_carddav_max_entries', 25000),
        );
        $total_count     = 0;
        $entries         = $gfapi::get_entries($form_id, $search_criteria, $sorting, $paging, $total_count);

        if (is_wp_error($entries)) {
            $this->logger->log('Failed to fetch entries', array('form_id' => $form_id, 'error' => $entries->get_error_message()));

            return array();
        }

        return is_array($entries) ? array_values($entries) : array();
    }

    public function get_entry($entry_id) {
        if (! $this->gravity_forms_available()) {
            return null;
        }

        $gfapi = 'GFAPI';

        $entry = $gfapi::get_entry((int) $entry_id);

        if (is_wp_error($entry)) {
            return null;
        }

        if (! isset($entry['status']) || $entry['status'] !== 'active') {
            return null;
        }

        if ((int) $this->get_value($entry, 'form_id') !== $this->get_form_id()) {
            return null;
        }

        return $entry;
    }

    /**
     * Fetch multiple entries by their IDs in a single GFAPI call.
     *
     * Used by addressbook-multiget to avoid N+1 queries.
     *
     * @param int[] $entry_ids
     * @return array
     */
    public function get_entries_by_ids(array $entry_ids) {
        if (! $this->gravity_forms_available() || empty($entry_ids)) {
            return array();
        }

        $form_id = $this->get_form_id();

        if ($form_id <= 0) {
            return array();
        }

        $gfapi = 'GFAPI';

        $search_criteria = array(
            'status'        => 'active',
            'field_filters' => array(
                array(
                    'key'      => 'id',
                    'operator' => 'in',
                    'value'    => array_map('intval', $entry_ids),
                ),
            ),
        );

        $entries = $gfapi::get_entries($form_id, $search_criteria);

        if (is_wp_error($entries)) {
            $this->logger->log('Failed to fetch entries by IDs', array(
                'form_id'   => $form_id,
                'entry_ids' => $entry_ids,
                'error'     => $entries->get_error_message(),
            ));

            return array();
        }

        return is_array($entries) ? array_values($entries) : array();
    }

    /**
     * Compute the collection CTag without loading all entries.
     *
     * Fetches only the most recently updated entry (page_size=1, sorted by
     * date_updated DESC) and uses $total_count for the active entry count.
     */
    public function get_collection_ctag() {
        if (! $this->gravity_forms_available()) {
            return md5('empty');
        }

        $form_id = $this->get_form_id();

        if ($form_id <= 0) {
            return md5('no-form');
        }

        $gfapi = 'GFAPI';

        $search_criteria = array(
            'status' => 'active',
        );
        $sorting = array(
            'key'       => 'date_updated',
            'direction' => 'DESC',
        );
        $paging = array(
            'offset'    => 0,
            'page_size' => 1,
        );
        $total_count = 0;

        $entries = $gfapi::get_entries($form_id, $search_criteria, $sorting, $paging, $total_count);

        $latest_modified = '';

        if (! is_wp_error($entries) && ! empty($entries[0])) {
            $latest_modified = isset($entries[0]['date_updated']) ? (string) $entries[0]['date_updated'] : '';
        }

        $mapping_version = $this->settings->get_mapping_version();

        return md5($latest_modified . '|' . $total_count . '|' . $mapping_version);
    }

    private function get_value(array $values, $key) {
        return array_key_exists($key, $values) ? $values[$key] : '';
    }
}

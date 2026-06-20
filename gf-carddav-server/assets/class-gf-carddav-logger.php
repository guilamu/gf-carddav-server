<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_Logger {
    /**
     * @var GF_CardDAV_Settings
     */
    private $settings;

    public function __construct($settings) {
        $this->settings = $settings;
    }

    public function log($message, array $context = array()) {
        $config = $this->settings->get_settings();

        if (empty($config['debug_enabled'])) {
            return;
        }

        $this->write_log('GF CardDAV Server', $message, $context);
    }

    private function write_log($channel, $message, array $context = array()) {
        $line = '[' . $channel . '] ' . $message;

        if (! empty($context)) {
            $line .= ' ' . wp_json_encode($context);
        }

        error_log($line);
    }
}

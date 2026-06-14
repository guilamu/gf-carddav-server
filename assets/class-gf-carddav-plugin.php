<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_Plugin {
    /**
     * @var GF_CardDAV_Plugin|null
     */
    private static $instance = null;

    /**
     * @var GF_CardDAV_Settings
     */
    private $settings;

    /**
     * @var GF_CardDAV_Server
     */
    private $server;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function register_rewrite_rules() {
        add_rewrite_tag('%gf_carddav_route%', '([^&]+)');
        add_rewrite_tag('%gf_carddav_principal%', '([^&]+)');
        add_rewrite_tag('%gf_carddav_entry%', '([0-9]+)');

        add_rewrite_rule('^\.well-known/carddav/?$', 'index.php?gf_carddav_route=well-known', 'top');
        add_rewrite_rule('^carddav/?$', 'index.php?gf_carddav_route=service', 'top');
        add_rewrite_rule('^carddav/principals/([^/]+)/?$', 'index.php?gf_carddav_route=principal&gf_carddav_principal=$matches[1]', 'top');
        add_rewrite_rule('^carddav/contacts/?$', 'index.php?gf_carddav_route=contacts', 'top');
        add_rewrite_rule('^carddav/contacts/gf-([0-9]+)\.vcf$', 'index.php?gf_carddav_route=contact&gf_carddav_entry=$matches[1]', 'top');
    }

    public static function activate() {
        self::register_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    private function __construct() {
        $this->settings  = new GF_CardDAV_Settings();
        $logger          = new GF_CardDAV_Logger($this->settings);
        $auth            = new GF_CardDAV_Auth($this->settings, $logger);
        $directory       = new GF_CardDAV_Directory($this->settings, $logger);
        $vcf             = new GF_CardDAV_VCF($this->settings);
        $principal       = new GF_CardDAV_Principal();
        $this->server    = new GF_CardDAV_Server($this->settings, $auth, $directory, $vcf, $principal, $logger);

        add_action('init', array(__CLASS__, 'register_rewrite_rules'));
        add_action('init', array(__CLASS__, 'maybe_flush_rewrite_rules'), 20);
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_filter('redirect_canonical', array($this, 'disable_canonical_redirect'), 10, 2);
        add_action('template_redirect', array($this->server, 'maybe_handle_request'), 0);

        if (is_admin()) {
            $this->settings->hooks();
        }
    }

    public function register_query_vars($vars) {
        $vars[] = 'gf_carddav_route';
        $vars[] = 'gf_carddav_principal';
        $vars[] = 'gf_carddav_entry';

        return $vars;
    }

    public function get_settings() {
        return $this->settings;
    }

    public static function maybe_flush_rewrite_rules() {
        $stored_version = get_option(GF_CARDDAV_SERVER_REWRITE_VERSION_OPTION, '');

        if ($stored_version === GF_CARDDAV_SERVER_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option(GF_CARDDAV_SERVER_REWRITE_VERSION_OPTION, GF_CARDDAV_SERVER_VERSION, false);
    }

    public function disable_canonical_redirect($redirect_url, $requested_url) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        if (get_query_var('gf_carddav_route') || $this->server->is_carddav_request()) {
            return false;
        }

        return $redirect_url;
    }
}

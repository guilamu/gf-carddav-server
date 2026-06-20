<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_Auth {
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

    public function authenticate() {
        $credentials = $this->get_basic_credentials();

        if (empty($credentials['username']) || ! array_key_exists('password', $credentials)) {
            return array(
                'status' => 'missing',
            );
        }

        $user = wp_authenticate($credentials['username'], $credentials['password']);

        if (is_wp_error($user)) {
            $this->logger->log('Authentication failed', array('username' => $credentials['username']));

            return array(
                'status' => 'invalid',
            );
        }

        $settings         = $this->settings->get_settings();
        $allowed_user_ids = array_map('intval', isset($settings['allowed_user_ids']) ? (array) $settings['allowed_user_ids'] : array());

        if (! in_array((int) $user->ID, $allowed_user_ids, true)) {
            $this->logger->log('Authenticated user not authorized', array('user_id' => (int) $user->ID));

            return array(
                'status' => 'forbidden',
                'user'   => $user,
            );
        }

        wp_set_current_user($user->ID);

        return array(
            'status' => 'authorized',
            'user'   => $user,
        );
    }

    public function send_auth_challenge() {
        status_header(401);
        header(sprintf('WWW-Authenticate: Basic realm="%s"', str_replace('"', '\\"', __('GF CardDAV Server', 'gf-carddav-server'))));
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html__('Authentication required', 'gf-carddav-server');
        exit;
    }

    private function get_basic_credentials() {
        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            return array(
                'username' => wp_unslash($_SERVER['PHP_AUTH_USER']),
                'password' => $_SERVER['PHP_AUTH_PW'],
            );
        }

        $header = '';

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (stripos($header, 'Basic ') !== 0) {
            return array();
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || strpos($decoded, ':') === false) {
            return array();
        }

        list($username, $password) = explode(':', $decoded, 2);

        return array(
            'username' => $username,
            'password' => $password,
        );
    }
}

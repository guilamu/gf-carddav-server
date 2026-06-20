<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_Principal {
    public function get_service_url() {
        return home_url('/carddav/');
    }

    public function get_principal_url($user_login = '') {
        $path = 'carddav/principals/';

        if ($user_login !== '') {
            $path .= rawurlencode($user_login) . '/';
        }

        return home_url('/' . $path);
    }

    public function get_contacts_url() {
        return home_url('/carddav/contacts/');
    }

    public function build_propfind_response($user_login) {
        $principal_url = $this->get_principal_url($user_login);
        $contacts_url  = $this->get_contacts_url();

        return array(
            'href'       => wp_parse_url($principal_url, PHP_URL_PATH),
            'properties' => array(
                'd:displayname'            => esc_html($user_login),
                'd:current-user-principal' => array('d:href' => wp_parse_url($principal_url, PHP_URL_PATH)),
                'card:addressbook-home-set'=> array('d:href' => wp_parse_url($contacts_url, PHP_URL_PATH)),
                'd:resourcetype'           => array('d:principal' => null),
            ),
        );
    }

    public function build_service_discovery_response($user_login) {
        $service_url   = $this->get_service_url();
        $principal_url = $this->get_principal_url($user_login);
        $contacts_url  = $this->get_contacts_url();

        return array(
            'href'       => wp_parse_url($service_url, PHP_URL_PATH),
            'properties' => array(
                'd:displayname'            => __( 'GF CardDAV Server', 'gf-carddav-server' ),
                'd:current-user-principal' => array('d:href' => wp_parse_url($principal_url, PHP_URL_PATH)),
                'card:addressbook-home-set'=> array('d:href' => wp_parse_url($contacts_url, PHP_URL_PATH)),
                'd:resourcetype'           => array('d:collection' => null),
            ),
        );
    }
}

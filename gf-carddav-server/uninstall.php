<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('gf_carddav_server_settings');

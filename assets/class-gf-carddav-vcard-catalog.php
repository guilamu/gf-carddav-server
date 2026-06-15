<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_VCard_Catalog {

    private static $fields = array(
        'name_family' => array(
            'vcard_property' => 'N',
            'vcard_index'    => 0,
            'label'   => 'Last name',
            'group'   => 'identity',
            'normalize' => 'normalize_last_name',
        ),
        'name_given' => array(
            'vcard_property' => 'N',
            'vcard_index'    => 1,
            'label'   => 'First name',
            'group'   => 'identity',
            'normalize' => 'normalize_first_name',
        ),
        'nickname' => array(
            'vcard_property' => 'NICKNAME',
            'vcard_index'    => null,
            'label'   => 'Nickname',
            'group'   => 'identity',
            'normalize' => null,
        ),
        'bday' => array(
            'vcard_property' => 'BDAY',
            'vcard_index'    => null,
            'label'   => 'Birthday',
            'group'   => 'identity',
            'normalize' => null,
        ),
        'anniversary' => array(
            'vcard_property' => 'ANNIVERSARY',
            'vcard_index'    => null,
            'label'   => 'Anniversary',
            'group'   => 'identity',
            'normalize' => null,
        ),
        'gender' => array(
            'vcard_property' => 'GENDER',
            'vcard_index'    => null,
            'label'   => 'Gender',
            'group'   => 'identity',
            'normalize' => null,
        ),
        'photo' => array(
            'vcard_property' => 'PHOTO',
            'vcard_index'    => null,
            'label'   => 'Photo URL',
            'group'   => 'identity',
            'normalize' => null,
        ),
        'email' => array(
            'vcard_property' => 'EMAIL',
            'vcard_index'    => null,
            'label'   => 'Email',
            'group'   => 'contact',
            'normalize' => null,
        ),
        'tel_cell' => array(
            'vcard_property' => 'TEL;TYPE=cell',
            'vcard_index'    => null,
            'label'   => 'Mobile phone',
            'group'   => 'contact',
            'normalize' => 'normalize_phone',
        ),
        'tel_work' => array(
            'vcard_property' => 'TEL;TYPE=work',
            'vcard_index'    => null,
            'label'   => 'Work phone',
            'group'   => 'contact',
            'normalize' => 'normalize_phone',
        ),
        'tel_home' => array(
            'vcard_property' => 'TEL;TYPE=home',
            'vcard_index'    => null,
            'label'   => 'Home phone',
            'group'   => 'contact',
            'normalize' => 'normalize_phone',
        ),
        'tel_fax' => array(
            'vcard_property' => 'TEL;TYPE=fax',
            'vcard_index'    => null,
            'label'   => 'Fax',
            'group'   => 'contact',
            'normalize' => 'normalize_phone',
        ),
        'impp' => array(
            'vcard_property' => 'IMPP',
            'vcard_index'    => null,
            'label'   => 'Instant messaging',
            'group'   => 'contact',
            'normalize' => null,
        ),
        'adr_work_street' => array(
            'vcard_property' => 'ADR;TYPE=work',
            'vcard_index'    => 2,
            'label'   => 'Work street',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_work_city' => array(
            'vcard_property' => 'ADR;TYPE=work',
            'vcard_index'    => 3,
            'label'   => 'Work city',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_work_region' => array(
            'vcard_property' => 'ADR;TYPE=work',
            'vcard_index'    => 4,
            'label'   => 'Work region',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_work_postcode' => array(
            'vcard_property' => 'ADR;TYPE=work',
            'vcard_index'    => 5,
            'label'   => 'Work postal code',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_work_country' => array(
            'vcard_property' => 'ADR;TYPE=work',
            'vcard_index'    => 6,
            'label'   => 'Work country',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_home_street' => array(
            'vcard_property' => 'ADR;TYPE=home',
            'vcard_index'    => 2,
            'label'   => 'Home street',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_home_city' => array(
            'vcard_property' => 'ADR;TYPE=home',
            'vcard_index'    => 3,
            'label'   => 'Home city',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_home_region' => array(
            'vcard_property' => 'ADR;TYPE=home',
            'vcard_index'    => 4,
            'label'   => 'Home region',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_home_postcode' => array(
            'vcard_property' => 'ADR;TYPE=home',
            'vcard_index'    => 5,
            'label'   => 'Home postal code',
            'group'   => 'address',
            'normalize' => null,
        ),
        'adr_home_country' => array(
            'vcard_property' => 'ADR;TYPE=home',
            'vcard_index'    => 6,
            'label'   => 'Home country',
            'group'   => 'address',
            'normalize' => null,
        ),
        'org_name' => array(
            'vcard_property' => 'ORG',
            'vcard_index'    => 0,
            'label'   => 'Organization',
            'group'   => 'organization',
            'normalize' => null,
        ),
        'org_department' => array(
            'vcard_property' => 'ORG',
            'vcard_index'    => 1,
            'label'   => 'Department',
            'group'   => 'organization',
            'normalize' => null,
        ),
        'title' => array(
            'vcard_property' => 'TITLE',
            'vcard_index'    => null,
            'label'   => 'Job title',
            'group'   => 'organization',
            'normalize' => null,
        ),
        'role' => array(
            'vcard_property' => 'ROLE',
            'vcard_index'    => null,
            'label'   => 'Role',
            'group'   => 'organization',
            'normalize' => null,
        ),
        'url' => array(
            'vcard_property' => 'URL',
            'vcard_index'    => null,
            'label'   => 'Website',
            'group'   => 'web',
            'normalize' => null,
        ),
        'note' => array(
            'vcard_property' => 'NOTE',
            'vcard_index'    => null,
            'label'   => 'Note',
            'group'   => 'notes',
            'normalize' => null,
        ),
        'categories' => array(
            'vcard_property' => 'CATEGORIES',
            'vcard_index'    => null,
            'label'   => 'Categories',
            'group'   => 'notes',
            'normalize' => null,
        ),
    );

    public static function get_all() {
        return self::$fields;
    }

    public static function get($key) {
        return isset(self::$fields[$key]) ? self::$fields[$key] : null;
    }

    public static function get_keys() {
        return array_keys(self::$fields);
    }

    public static function exists($key) {
        return isset(self::$fields[$key]);
    }

    public static function get_by_group() {
        $groups = array();

        foreach (self::$fields as $key => $field) {
            $groups[$field['group']][$key] = $field;
        }

        return $groups;
    }

    public static function get_group_labels() {
        return array(
            'identity'     => __('Identity', 'gf-carddav-server'),
            'contact'      => __('Contact', 'gf-carddav-server'),
            'address'      => __('Address', 'gf-carddav-server'),
            'organization' => __('Organization', 'gf-carddav-server'),
            'web'          => __('Web', 'gf-carddav-server'),
            'notes'        => __('Notes', 'gf-carddav-server'),
        );
    }

    public static function get_js_catalog() {
        $catalog = array();

        foreach (self::$fields as $key => $field) {
            $catalog[$key] = array(
                'key'            => $key,
                'vcard_property' => $field['vcard_property'],
                'vcard_index'    => $field['vcard_index'],
                'label'          => $field['label'],
                'group'          => $field['group'],
            );
        }

        return $catalog;
    }

    public static function get_legacy_key_map() {
        return array(
            'first_name' => 'name_given',
            'last_name'  => 'name_family',
            'email'      => 'email',
            'phone'      => 'tel_cell',
            'union'      => 'org_name',
            'department' => 'org_department',
        );
    }

    public static function get_structured_component_count($vcard_property) {
        $counts = array(
            'N'             => 5,
            'ORG'           => 2,
            'ADR;TYPE=work' => 7,
            'ADR;TYPE=home' => 7,
        );

        return isset($counts[$vcard_property]) ? $counts[$vcard_property] : 1;
    }
}

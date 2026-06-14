<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_VCF {
    /**
     * @var GF_CardDAV_Settings
     */
    private $settings;

    public function __construct($settings) {
        $this->settings = $settings;
    }

    public function build_vcard(array $entry) {
        $settings    = $this->settings->get_settings();
        $mapping     = isset($settings['mapping']) ? (array) $settings['mapping'] : array();
        $first_name  = $this->normalize_first_name($this->get_entry_value($entry, isset($mapping['first_name']) ? $mapping['first_name'] : ''));
        $last_name   = $this->normalize_last_name($this->get_entry_value($entry, isset($mapping['last_name']) ? $mapping['last_name'] : ''));
        $email       = $this->get_entry_value($entry, isset($mapping['email']) ? $mapping['email'] : '');
        $phone       = $this->normalize_phone($this->get_entry_value($entry, isset($mapping['phone']) ? $mapping['phone'] : ''));
        $union       = $this->get_entry_value($entry, isset($mapping['union']) ? $mapping['union'] : '');
        $department  = $this->get_entry_value($entry, isset($mapping['department']) ? $mapping['department'] : '');
        $categories  = isset($settings['category']) ? trim((string) $settings['category']) : '';
        $displayname = trim($first_name . ' ' . $last_name);

        if ($displayname === '') {
            $displayname = $email !== '' ? $email : 'gf-' . (int) $entry['id'];
        }

        $lines = array(
            'BEGIN:VCARD',
            'VERSION:4.0',
            'UID:' . $this->escape_text('gf-' . (int) $entry['id']),
            'N:' . $this->escape_text($last_name) . ';' . $this->escape_text($first_name) . ';;;',
            'FN:' . $this->escape_text($displayname),
        );

        if ($email !== '') {
            $lines[] = 'EMAIL:' . $this->escape_text($email);
        }

        if ($phone !== '') {
            $lines[] = 'TEL;TYPE=cell:' . $this->escape_text($phone);
        }

        if ($union !== '' || $department !== '') {
            $lines[] = 'ORG:' . $this->escape_text($union) . ';' . $this->escape_text($department);
        }

        if ($categories !== '') {
            $lines[] = 'CATEGORIES:' . $this->escape_text($categories);
        }

        $timestamp = strtotime(isset($entry['date_updated']) ? $entry['date_updated'] : 'now');
        $lines[] = 'REV:' . gmdate('Y-m-d\TH:i:s\Z', $timestamp !== false ? $timestamp : time());
        $lines[] = 'END:VCARD';

        $folded = array_map(array($this, 'fold_line'), $lines);

        return implode("\r\n", $folded) . "\r\n";
    }

    public function get_entry_value(array $entry, $mapping_key) {
        $mapping_key = (string) $mapping_key;

        if ($mapping_key === '') {
            return '';
        }

        if (isset($entry[$mapping_key])) {
            return trim((string) $entry[$mapping_key]);
        }

        return '';
    }

    public function get_etag(array $entry) {
        return '"' . md5($this->build_vcard($entry)) . '"';
    }

    private function escape_text($value) {
        return str_replace(
            array('\\', ';', ',', "\n", "\r"),
            array('\\\\', '\\;', '\\,', '\\n', ''),
            trim((string) $value)
        );
    }

    private function normalize_last_name($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return mb_strtoupper($value, 'UTF-8');
    }

    private function normalize_first_name($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $parts = preg_split('/([\s\-\']+)/u', mb_strtolower($value, 'UTF-8'), -1, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($parts)) {
            return $value;
        }

        foreach ($parts as $index => $part) {
            if ($part === '' || preg_match('/^[\s\-\']+$/u', $part)) {
                continue;
            }

            $first_char = mb_substr($part, 0, 1, 'UTF-8');
            $rest       = mb_substr($part, 1, null, 'UTF-8');
            $parts[$index] = mb_strtoupper($first_char, 'UTF-8') . $rest;
        }

        return implode('', $parts);
    }

    private function normalize_phone($value) {
        $value  = trim((string) $value);
        $digits = preg_replace('/\D+/', '', $value);

        if ($value === '' || ! is_string($digits) || $digits === '') {
            return '';
        }

        if (preg_match('/^33([67]\d{8})$/', $digits, $matches)) {
            $digits = '0' . $matches[1];
        } elseif (preg_match('/^[67]\d{8}$/', $digits)) {
            $digits = '0' . $digits;
        }

        if (! preg_match('/^0([67]\d{8})$/', $digits, $matches)) {
            return $value;
        }

        $international = '33' . $matches[1];

        return sprintf(
            '+%s %s %s %s %s',
            substr($international, 0, 3),
            substr($international, 3, 2),
            substr($international, 5, 2),
            substr($international, 7, 2),
            substr($international, 9, 2)
        );
    }

    /**
     * Fold a vCard line at 75 octets using mb_strcut to preserve UTF-8 characters.
     *
     * RFC 6350 §3.2: Lines are folded at 75 octets. The continuation line
     * begins with a single space. mb_strcut cuts at byte boundaries without
     * splitting multi-byte characters.
     */
    private function fold_line($line) {
        $line      = (string) $line;
        $max_bytes = 75;

        if (strlen($line) <= $max_bytes) {
            return $line;
        }

        $chunks = array();

        while (strlen($line) > $max_bytes) {
            $chunks[] = mb_strcut($line, 0, $max_bytes, 'UTF-8');
            $line     = ' ' . mb_strcut($line, $max_bytes, null, 'UTF-8');
        }

        $chunks[] = $line;

        return implode("\r\n", $chunks);
    }
}

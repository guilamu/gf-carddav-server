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
        $settings = $this->settings->get_settings();
        $mapping  = isset($settings['mapping']) ? (array) $settings['mapping'] : array();

        $lines = array(
            'BEGIN:VCARD',
            'VERSION:4.0',
            'UID:' . $this->escape_text('gf-' . (int) $entry['id']),
        );

        $structured = array();
        $simple_values = array();
        $family_name = '';
        $given_name  = '';

        foreach ($mapping as $map_entry) {
            if (! is_array($map_entry) || empty($map_entry['vcard'])) {
                continue;
            }

            $catalog_key = $map_entry['vcard'];
            $catalog_def = GF_CardDAV_VCard_Catalog::get($catalog_key);

            if (! $catalog_def) {
                continue;
            }

            // Support both old field_id (string) and new field_ids (array) format.
            $field_ids = array();
            if (isset($map_entry['field_ids']) && is_array($map_entry['field_ids'])) {
                $field_ids = $map_entry['field_ids'];
            } elseif (isset($map_entry['field_id']) && (string) $map_entry['field_id'] !== '') {
                $field_ids = array( (string) $map_entry['field_id'] );
            }

            $separator = isset($map_entry['separator']) ? (string) $map_entry['separator'] : ' ';

            // Apply per-field case transformation from user settings.
            $case_transforms = array();
            if (isset($map_entry['case_transforms']) && is_array($map_entry['case_transforms'])) {
                $case_transforms = $map_entry['case_transforms'];
            }

            $parts = array();
            foreach ($field_ids as $fi => $fid) {
                $v = $this->get_entry_value($entry, (string) $fid);
                $ct = isset($case_transforms[$fi]) ? $case_transforms[$fi] : '';
                if ($v !== '' && $ct !== '') {
                    $v = $this->apply_case_transform($v, $ct);
                }
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            $value = implode($separator, $parts);

            if (! empty($catalog_def['normalize']) && method_exists($this, $catalog_def['normalize'])) {
                $value = $this->{$catalog_def['normalize']}($value);
            }

            if ($catalog_key === 'name_family') {
                $family_name = $value;
            } elseif ($catalog_key === 'name_given') {
                $given_name = $value;
            }

            $vcard_prop = $catalog_def['vcard_property'];

            if ($catalog_def['vcard_index'] !== null) {
                if (! isset($structured[$vcard_prop])) {
                    $structured[$vcard_prop] = array();
                }
                $structured[$vcard_prop][$catalog_def['vcard_index']] = $value;
            } else {
                if ($value !== '') {
                    $simple_values[$vcard_prop] = $value;
                }
            }
        }

        if ($family_name !== '' || $given_name !== '') {
            $lines[] = 'N:' . $this->escape_text($family_name) . ';' . $this->escape_text($given_name) . ';;;';
        }

        foreach ($structured as $prop => $components) {
            if ($prop === 'N') {
                continue;
            }

            $count = GF_CardDAV_VCard_Catalog::get_structured_component_count($prop);
            $parts = array();
            $has_value = false;

            for ($i = 0; $i < $count; $i++) {
                $val = isset($components[$i]) ? $components[$i] : '';
                $parts[] = $this->escape_text($val);
                if ($val !== '') {
                    $has_value = true;
                }
            }

            if ($has_value) {
                $lines[] = $prop . ':' . implode(';', $parts);
            }
        }

        foreach ($simple_values as $prop => $value) {
            if ($value !== '') {
                $lines[] = $prop . ':' . $this->escape_text($value);
            }
        }

        $displayname = trim($given_name . ' ' . $family_name);

        if ($displayname === '') {
            $email_value = isset($simple_values['EMAIL']) ? $simple_values['EMAIL'] : '';
            $displayname = $email_value !== '' ? $email_value : 'gf-' . (int) $entry['id'];
        }

        $fn_index = null;
        foreach ($lines as $i => $line) {
            if (strpos($line, 'N:') === 0) {
                $fn_index = $i + 1;
                break;
            }
        }

        $fn_line = 'FN:' . $this->escape_text($displayname);

        if ($fn_index !== null) {
            array_splice($lines, $fn_index, 0, array($fn_line));
        } else {
            $lines[] = $fn_line;
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

    /**
     * Apply a case transformation to a value.
     *
     * @param string $value The input value.
     * @param string $transform One of: 'upper', 'lower', 'ucfirst', 'ucwords'.
     * @return string
     */
    private function apply_case_transform($value, $transform) {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        switch ($transform) {
            case 'upper':
                return mb_strtoupper($value, 'UTF-8');

            case 'lower':
                return mb_strtolower($value, 'UTF-8');

            case 'ucfirst':
                $first_char = mb_substr($value, 0, 1, 'UTF-8');
                $rest       = mb_substr($value, 1, null, 'UTF-8');
                return mb_strtoupper($first_char, 'UTF-8') . $rest;

            case 'ucwords':
                $parts = preg_split('/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE);

                if (! is_array($parts)) {
                    return $value;
                }

                foreach ($parts as $index => $part) {
                    if ($part === '' || preg_match('/^\s+$/u', $part)) {
                        continue;
                    }

                    $first_char = mb_substr($part, 0, 1, 'UTF-8');
                    $rest       = mb_substr($part, 1, null, 'UTF-8');
                    $parts[$index] = mb_strtoupper($first_char, 'UTF-8') . $rest;
                }

                return implode('', $parts);

            default:
                return $value;
        }
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

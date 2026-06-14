<?php

if (! defined('ABSPATH')) {
    exit;
}

class GF_CardDAV_Settings {
    const OPTION_KEY = 'gf_carddav_server_settings';
    const OPTION_GROUP = 'gf_carddav_server';
    const PAGE_CAPABILITY = 'manage_options';

    public function hooks() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'maybe_persist_access_override'), 20);
        add_action('wp_ajax_gf_carddav_form_fields', array($this, 'ajax_form_fields'));
        add_filter('option_page_capability_' . self::OPTION_GROUP, array($this, 'get_option_page_capability'));
    }

    public function get_defaults() {
        return array(
            'form_id'          => 0,
            'mapping'          => array(
                'first_name' => '',
                'last_name'  => '',
                'email'      => '',
                'phone'      => '',
                'union'      => '',
                'department' => '',
            ),
            'allowed_user_ids' => array(),
            'category'         => '',
            'debug_enabled'    => 0,
            'mapping_version'  => '',
        );
    }

    public function get_settings() {
        $settings = $this->get_addon_settings();

        if (empty($settings)) {
            $settings = get_option(self::OPTION_KEY, array());
        }

        $normalized = $this->normalize_settings(is_array($settings) ? $settings : array());
        $override = get_option(self::OPTION_KEY, array());

        if (! is_array($override) || empty($override)) {
            return $normalized;
        }

        $override = $this->normalize_settings($override);
        $normalized['allowed_user_ids'] = $override['allowed_user_ids'];
        $normalized['debug_enabled'] = $override['debug_enabled'];

        return $normalized;
    }

    public function get_mapping_version() {
        $settings = $this->get_settings();

        return ! empty($settings['mapping_version']) ? (string) $settings['mapping_version'] : md5(wp_json_encode($settings['mapping']));
    }

    public function get_addon_settings() {
        if (! class_exists('GF_CardDAV_AddOn')) {
            return array();
        }

        $addon = GF_CardDAV_AddOn::get_instance();

        if (! $addon || ! method_exists($addon, 'get_plugin_settings')) {
            return array();
        }

        $settings = $addon->get_plugin_settings();

        return is_array($settings) ? $settings : array();
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION_KEY, array($this, 'sanitize_settings'));
    }

    public function maybe_persist_access_override() {
        if (! $this->is_addon_settings_post()) {
            return;
        }

        $current = $this->normalize_settings(get_option(self::OPTION_KEY, array()));

        if (isset($_POST['_gaddon_setting_allowed_user_ids'])) {
            $current['allowed_user_ids'] = array_values(array_unique(array_filter(array_map('absint', (array) wp_unslash($_POST['_gaddon_setting_allowed_user_ids'])))));
        }

        $current['debug_enabled'] = empty($_POST['_gaddon_setting_debug_enabled']) ? 0 : 1;

        update_option(self::OPTION_KEY, $current, false);
    }

    private function is_addon_settings_post() {
        if (! is_admin() || ! current_user_can(self::PAGE_CAPABILITY)) {
            return false;
        }

        if (! isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
            return false;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $subview = isset($_GET['subview']) ? sanitize_key(wp_unslash($_GET['subview'])) : '';

        return ($page === 'gf_settings' && $subview === 'gf-carddav-server') || $page === 'gf-carddav-server';
    }

    public function get_option_page_capability() {
        return self::PAGE_CAPABILITY;
    }

    public function sanitize_settings($input) {
        $defaults = $this->get_defaults();
        $current  = $this->get_settings();
        $input    = is_array($input) ? $input : array();
        $mapping  = isset($input['mapping']) && is_array($input['mapping']) ? $input['mapping'] : array();

        $sanitized = $defaults;
        $sanitized['form_id'] = isset($input['form_id']) ? absint($input['form_id']) : 0;
        $sanitized['mapping'] = array(
            'first_name' => isset($mapping['first_name']) ? sanitize_text_field($mapping['first_name']) : '',
            'last_name'  => isset($mapping['last_name']) ? sanitize_text_field($mapping['last_name']) : '',
            'email'      => isset($mapping['email']) ? sanitize_text_field($mapping['email']) : '',
            'phone'      => isset($mapping['phone']) ? sanitize_text_field($mapping['phone']) : '',
            'union'      => isset($mapping['union']) ? sanitize_text_field($mapping['union']) : '',
            'department' => isset($mapping['department']) ? sanitize_text_field($mapping['department']) : '',
        );
        $sanitized['allowed_user_ids'] = array_values(array_unique(array_filter(array_map('absint', isset($input['allowed_user_ids']) ? (array) $input['allowed_user_ids'] : array()))));
        $sanitized['category']         = isset($input['category']) ? sanitize_text_field($input['category']) : '';
        $sanitized['debug_enabled']    = empty($input['debug_enabled']) ? 0 : 1;

        $mapping_changed = $current['form_id'] !== $sanitized['form_id']
            || wp_json_encode($current['mapping']) !== wp_json_encode($sanitized['mapping'])
            || $current['category'] !== $sanitized['category'];

        $sanitized['mapping_version'] = $mapping_changed ? (string) time() : $this->get_mapping_version();

        return $sanitized;
    }

    public function render_page() {
        if (! current_user_can(self::PAGE_CAPABILITY)) {
            return;
        }

        $settings = $this->get_settings();
        $forms    = $this->get_forms();
        $users    = get_users(array('fields' => array('ID', 'display_name', 'user_login')));
        $options  = $this->get_field_choices((int) $settings['form_id']);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('GF CardDAV Server', 'gf-carddav-server'); ?></h1>
            <?php if (! class_exists('GFAPI')) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Gravity Forms is not active. The plugin stays loadable, but the CardDAV directory is in degraded mode until Gravity Forms is available.', 'gf-carddav-server'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="gf-carddav-form-id"><?php esc_html_e('Source form', 'gf-carddav-server'); ?></label></th>
                        <td>
                            <select id="gf-carddav-form-id" name="<?php echo esc_attr(self::OPTION_KEY); ?>[form_id]">
                                <option value="0"><?php esc_html_e('Select a form', 'gf-carddav-server'); ?></option>
                                <?php foreach ($forms as $form) : ?>
                                    <option value="<?php echo esc_attr($form['id']); ?>" <?php selected((int) $settings['form_id'], (int) $form['id']); ?>><?php echo esc_html($form['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php foreach ($this->get_mapping_labels() as $key => $label) : ?>
                        <tr>
                            <th scope="row"><label for="gf-carddav-mapping-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <?php echo $this->render_mapping_select($key, isset($settings['mapping'][$key]) ? $settings['mapping'][$key] : '', $options); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><label for="gf-carddav-category"><?php esc_html_e('vCard category', 'gf-carddav-server'); ?></label></th>
                        <td><input id="gf-carddav-category" name="<?php echo esc_attr(self::OPTION_KEY); ?>[category]" type="text" class="regular-text" value="<?php echo esc_attr($settings['category']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Authorized users', 'gf-carddav-server'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e('Authorized users', 'gf-carddav-server'); ?></legend>
                                <?php foreach ($users as $user) : ?>
                                    <label style="display:block; margin-bottom:6px;">
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allowed_user_ids][]" value="<?php echo esc_attr($user->ID); ?>" <?php checked(in_array((int) $user->ID, array_map('intval', $settings['allowed_user_ids']), true)); ?>>
                                        <?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e('Select every WordPress user allowed to authenticate against this CardDAV directory.', 'gf-carddav-server'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Debug logs', 'gf-carddav-server'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[debug_enabled]" value="1" <?php checked(! empty($settings['debug_enabled'])); ?>> <?php esc_html_e('Enable request logging', 'gf-carddav-server'); ?></label></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        (function() {
            const formSelect = document.getElementById('gf-carddav-form-id');
            if (!formSelect) {
                return;
            }
            formSelect.addEventListener('change', function() {
                const data = new window.FormData();
                data.append('action', 'gf_carddav_form_fields');
                data.append('nonce', '<?php echo esc_js(wp_create_nonce('gf_carddav_form_fields')); ?>');
                data.append('form_id', formSelect.value);
                window.fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(response => response.json())
                    .then(payload => {
                        if (!payload.success) {
                            return;
                        }
                        Object.keys(payload.data).forEach(function(key) {
                            const select = document.getElementById('gf-carddav-mapping-' + key);
                            if (!select) {
                                return;
                            }
                            select.innerHTML = payload.data[key];
                        });
                    });
            });
        }());
        </script>
        <?php
    }

    public function ajax_form_fields() {
        check_ajax_referer('gf_carddav_form_fields', 'nonce');

        if (! current_user_can(self::PAGE_CAPABILITY)) {
            wp_send_json_error();
        }

        $form_id  = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        $choices  = $this->get_field_choices($form_id);
        $payload  = array();

        foreach (array_keys($this->get_mapping_labels()) as $key) {
            $payload[$key] = $this->render_mapping_select($key, '', $choices);
        }

        wp_send_json_success($payload);
    }

    public function get_forms() {
        if (! class_exists('GFAPI')) {
            return array();
        }

        $gfapi = 'GFAPI';
        $forms = $gfapi::get_forms();

        return is_array($forms) ? $forms : array();
    }

    public function get_form_choices() {
        $choices = array(
            array(
                'label' => __('Select a form', 'gf-carddav-server'),
                'value' => '0',
            ),
        );

        foreach ($this->get_forms() as $form) {
            $choices[] = array(
                'label' => isset($form['title']) ? $form['title'] : sprintf(__('Form #%d', 'gf-carddav-server'), (int) $form['id']),
                'value' => (string) $form['id'],
            );
        }

        return $choices;
    }

    public function get_field_choices($form_id) {
        if (! class_exists('GFAPI') || $form_id <= 0) {
            return array();
        }

        $gfapi = 'GFAPI';
        $form  = $gfapi::get_form($form_id);

        if (! is_array($form) || empty($form['fields'])) {
            return array();
        }

        $choices = array();

        foreach ($form['fields'] as $field) {
            $field_id    = (string) $field->id;
            $field_label = method_exists($field, 'get_field_label') ? $field->get_field_label(false, '') : $field->label;

            if (! empty($field->inputs) && is_array($field->inputs)) {
                foreach ($field->inputs as $input) {
                    $input_id    = (string) $input['id'];
                    $input_label = isset($input['label']) ? $input['label'] : $input_id;
                    $choices[$input_id] = sprintf('%s -> %s (%s)', $field_label, $input_label, $input_id);
                }
                continue;
            }

            $choices[$field_id] = sprintf('%s (%s)', $field_label, $field_id);
        }

        return $choices;
    }

    public function get_field_select_choices($form_id) {
        $choices = array(
            array(
                'label' => __('Not mapped', 'gf-carddav-server'),
                'value' => '',
            ),
        );

        foreach ($this->get_field_choices($form_id) as $value => $label) {
            $choices[] = array(
                'label' => $label,
                'value' => (string) $value,
            );
        }

        return $choices;
    }

    public function get_allowed_user_choices() {
        $choices = array();

        foreach (get_users(array('fields' => array('ID', 'display_name', 'user_login'))) as $user) {
            $choices[] = array(
                'name'  => 'user_' . (int) $user->ID,
                'label' => $user->display_name . ' (' . $user->user_login . ')',
                'value' => (string) $user->ID,
            );
        }

        return $choices;
    }

    public function get_allowed_user_picker_data() {
        $users = get_users(
            array(
                'fields'  => array('ID', 'display_name', 'user_login', 'user_email'),
                'orderby' => 'display_name',
                'order'   => 'ASC',
            )
        );

        $payload = array();

        foreach ($users as $user) {
            $display_name = trim((string) $user->display_name);
            $login        = trim((string) $user->user_login);
            $email        = trim((string) $user->user_email);
            $label        = $display_name !== '' ? $display_name : $login;

            if ($email !== '') {
                $label .= ' <' . $email . '>';
            } elseif ($login !== '' && $login !== $label) {
                $label .= ' (' . $login . ')';
            }

            $payload[] = array(
                'id'           => (int) $user->ID,
                'label'        => $label,
                'display_name' => $display_name,
                'login'        => $login,
                'email'        => $email,
            );
        }

        return $payload;
    }

    private function get_mapping_labels() {
        return array(
            'first_name' => __('First name', 'gf-carddav-server'),
            'last_name'  => __('Last name', 'gf-carddav-server'),
            'email'      => __('Email', 'gf-carddav-server'),
            'phone'      => __('Phone', 'gf-carddav-server'),
            'union'      => __('Union', 'gf-carddav-server'),
            'department' => __('Department', 'gf-carddav-server'),
        );
    }

    private function render_mapping_select($key, $selected_value, array $choices) {
        ob_start();
        ?>
        <select id="gf-carddav-mapping-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mapping][<?php echo esc_attr($key); ?>]">
            <option value=""><?php esc_html_e('Not mapped', 'gf-carddav-server'); ?></option>
            <?php foreach ($choices as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected((string) $selected_value, (string) $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
        return ob_get_clean();
    }

    private function normalize_settings(array $settings) {
        $defaults = $this->get_defaults();
        $mapping  = isset($settings['mapping']) && is_array($settings['mapping']) ? $settings['mapping'] : array();

        if (empty($mapping)) {
            $mapping = array(
                'first_name' => isset($settings['mapping_first_name']) ? $settings['mapping_first_name'] : '',
                'last_name'  => isset($settings['mapping_last_name']) ? $settings['mapping_last_name'] : '',
                'email'      => isset($settings['mapping_email']) ? $settings['mapping_email'] : '',
                'phone'      => isset($settings['mapping_phone']) ? $settings['mapping_phone'] : '',
                'union'      => isset($settings['mapping_union']) ? $settings['mapping_union'] : '',
                'department' => isset($settings['mapping_department']) ? $settings['mapping_department'] : '',
            );
        }

        $normalized = $defaults;
        $normalized['form_id'] = isset($settings['form_id']) ? absint($settings['form_id']) : 0;
        $normalized['mapping'] = array(
            'first_name' => isset($mapping['first_name']) ? sanitize_text_field($mapping['first_name']) : '',
            'last_name'  => isset($mapping['last_name']) ? sanitize_text_field($mapping['last_name']) : '',
            'email'      => isset($mapping['email']) ? sanitize_text_field($mapping['email']) : '',
            'phone'      => isset($mapping['phone']) ? sanitize_text_field($mapping['phone']) : '',
            'union'      => isset($mapping['union']) ? sanitize_text_field($mapping['union']) : '',
            'department' => isset($mapping['department']) ? sanitize_text_field($mapping['department']) : '',
        );
        $allowed_user_ids = isset($settings['allowed_user_ids']) ? $settings['allowed_user_ids'] : array();

        if (is_string($allowed_user_ids)) {
            $decoded = json_decode($allowed_user_ids, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $allowed_user_ids = $decoded;
            } else {
                $allowed_user_ids = preg_split('/\s*,\s*/', $allowed_user_ids, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        $normalized['allowed_user_ids'] = array_values(array_unique(array_filter(array_map('absint', (array) $allowed_user_ids))));
        $normalized['category'] = isset($settings['category']) ? sanitize_text_field($settings['category']) : '';
        $normalized['debug_enabled'] = empty($settings['debug_enabled']) ? 0 : 1;
        $normalized['mapping_version'] = isset($settings['mapping_version']) ? sanitize_text_field((string) $settings['mapping_version']) : '';

        return $normalized;
    }
}

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
        add_action('wp_loaded', array($this, 'maybe_persist_access_override'), 10);
        add_action('shutdown', array($this, 'maybe_persist_access_override'), 999);
        add_action('wp_ajax_gf_carddav_form_fields', array($this, 'ajax_form_fields'));
        add_filter('option_page_capability_' . self::OPTION_GROUP, array($this, 'get_option_page_capability'));
    }

    public function get_defaults() {
        return array(
            'form_id'          => 0,
            'mapping'          => array(),
            'allowed_user_ids' => array(),
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
        $normalized['mapping'] = $override['mapping'];

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

    private $did_persist_override = false;

    public function maybe_persist_access_override() {
        if ($this->did_persist_override) {
            return;
        }

        if (! $this->is_addon_settings_post()) {
            return;
        }

        $this->did_persist_override = true;

        $current = $this->normalize_settings(get_option(self::OPTION_KEY, array()));

        if (isset($_POST['_gaddon_setting_allowed_user_ids'])) {
            $current['allowed_user_ids'] = array_values(array_unique(array_filter(array_map('absint', (array) wp_unslash($_POST['_gaddon_setting_allowed_user_ids'])))));
        }

        $current['debug_enabled'] = empty($_POST['_gaddon_setting_debug_enabled']) ? 0 : 1;

        if (isset($_POST['_gaddon_setting_mapping']) && is_array($_POST['_gaddon_setting_mapping'])) {
            $current['mapping'] = $this->sanitize_mapping(wp_unslash($_POST['_gaddon_setting_mapping'])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        } elseif (isset($_POST[ self::OPTION_KEY ]) && isset($_POST[ self::OPTION_KEY ]['mapping'])) {
            $current['mapping'] = $this->sanitize_mapping(wp_unslash($_POST[ self::OPTION_KEY ]['mapping'])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

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

        $sanitized = $defaults;
        $sanitized['form_id'] = isset($input['form_id']) ? absint($input['form_id']) : 0;
        $sanitized['mapping'] = isset($input['mapping']) ? $this->sanitize_mapping($input['mapping']) : array();
        $sanitized['allowed_user_ids'] = array_values(array_unique(array_filter(array_map('absint', isset($input['allowed_user_ids']) ? (array) $input['allowed_user_ids'] : array()))));
        $sanitized['debug_enabled'] = empty($input['debug_enabled']) ? 0 : 1;

        $mapping_changed = $current['form_id'] !== $sanitized['form_id']
            || wp_json_encode($current['mapping']) !== wp_json_encode($sanitized['mapping']);

        $sanitized['mapping_version'] = $mapping_changed ? (string) time() : $this->get_mapping_version();

        return $sanitized;
    }


    /**
     * Public wrapper for sanitize_mapping — used by the add-on render method
     * to sanitize $_POST mapping data when re-rendering after a save.
     */
    public function sanitize_mapping_public( $mapping ) {
        return $this->sanitize_mapping( $mapping );
    }

    private function sanitize_mapping($mapping) {
        $sanitized = array();

        if (! is_array($mapping)) {
            return $sanitized;
        }

        foreach ($mapping as $entry) {
            if (! is_array($entry) || empty($entry['vcard'])) {
                continue;
            }

            $vcard_key = sanitize_text_field($entry['vcard']);

            if (! GF_CardDAV_VCard_Catalog::exists($vcard_key)) {
                continue;
            }

            // Accept both old field_id (string) and new field_ids (array) format.
            $field_ids = array();

            if (isset($entry['field_ids']) && is_array($entry['field_ids'])) {
                foreach ($entry['field_ids'] as $fid) {
                    $fid = sanitize_text_field((string) $fid);
                    if ($fid !== '') {
                        $field_ids[] = $fid;
                    }
                }
            } elseif (isset($entry['field_id'])) {
                $fid = sanitize_text_field((string) $entry['field_id']);
                if ($fid !== '') {
                    $field_ids[] = $fid;
                }
            }

            if (empty($field_ids)) {
                continue;
            }

            $separator = isset($entry['separator']) ? sanitize_text_field((string) $entry['separator']) : ' ';

            $sanitized[] = array(
                'vcard'     => $vcard_key,
                'field_ids' => $field_ids,
                'separator' => $separator,
            );
        }

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
                    <tr>
                        <th scope="row"><?php esc_html_e('Field mapping', 'gf-carddav-server'); ?></th>
                        <td>
                            <?php $this->render_mapping_ui($settings['mapping'], $options, self::OPTION_KEY . '[mapping]'); ?>
                        </td>
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
        <?php
    }

    public function render_mapping_ui($current_mapping, $field_choices, $input_name_prefix) {
        $group_labels  = GF_CardDAV_VCard_Catalog::get_group_labels();
        $js_catalog    = GF_CardDAV_VCard_Catalog::get_js_catalog();
        $safe_mapping  = array();

        if (is_array($current_mapping)) {
            foreach ($current_mapping as $entry) {
                if (is_array($entry) && ! empty($entry['vcard']) && GF_CardDAV_VCard_Catalog::exists($entry['vcard'])) {
                    // Normalize to field_ids format for the JS.
                    $field_ids = array();
                    if (isset($entry['field_ids']) && is_array($entry['field_ids'])) {
                        $field_ids = array_values(array_filter($entry['field_ids'], function($v) { return (string) $v !== ''; }));
                    } elseif (isset($entry['field_id']) && (string) $entry['field_id'] !== '') {
                        $field_ids = array( (string) $entry['field_id'] );
                    }
                    $safe_mapping[] = array(
                        'vcard'     => $entry['vcard'],
                        'field_ids' => $field_ids,
                        'separator' => isset($entry['separator']) ? $entry['separator'] : ' ',
                    );
                }
            }
        }

        $container_id = 'gf-carddav-mapping-' . wp_generate_password(6, false, false);
        $ajax_nonce   = wp_create_nonce('gf_carddav_form_fields');
        ?>
        <div id="<?php echo esc_attr($container_id); ?>" class="gf-carddav-mapping-ui"
            data-catalog="<?php echo esc_attr(wp_json_encode($js_catalog)); ?>"
            data-groups="<?php echo esc_attr(wp_json_encode($group_labels)); ?>"
            data-mapping="<?php echo esc_attr(wp_json_encode($safe_mapping)); ?>"
            data-field-choices="<?php echo esc_attr(wp_json_encode($field_choices)); ?>"
            data-input-prefix="<?php echo esc_attr($input_name_prefix); ?>"
            data-ajax-nonce="<?php echo esc_attr($ajax_nonce); ?>">
            <div class="gf-carddav-mapping__rows"></div>
            <div class="gf-carddav-mapping__add">
                <select class="gf-carddav-mapping__add-select">
                    <option value=""><?php esc_html_e('Add a field...', 'gf-carddav-server'); ?></option>
                </select>
            </div>
            <p class="description"><?php esc_html_e('Select a vCard property to map, then choose the corresponding Gravity Forms field.', 'gf-carddav-server'); ?></p>
        </div>
        <?php

        static $did_output_assets = false;

        if ($did_output_assets) {
            ?>
            <script>window.GFCardDAVMapping && window.GFCardDAVMapping.init('<?php echo esc_js($container_id); ?>');</script>
            <?php
            return;
        }

        $did_output_assets = true;
        ?>
        <style>
        .gf-carddav-mapping-ui{max-width:860px}
        .gf-carddav-mapping__rows{display:grid;gap:8px;margin-bottom:12px}
        .gf-carddav-mapping__row{display:flex;align-items:flex-start;gap:12px;padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
        .gf-carddav-mapping__row-label{min-width:140px;font-weight:600;padding-top:7px}
        .gf-carddav-mapping__row-body{flex:1;display:flex;flex-direction:column;gap:6px}
        .gf-carddav-mapping__field-row{display:flex;align-items:center;gap:8px}
        .gf-carddav-mapping__field-row select,
        .gf-carddav-mapping__field-row input[type="text"]{flex:1;min-width:0}
        .gf-carddav-mapping__field-remove{flex:0 0 auto;border:0;background:transparent;cursor:pointer;font-size:18px;line-height:1;padding:4px 6px;color:#b32d2e;border-radius:4px}
        .gf-carddav-mapping__field-remove:hover{background:#fcecec}
        .gf-carddav-mapping__separator{display:flex;align-items:center;gap:8px;margin-top:2px;font-size:13px;color:#50575e}
        .gf-carddav-mapping__separator select{width:auto}
        .gf-carddav-mapping__separator input[type="text"]{width:80px}
        .gf-carddav-mapping__add-field{display:inline;background:none;border:none;color:#2271b1;cursor:pointer;font-size:13px;padding:0;text-decoration:none}
        .gf-carddav-mapping__add-field:hover{color:#135e96;text-decoration:underline}
        .gf-carddav-mapping__row-remove-prop{flex:0 0 auto;align-self:flex-start;margin-top:4px}
        .gf-carddav-mapping__add{margin-bottom:8px}
        .gf-carddav-mapping__add-select{width:100%;max-width:400px}
        </style>
        <script>
        (function(){
            function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML}

            var SEPARATOR_OPTIONS=[
                {value:' ',   label:'<?php echo esc_js(__('Space', 'gf-carddav-server')); ?>'},
                {value:', ',  label:'<?php echo esc_js(__('Comma', 'gf-carddav-server')); ?>'},
                {value:'\n',  label:'<?php echo esc_js(__('New line', 'gf-carddav-server')); ?>'},
                {value:'gf_custom', label:'<?php echo esc_js(__('Custom...', 'gf-carddav-server')); ?>'}
            ];

            window.GFCardDAVMapping={
                init:function(containerId){
                    var root=document.getElementById(containerId);
                    if(!root||root.dataset.bound==='1')return;
                    root.dataset.bound='1';

                    var catalog=JSON.parse(root.getAttribute('data-catalog')||'{}');
                    var groups=JSON.parse(root.getAttribute('data-groups')||'{}');
                    var mapping=JSON.parse(root.getAttribute('data-mapping')||'[]');
                    var fieldChoices=JSON.parse(root.getAttribute('data-field-choices')||'{}');
                    var inputPrefix=root.getAttribute('data-input-prefix');
                    var ajaxNonce=root.getAttribute('data-ajax-nonce');

                    /* Migrate legacy field_id entries to field_ids */
                    for(var m=0;m<mapping.length;m++){
                        if(!mapping[m].field_ids){
                            mapping[m].field_ids=mapping[m].field_id?[mapping[m].field_id]:[];
                            mapping[m].separator=mapping[m].separator||' ';
                            delete mapping[m].field_id;
                        }
                        if(!mapping[m].separator)mapping[m].separator=' ';
                    }

                    var rowsContainer=root.querySelector('.gf-carddav-mapping__rows');
                    var addSelect=root.querySelector('.gf-carddav-mapping__add-select');

                    function getUsedKeys(){
                        return mapping.map(function(e){return e.vcard});
                    }

                    function refreshAddSelect(){
                        var used=getUsedKeys();
                        var html='<option value=""><?php echo esc_js(__('Add a field...', 'gf-carddav-server')); ?></option>';
                        var groupKeys=Object.keys(groups);
                        for(var g=0;g<groupKeys.length;g++){
                            var groupKey=groupKeys[g];
                            var groupLabel=groups[groupKey];
                            var opts='';
                            var catKeys=Object.keys(catalog);
                            for(var i=0;i<catKeys.length;i++){
                                var key=catKeys[i];
                                if(catalog[key].group!==groupKey)continue;
                                if(used.indexOf(key)!==-1)continue;
                                opts+='<option value="'+esc(key)+'">'+esc(catalog[key].label)+'</option>';
                            }
                            if(opts){
                                html+='<optgroup label="'+esc(groupLabel)+'">'+opts+'</optgroup>';
                            }
                        }
                        addSelect.innerHTML=html;
                    }

                    function buildFieldSelect(selectedValue){
                        var select=document.createElement('select');
                        var opt=document.createElement('option');
                        opt.value='';
                        opt.textContent='<?php echo esc_js(__('Not mapped', 'gf-carddav-server')); ?>';
                        select.appendChild(opt);
                        var keys=Object.keys(fieldChoices);
                        for(var i=0;i<keys.length;i++){
                            var o=document.createElement('option');
                            o.value=keys[i];
                            o.textContent=fieldChoices[keys[i]];
                            if(keys[i]===String(selectedValue))o.selected=true;
                            select.appendChild(o);
                        }
                        return select;
                    }

                    function isPresetSeparator(val){
                        for(var i=0;i<SEPARATOR_OPTIONS.length;i++){
                            if(SEPARATOR_OPTIONS[i].value===val)return true;
                        }
                        return false;
                    }

                    function buildSeparatorUI(entry,mapIndex){
                        var wrap=document.createElement('div');
                        wrap.className='gf-carddav-mapping__separator';

                        var label=document.createElement('span');
                        label.textContent='<?php echo esc_js(__('Combine with', 'gf-carddav-server')); ?>';
                        wrap.appendChild(label);

                        var sel=document.createElement('select');
                        var isCustom=!isPresetSeparator(entry.separator);

                        for(var i=0;i<SEPARATOR_OPTIONS.length;i++){
                            var o=document.createElement('option');
                            o.value=SEPARATOR_OPTIONS[i].value;
                            o.textContent=SEPARATOR_OPTIONS[i].label;
                            if(!isCustom && SEPARATOR_OPTIONS[i].value===entry.separator)o.selected=true;
                            if(isCustom && SEPARATOR_OPTIONS[i].value==='gf_custom')o.selected=true;
                            sel.appendChild(o);
                        }
                        wrap.appendChild(sel);

                        var customInput=document.createElement('input');
                        customInput.type='text';
                        customInput.value=isCustom?entry.separator:'';
                        customInput.placeholder='<?php echo esc_js(__('separator', 'gf-carddav-server')); ?>';
                        customInput.style.display=isCustom?'':'none';
                        wrap.appendChild(customInput);

                        sel.addEventListener('change',function(){
                            if(this.value==='gf_custom'){
                                customInput.style.display='';
                                mapping[mapIndex].separator=customInput.value||'';
                            }else{
                                customInput.style.display='none';
                                mapping[mapIndex].separator=this.value;
                            }
                            syncHidden();
                        });

                        customInput.addEventListener('input',function(){
                            mapping[mapIndex].separator=this.value;
                            syncHidden();
                        });

                        return wrap;
                    }

                    function renderRow(entry,mapIndex){
                        var catDef=catalog[entry.vcard];
                        if(!catDef)return;

                        var row=document.createElement('div');
                        row.className='gf-carddav-mapping__row';

                        /* Left label */
                        var label=document.createElement('div');
                        label.className='gf-carddav-mapping__row-label';
                        label.textContent=catDef.label;
                        row.appendChild(label);

                        /* Body: stacked field rows + separator + add-field link */
                        var body=document.createElement('div');
                        body.className='gf-carddav-mapping__row-body';

                        var fieldIds=entry.field_ids;
                        if(!fieldIds.length)fieldIds=[''];

                        for(var f=0;f<fieldIds.length;f++){
                            (function(fieldIndex){
                                var fieldRow=document.createElement('div');
                                fieldRow.className='gf-carddav-mapping__field-row';

                                if(entry.vcard==='categories'){
                                    var inp=document.createElement('input');
                                    inp.type='text';
                                    inp.value=fieldIds[fieldIndex]||'';
                                    inp.placeholder='<?php echo esc_js(__('Enter category value', 'gf-carddav-server')); ?>';
                                    inp.addEventListener('input',function(){
                                        mapping[mapIndex].field_ids[fieldIndex]=this.value;
                                        syncHidden();
                                    });
                                    fieldRow.appendChild(inp);
                                }else{
                                    var sel=buildFieldSelect(fieldIds[fieldIndex]||'');
                                    sel.addEventListener('change',function(){
                                        mapping[mapIndex].field_ids[fieldIndex]=this.value;
                                        syncHidden();
                                    });
                                    fieldRow.appendChild(sel);
                                }

                                /* Per-field remove (×) button */
                                var removeBtn=document.createElement('button');
                                removeBtn.type='button';
                                removeBtn.className='gf-carddav-mapping__field-remove';
                                removeBtn.innerHTML='&times;';
                                removeBtn.title='<?php echo esc_js(__('Remove this field', 'gf-carddav-server')); ?>';
                                removeBtn.addEventListener('click',function(){
                                    if(mapping[mapIndex].field_ids.length<=1){
                                        /* Last field — remove the entire property */
                                        mapping.splice(mapIndex,1);
                                    }else{
                                        mapping[mapIndex].field_ids.splice(fieldIndex,1);
                                    }
                                    renderAll();
                                });
                                fieldRow.appendChild(removeBtn);

                                body.appendChild(fieldRow);
                            })(f);
                        }

                        /* Separator selector (only when 2+ fields) */
                        if(entry.field_ids.length>=2){
                            body.appendChild(buildSeparatorUI(entry,mapIndex));
                        }

                        /* "+ Add field" link */
                        var addFieldBtn=document.createElement('button');
                        addFieldBtn.type='button';
                        addFieldBtn.className='gf-carddav-mapping__add-field';
                        addFieldBtn.textContent='+ <?php echo esc_js(__('Add field', 'gf-carddav-server')); ?>';
                        addFieldBtn.addEventListener('click',function(){
                            mapping[mapIndex].field_ids.push('');
                            renderAll();
                        });
                        body.appendChild(addFieldBtn);

                        row.appendChild(body);
                        rowsContainer.appendChild(row);
                    }

                    function renderAll(){
                        rowsContainer.innerHTML='';
                        for(var i=0;i<mapping.length;i++){
                            renderRow(mapping[i],i);
                        }
                        refreshAddSelect();
                        syncHidden();
                    }

                    function syncHidden(){
                        var old=root.querySelectorAll('input.gf-carddav-mapping__hidden');
                        for(var i=0;i<old.length;i++){
                            old[i].parentNode.removeChild(old[i]);
                        }
                        for(var i=0;i<mapping.length;i++){
                            var entry=mapping[i];
                            var base=inputPrefix+'['+i+']';

                            var vInp=document.createElement('input');
                            vInp.type='hidden';
                            vInp.className='gf-carddav-mapping__hidden';
                            vInp.name=base+'[vcard]';
                            vInp.value=entry.vcard;
                            root.appendChild(vInp);

                            var sInp=document.createElement('input');
                            sInp.type='hidden';
                            sInp.className='gf-carddav-mapping__hidden';
                            sInp.name=base+'[separator]';
                            sInp.value=entry.separator;
                            root.appendChild(sInp);

                            for(var f=0;f<entry.field_ids.length;f++){
                                var fInp=document.createElement('input');
                                fInp.type='hidden';
                                fInp.className='gf-carddav-mapping__hidden';
                                fInp.name=base+'[field_ids][]';
                                fInp.value=entry.field_ids[f];
                                root.appendChild(fInp);
                            }
                        }
                    }

                    addSelect.addEventListener('change',function(){
                        var key=this.value;
                        if(!key||!catalog[key])return;
                        mapping.push({vcard:key,field_ids:[''],separator:' '});
                        this.value='';
                        renderAll();
                    });

                    var formSelect=document.getElementById('gf-carddav-form-id');
                    if(formSelect){
                        formSelect.addEventListener('change',function(){
                            var data=new window.FormData();
                            data.append('action','gf_carddav_form_fields');
                            data.append('nonce',ajaxNonce);
                            data.append('form_id',formSelect.value);
                            window.fetch(ajaxurl,{method:'POST',body:data,credentials:'same-origin'})
                                .then(function(r){return r.json()})
                                .then(function(payload){
                                    if(!payload.success)return;
                                    fieldChoices=payload.data;
                                    renderAll();
                                });
                        });
                    }

                    renderAll();
                }
            };

            function initAll(){
                var nodes=document.querySelectorAll('.gf-carddav-mapping-ui');
                for(var i=0;i<nodes.length;i++){
                    window.GFCardDAVMapping.init(nodes[i].id);
                }
            }

            if(document.readyState==='loading'){
                document.addEventListener('DOMContentLoaded',initAll);
            }else{
                initAll();
            }
        }());
        </script>
        <?php
    }

    public function ajax_form_fields() {
        check_ajax_referer('gf_carddav_form_fields', 'nonce');

        if (! current_user_can(self::PAGE_CAPABILITY)) {
            wp_send_json_error();
        }

        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;

        wp_send_json_success($this->get_field_choices($form_id));
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

    private function is_old_format_mapping($mapping) {
        if (! is_array($mapping) || empty($mapping)) {
            return false;
        }

        return isset($mapping['first_name']) || isset($mapping['last_name']) || isset($mapping['email'])
            || isset($mapping['phone']) || isset($mapping['union']) || isset($mapping['department']);
    }

    private function migrate_mapping($old_mapping) {
        $new_mapping = array();
        $legacy_map  = GF_CardDAV_VCard_Catalog::get_legacy_key_map();

        foreach ($old_mapping as $old_key => $field_id) {
            $field_id = trim((string) $field_id);

            if ($field_id === '' || ! isset($legacy_map[$old_key])) {
                continue;
            }

            $new_mapping[] = array(
                'vcard'    => $legacy_map[$old_key],
                'field_id' => $field_id,
            );
        }

        return $new_mapping;
    }

    private function normalize_settings(array $settings) {
        $defaults = $this->get_defaults();
        $mapping  = isset($settings['mapping']) ? $settings['mapping'] : array();

        if ($this->is_old_format_mapping($mapping)) {
            $mapping = $this->migrate_mapping($mapping);
        } elseif (! is_array($mapping)) {
            $mapping = array();
        } else {
            $mapping = array_values(array_filter($mapping, function($entry) {
                if (! is_array($entry) || empty($entry['vcard'])) {
                    return false;
                }
                // Accept new field_ids format or legacy field_id.
                if (! empty($entry['field_ids']) && is_array($entry['field_ids'])) {
                    return true;
                }
                return ! empty($entry['field_id']);
            }));
        }

        // Normalize all entries to the field_ids format.
        foreach ($mapping as &$entry) {
            if (! isset($entry['field_ids']) || ! is_array($entry['field_ids'])) {
                $fid = isset($entry['field_id']) ? (string) $entry['field_id'] : '';
                $entry['field_ids'] = $fid !== '' ? array( $fid ) : array();
                unset($entry['field_id']);
            }
            if (! isset($entry['separator'])) {
                $entry['separator'] = ' ';
            }
        }
        unset($entry);

        $normalized = $defaults;
        $normalized['form_id'] = isset($settings['form_id']) ? absint($settings['form_id']) : 0;
        $normalized['mapping'] = $mapping;

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
        $normalized['debug_enabled'] = empty($settings['debug_enabled']) ? 0 : 1;
        $normalized['mapping_version'] = isset($settings['mapping_version']) ? sanitize_text_field((string) $settings['mapping_version']) : '';

        return $normalized;
    }
}

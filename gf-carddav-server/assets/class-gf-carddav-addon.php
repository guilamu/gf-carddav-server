<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

GFForms::include_addon_framework();

class GF_CardDAV_AddOn extends GFAddOn {
	protected $_version = GF_CARDDAV_SERVER_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug = 'gf-carddav-server';
	protected $_path = 'gf-carddav-server/gf-carddav-server.php';
	protected $_full_path = GF_CARDDAV_SERVER_FILE;
	protected $_title = 'GF CardDAV Server';
	protected $_short_title = 'CardDAV Server';
	protected $_capabilities = array( 'manage_options' );
	protected $_capabilities_settings_page = array( 'manage_options' );
	protected $_capabilities_form_settings = array( 'manage_options' );
	protected $_capabilities_uninstall = array( 'gravityforms_uninstall' );

	private static $_instance = null;

	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function init() {
		$this->_title = esc_html__( 'GF CardDAV Server', 'gf-carddav-server' );
		$this->_short_title = esc_html__( 'CardDAV Server', 'gf-carddav-server' );

		parent::init();
	}

	public function init_admin() {
		parent::init_admin();
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_page' ) );
	}

	public function maybe_redirect_legacy_page() {
		if ( ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$subview = isset( $_GET['subview'] ) ? sanitize_key( wp_unslash( $_GET['subview'] ) ) : '';

		if ( $page !== $this->_slug || $subview === $this->_slug ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'gf_settings',
					'subview' => $this->_slug,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function plugin_settings_fields() {
		$settings_service = gf_carddav_server()->get_settings();
		$settings = $settings_service->get_settings();
		$field_choices = $settings_service->get_field_choices( (int) $settings['form_id'] );

		return array(
			array(
				'title'       => esc_html__( 'Source', 'gf-carddav-server' ),
				'description' => esc_html__( 'Choose which Gravity Forms form feeds the CardDAV directory.', 'gf-carddav-server' ),
				'class'       => 'gf-carddav-settings-section gf-carddav-settings-section--source',
				'fields'      => array(
					array(
						'type'          => 'select',
						'name'          => 'form_id',
						'label'         => esc_html__( 'Source form', 'gf-carddav-server' ),
						'choices'       => $settings_service->get_form_choices(),
						'default_value' => (string) $settings['form_id'],
					),
				),
			),
			array(
				'title'       => esc_html__( 'Field Mapping', 'gf-carddav-server' ),
				'description' => esc_html__( 'Add vCard properties and map them to Gravity Forms fields. No fields are mapped by default.', 'gf-carddav-server' ),
				'class'       => 'gf-carddav-settings-section gf-carddav-settings-section--mapping',
				'fields'      => array(
					array(
						'type'        => 'mapping_ui',
						'name'        => 'mapping',
						'label'       => esc_html__( 'vCard mapping', 'gf-carddav-server' ),
						'mapping'     => $settings['mapping'],
						'field_choices' => $field_choices,
					),
				),
			),
			array(
				'title'       => esc_html__( 'Access', 'gf-carddav-server' ),
				'description' => esc_html__( 'Control who can authenticate against this CardDAV directory and whether requests are logged.', 'gf-carddav-server' ),
				'class'       => 'gf-carddav-settings-section gf-carddav-settings-section--access',
				'fields'      => array(
					array(
						'type'        => 'authorized_users_picker',
						'name'        => 'allowed_user_ids',
						'label'       => esc_html__( 'Authorized users', 'gf-carddav-server' ),
						'users'       => $settings_service->get_allowed_user_picker_data(),
						'default_value' => $settings['allowed_user_ids'],
						'result_limit' => 8,
					),
					array(
						'type'    => 'checkbox',
						'name'    => 'debug_enabled',
						'label'   => '',
						'choices' => array(
							array(
								'name'          => 'debug_enabled',
								'label'         => esc_html__( 'Enable request logging', 'gf-carddav-server' ),
								'default_value' => ! empty( $settings['debug_enabled'] ) ? 1 : 0,
							),
						),
					),
				),
			),
		);
	}

	public function settings_mapping_ui( $field ) {
		$settings_service = gf_carddav_server()->get_settings();

		/*
		 * Read the mapping fresh rather than from the cached $field array.
		 *
		 * GF caches the plugin_settings_fields() return value early — before
		 * processing the save — so $field['mapping'] still holds pre-save data
		 * when the page is re-rendered after a successful save.
		 *
		 * On a POST (save), the authoritative mapping lives in $_POST; on a
		 * normal GET it comes from the persisted settings.
		 */
		if ( isset( $_POST['_gaddon_setting_mapping'] ) && is_array( $_POST['_gaddon_setting_mapping'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$current_mapping = $settings_service->sanitize_mapping_public( wp_unslash( $_POST['_gaddon_setting_mapping'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} else {
			$fresh_settings  = $settings_service->get_settings();
			$current_mapping = $fresh_settings['mapping'];
		}

		$fresh_settings  = isset( $fresh_settings ) ? $fresh_settings : $settings_service->get_settings();
		$field_choices   = $settings_service->get_field_choices( (int) $fresh_settings['form_id'] );

		$settings_service->render_mapping_ui( $current_mapping, $field_choices, '_gaddon_setting_mapping' );
	}

	public function settings_authorized_users_picker( $field ) {
		$value = $this->get_setting( $field['name'] );
		$name  = '_gaddon_setting_' . esc_attr( $field['name'] ) . '[]';
		$users = isset( $field['users'] ) && is_array( $field['users'] ) ? array_values( $field['users'] ) : array();
		$limit = isset( $field['result_limit'] ) ? max( 1, (int) $field['result_limit'] ) : 8;

		if ( empty( $value ) && isset( $field['default_value'] ) ) {
			$value = $field['default_value'];
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$value = $decoded;
			} else {
				$value = preg_split( '/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY );
			}
		}

		$selected_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
		$container_id = 'gf-carddav-authorized-users-' . wp_generate_password( 8, false, false );
		$translations = array(
			'userLabel'     => __( 'User #%d', 'gf-carddav-server' ),
			'summary'       => __( '%1$d of %2$d selected', 'gf-carddav-server' ),
			'removeUser'    => __( 'Remove %s', 'gf-carddav-server' ),
			'addUser'       => __( 'Add', 'gf-carddav-server' ),
		);
		?>
		<div id="<?php echo esc_attr( $container_id ); ?>" class="gf-carddav-user-picker" data-users="<?php echo esc_attr( wp_json_encode( $users ) ); ?>" data-selected="<?php echo esc_attr( wp_json_encode( $selected_ids ) ); ?>" data-result-limit="<?php echo esc_attr( (string) $limit ); ?>" data-i18n="<?php echo esc_attr( wp_json_encode( $translations ) ); ?>">
			<div class="gf-carddav-user-picker__header">
				<p class="gf-carddav-user-picker__description"><?php esc_html_e( 'Search by name or email to grant or remove access.', 'gf-carddav-server' ); ?></p>
				<div class="gf-carddav-user-picker__summary" aria-live="polite"></div>
			</div>
			<div class="gf-carddav-user-picker__chips" aria-live="polite"></div>
			<label class="screen-reader-text" for="<?php echo esc_attr( $container_id ); ?>-search"><?php esc_html_e( 'Search users', 'gf-carddav-server' ); ?></label>
			<input id="<?php echo esc_attr( $container_id ); ?>-search" type="search" class="regular-text gf-carddav-user-picker__search" placeholder="<?php echo esc_attr__( 'Search users...', 'gf-carddav-server' ); ?>" autocomplete="off">
			<div class="gf-carddav-user-picker__results" role="listbox" aria-label="<?php echo esc_attr__( 'Available users', 'gf-carddav-server' ); ?>"></div>
			<p class="gf-carddav-user-picker__empty" hidden><?php esc_html_e( 'No matching users.', 'gf-carddav-server' ); ?></p>
			<div class="gf-carddav-user-picker__hidden-inputs" hidden>
				<?php foreach ( $selected_ids as $selected_id ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $selected_id ); ?>">
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		static $did_output_assets = false;

		if ( $did_output_assets ) {
			?>
			<script>
			window.GFCardDAVUserPicker && window.GFCardDAVUserPicker.init('<?php echo esc_js( $container_id ); ?>');
			</script>
			<?php
			return;
		}

		$did_output_assets = true;
		?>
		<style>
		.gf-carddav-user-picker {
			max-width: 860px;
		}
		.gf-carddav-user-picker__header {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 16px;
			margin-bottom: 10px;
		}
		.gf-carddav-user-picker__description {
			margin: 0;
			color: #50575e;
		}
		.gf-carddav-user-picker__summary {
			white-space: nowrap;
			font-weight: 600;
		}
		.gf-carddav-user-picker__chips {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin-bottom: 12px;
		}
		.gf-carddav-user-picker__chip {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			padding: 6px 12px;
			border: 1px solid #dcdcde;
			border-radius: 999px;
			background: #f6f7f7;
		}
		.gf-carddav-user-picker__chip-remove {
			border: 0;
			background: transparent;
			cursor: pointer;
			font-size: 16px;
			line-height: 1;
			padding: 0;
		}
		.gf-carddav-user-picker__search {
			width: 100%;
			max-width: none;
			margin-bottom: 12px;
		}
		.gf-carddav-user-picker__results {
			display: grid;
			gap: 8px;
		}
		.gf-carddav-user-picker__result {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			padding: 10px 12px;
			border: 1px solid #dcdcde;
			border-radius: 8px;
			background: #fff;
		}
		.gf-carddav-user-picker__result-meta {
			display: block;
			font-size: 12px;
			color: #646970;
			margin-top: 2px;
		}
		.gf-carddav-user-picker__result-add {
			flex: 0 0 auto;
		}
		.gf-carddav-user-picker__empty {
			margin: 0;
			color: #646970;
		}
		</style>
		<script>
		(function() {
			function parseJsonAttribute(value, fallback) {
				if (!value) {
					return fallback;
				}
				try {
					return JSON.parse(value);
				} catch (error) {
					return fallback;
				}
			}

			window.GFCardDAVUserPicker = {
				init: function(containerId) {
					var root = document.getElementById(containerId);
					if (!root || root.dataset.bound === '1') {
						return;
					}

					root.dataset.bound = '1';
					var users = parseJsonAttribute(root.getAttribute('data-users'), []);
					var selected = parseJsonAttribute(root.getAttribute('data-selected'), []).map(function(id) {
						return String(id);
					});
					var i18n = parseJsonAttribute(root.getAttribute('data-i18n'), {});
					var limit = parseInt(root.getAttribute('data-result-limit') || '8', 10);
					var hiddenInputsContainer = root.querySelector('.gf-carddav-user-picker__hidden-inputs');
					var searchInput = root.querySelector('.gf-carddav-user-picker__search');
					var chips = root.querySelector('.gf-carddav-user-picker__chips');
					var results = root.querySelector('.gf-carddav-user-picker__results');
					var empty = root.querySelector('.gf-carddav-user-picker__empty');
					var summary = root.querySelector('.gf-carddav-user-picker__summary');
					var hiddenInputName = <?php echo wp_json_encode( $name ); ?>;

					function format(template, replacements) {
						return String(template || '').replace(/%([0-9]+\$)?[ds]/g, function(match, indexToken) {
							var index = indexToken ? parseInt(indexToken, 10) - 1 : 0;
							var value = typeof replacements[index] === 'undefined' ? match : replacements[index];
							return String(value);
						});
					}

					users = users.map(function(user) {
						var fallbackLabel = format(i18n.userLabel || 'User #%d', [user.id]);
						var label = user.label || user.display_name || user.login || user.email || fallbackLabel;
						var metaParts = [user.email, user.login].filter(Boolean);
						return {
							id: String(user.id),
							label: label,
							meta: metaParts.join(' · '),
							haystack: [label, user.display_name, user.login, user.email].filter(Boolean).join(' ').toLowerCase()
						};
					});

					function syncHiddenValue() {
						hiddenInputsContainer.innerHTML = '';
						selected.forEach(function(id) {
							var hiddenInput = document.createElement('input');
							hiddenInput.type = 'hidden';
							hiddenInput.name = hiddenInputName;
							hiddenInput.value = id;
							hiddenInputsContainer.appendChild(hiddenInput);
						});
						root.setAttribute('data-selected', JSON.stringify(selected));
					}

					function renderSummary() {
						summary.textContent = format(i18n.summary || '%1$d of %2$d selected', [selected.length, users.length]);
					}

					function renderChips() {
						chips.innerHTML = '';
						selected.forEach(function(id) {
							var user = users.find(function(candidate) { return candidate.id === id; });
							if (!user) {
								return;
							}

							var chip = document.createElement('span');
							chip.className = 'gf-carddav-user-picker__chip';
							chip.textContent = user.label;

							var removeButton = document.createElement('button');
							removeButton.type = 'button';
							removeButton.className = 'gf-carddav-user-picker__chip-remove';
							removeButton.setAttribute('aria-label', format(i18n.removeUser || 'Remove %s', [user.label]));
							removeButton.textContent = '×';
							removeButton.addEventListener('click', function() {
								selected = selected.filter(function(selectedId) { return selectedId !== id; });
								syncHiddenValue();
								render();
							});

							chip.appendChild(removeButton);
							chips.appendChild(chip);
						});
					}

					function renderResults() {
						var query = (searchInput.value || '').trim().toLowerCase();
						var availableUsers = users.filter(function(user) {
							return selected.indexOf(user.id) === -1;
						});

						var filtered = availableUsers.filter(function(user) {
							return query === '' || user.haystack.indexOf(query) !== -1;
						}).slice(0, limit);

						results.innerHTML = '';
						empty.hidden = filtered.length > 0;

						filtered.forEach(function(user) {
							var row = document.createElement('div');
							row.className = 'gf-carddav-user-picker__result';

							var text = document.createElement('div');
							text.innerHTML = '<strong></strong><span class="gf-carddav-user-picker__result-meta"></span>';
							text.querySelector('strong').textContent = user.label;
							text.querySelector('span').textContent = user.meta;

							var button = document.createElement('button');
							button.type = 'button';
							button.className = 'button button-secondary gf-carddav-user-picker__result-add';
							button.textContent = i18n.addUser || 'Add';
							button.addEventListener('click', function() {
								selected.push(user.id);
								syncHiddenValue();
								render();
								searchInput.focus();
							});

							row.appendChild(text);
							row.appendChild(button);
							results.appendChild(row);
						});
					}

					function render() {
						renderSummary();
						renderChips();
						renderResults();
					}

					searchInput.addEventListener('input', renderResults);
					syncHiddenValue();
					render();
				}
			};

			function initAll() {
				document.querySelectorAll('.gf-carddav-user-picker[id]').forEach(function(node) {
					window.GFCardDAVUserPicker.init(node.id);
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initAll);
			} else {
				initAll();
			}
		}());
		</script>
		<?php
	}

}

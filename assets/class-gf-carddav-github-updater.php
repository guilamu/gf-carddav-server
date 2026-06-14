<?php
/**
 * GitHub auto-updater for GF CardDAV Server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_CardDAV_GitHub_Updater {
	private const GITHUB_USER = 'guilamu';
	private const GITHUB_REPO = 'gf-carddav-server';
	private const PLUGIN_FILE = 'gf-carddav-server/gf-carddav-server.php';
	private const PLUGIN_SLUG = 'gf-carddav-server';
	private const PLUGIN_NAME = 'GF CardDAV Server';
	private const PLUGIN_DESCRIPTION = 'Exposes Gravity Forms entries as a native CardDAV address book.';
	private const REQUIRES_WP = '6.0';
	private const TESTED_WP = '6.7';
	private const REQUIRES_PHP = '8.0';
	private const REQUIRES_GF = '2.5';
	private const TEXT_DOMAIN = 'gf-carddav-server';
	private const CACHE_KEY = 'gf_carddav_server_github_release';
	private const CACHE_EXPIRATION = 43200;

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'check_for_update' ), 10, 4 );
		add_filter( 'plugins_api', array( self::class, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'fix_folder_name' ), 10, 4 );
		add_action( 'admin_head', array( self::class, 'plugin_info_css' ) );
	}

	private static function get_release_data(): ?array {
		$release_data = get_transient( self::CACHE_KEY );

		if ( false !== $release_data && is_array( $release_data ) ) {
			return $release_data;
		}

		$headers = array();
		$token   = defined( 'GF_CARDDAV_SERVER_GITHUB_TOKEN' ) ? (string) GF_CARDDAV_SERVER_GITHUB_TOKEN : '';

		if ( '' !== $token ) {
			$headers['Authorization'] = 'token ' . $token;
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_USER, self::GITHUB_REPO ),
			array(
				'user-agent' => 'WordPress/' . self::PLUGIN_SLUG,
				'timeout'    => 15,
				'headers'    => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( self::PLUGIN_NAME . ' update error: ' . $response->get_error_message() );
			}

			return null;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( self::PLUGIN_NAME . ' update error: HTTP ' . $response_code );
			}

			return null;
		}

		$release_data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release_data ) || empty( $release_data['tag_name'] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( self::PLUGIN_NAME . ' update error: invalid release payload' );
			}

			return null;
		}

		set_transient( self::CACHE_KEY, $release_data, self::CACHE_EXPIRATION );

		return $release_data;
	}

	private static function get_package_url( array $release_data ): string {
		if ( ! empty( $release_data['assets'] ) && is_array( $release_data['assets'] ) ) {
			foreach ( $release_data['assets'] as $asset ) {
				if ( isset( $asset['browser_download_url'], $asset['name'] ) && str_ends_with( $asset['name'], '.zip' ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return isset( $release_data['zipball_url'] ) ? (string) $release_data['zipball_url'] : '';
	}

	private static function get_plugin_info_download_link( ?array $release_data = null ): string {
		if ( is_array( $release_data ) ) {
			$package_url = self::get_package_url( $release_data );

			if ( '' !== $package_url ) {
				return $package_url;
			}
		}

		return sprintf(
			'https://github.com/%s/%s/releases/latest/download/%s.zip',
			self::GITHUB_USER,
			self::GITHUB_REPO,
			self::GITHUB_REPO
		);
	}

	public static function check_for_update( $update, array $plugin_data, string $plugin_file, $locales ) {
		unset( $locales );

		if ( self::PLUGIN_FILE !== $plugin_file ) {
			return $update;
		}

		$release_data = self::get_release_data();
		if ( null === $release_data ) {
			return $update;
		}

		$new_version = ltrim( (string) $release_data['tag_name'], 'v' );
		if ( version_compare( (string) $plugin_data['Version'], $new_version, '>=' ) ) {
			return $update;
		}

		return array(
			'id'            => 'github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
			'slug'          => self::PLUGIN_SLUG,
			'plugin'        => self::PLUGIN_FILE,
			'new_version'   => $new_version,
			'version'       => $new_version,
			'package'       => self::get_package_url( $release_data ),
			'url'           => (string) $release_data['html_url'],
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => self::REQUIRES_PHP,
			'compatibility' => new stdClass(),
			'icons'         => array(),
			'banners'       => array(),
		);
	}

	public static function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		if ( ! isset( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $res;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file       = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
		$plugin_data       = get_plugin_data( $plugin_file, false, false );
		$release_data      = self::get_release_data();
		$installed_version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '1.0.0';
		$release_version   = ( $release_data && ! empty( $release_data['tag_name'] ) ) ? ltrim( (string) $release_data['tag_name'], 'v' ) : '';
		$version           = $installed_version;

		if ( '' !== $release_version && version_compare( $release_version, $installed_version, '>' ) ) {
			$version = $release_version;
		}

		$res               = new stdClass();
		$res->name         = self::PLUGIN_NAME;
		$res->slug         = self::PLUGIN_SLUG;
		$res->plugin       = self::PLUGIN_FILE;
		$res->version      = $version;
		$res->author       = sprintf( '<a href="%1$s">%2$s</a>', esc_url( 'https://github.com/' . self::GITHUB_USER ), esc_html( self::GITHUB_USER ) );
		$res->homepage     = sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO );
		$res->requires     = self::REQUIRES_WP;
		$res->tested       = get_bloginfo( 'version' );
		$res->requires_php = self::REQUIRES_PHP;
		$res->download_link = self::get_plugin_info_download_link( $release_data );
		$res->banners      = array();
		$res->icons        = array();

		if ( $release_data && ! empty( $release_data['published_at'] ) ) {
			$res->last_updated = (string) $release_data['published_at'];
		}

		$readme = self::parse_readme();

		$res->sections = array(
			'description' => ! empty( $readme['description'] )
				? $readme['description']
				: '<p>' . esc_html( self::PLUGIN_DESCRIPTION ) . '</p>',
		);

		if ( ! empty( $readme['installation'] ) ) {
			$res->sections['installation'] = $readme['installation'];
		}

		if ( ! empty( $readme['faq'] ) ) {
			$res->sections['faq'] = $readme['faq'];
		}

		$changelog_html = '';
		if ( $release_data && ! empty( $release_data['body'] ) && version_compare( $installed_version, $version, '<' ) ) {
			$changelog_html .= '<h4>' . esc_html( $version ) . '</h4>' . self::markdown_to_html( (string) $release_data['body'] );
		}

		if ( ! empty( $readme['changelog'] ) ) {
			$changelog_html .= $readme['changelog'];
		}

		$res->sections['changelog'] = '' !== $changelog_html
			? $changelog_html
			: sprintf(
				'<p>%s</p>',
				sprintf(
					wp_kses_post( __( 'See <a href="%s" target="_blank" rel="noopener noreferrer">GitHub releases</a> for changelog.', self::TEXT_DOMAIN ) ),
					esc_url( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/releases' )
				)
			);

		return $res;
	}

	public static function plugin_info_css(): void {
		if ( ! isset( $_GET['plugin'], $_GET['tab'] ) ) {
			return;
		}

		$tab    = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
		$plugin = sanitize_text_field( wp_unslash( $_GET['plugin'] ) );
		if ( 'plugin-information' !== $tab || self::PLUGIN_SLUG !== $plugin ) {
			return;
		}

		$pattern_css = '--s: 27px;'
			. '--c1: #b2b2b2;'
			. '--c2: #ffffff;'
			. '--c3: #d9d9d9;'
			. '--_g: var(--c3) 0 120deg, #0000 0;';

		$pattern_bg = 'conic-gradient(from -60deg at 50% calc(100%/3), var(--_g)),'
			. 'conic-gradient(from 120deg at 50% calc(200%/3), var(--_g)),'
			. 'conic-gradient(from 60deg at calc(200%/3), var(--c3) 60deg, var(--c2) 0 120deg, #0000 0),'
			. 'conic-gradient(from 180deg at calc(100%/3), var(--c1) 60deg, var(--_g)),'
			. 'linear-gradient(90deg, var(--c1) calc(100%/6), var(--c2) 0 50%, var(--c1) 0 calc(500%/6), var(--c2) 0)';

		echo '<style>'
			. '#plugin-information-title.with-banner {'
			. $pattern_css
			. 'background:' . $pattern_bg . ' !important;'
			. 'background-size:calc(1.732 * var(--s)) var(--s) !important;'
			. '}'
			. '#plugin-information-title.with-banner h2 {'
			. 'position:relative;display:inline-block;max-width:100%;padding:0 15px;margin-top:174px;'
			. 'font-family:"Helvetica Neue",sans-serif;font-size:30px;line-height:1.68;color:#fff;'
			. 'background:rgba(29,35,39,.9);text-shadow:0 1px 3px rgba(0,0,0,.4);'
			. 'box-shadow:0 0 30px rgba(255,255,255,.1);border-radius:8px;box-sizing:border-box;'
			. '}'
			. '#section-holder .section h2{margin:1.5em 0 .5em;clear:none;}'
			. '#section-holder .section h3{margin:1.5em 0 .5em;}'
			. '#section-holder .section > :first-child{margin-top:0;}'
			. '.md-table{display:table;width:100%;border-collapse:collapse;margin:1em 0;font-size:13px;}'
			. '.md-tr{display:table-row;}'
			. '.md-tr > span{display:table-cell;padding:6px 10px;border:1px solid #ddd;vertical-align:top;}'
			. '.md-th > span{font-weight:600;background:#f5f5f5;}'
			. '</style>';

		$requires_gf_html = sprintf(
			'<strong>%1$s</strong> %2$s',
			esc_html__( 'Requires Gravity Forms:', self::TEXT_DOMAIN ),
			esc_html( sprintf( __( '%s or higher', self::TEXT_DOMAIN ), self::REQUIRES_GF ) )
		);

		echo '<script>'
			. 'document.addEventListener("DOMContentLoaded",function(){'
			. 'var title=document.getElementById("plugin-information-title");'
			. 'if(title){title.classList.add("with-banner");}'
			. 'var list=document.querySelector(".fyi ul");'
			. 'if(!list){return;}'
			. 'var li=document.createElement("li");'
			. 'li.innerHTML=' . wp_json_encode( $requires_gf_html ) . ';'
			. 'list.appendChild(li);'
			. '});'
			. '</script>';
	}

	private static function parse_readme(): array {
		$readme_path = WP_PLUGIN_DIR . '/' . dirname( self::PLUGIN_FILE ) . '/README.md';
		if ( ! file_exists( $readme_path ) ) {
			return array();
		}

		$content = file_get_contents( $readme_path );
		if ( false === $content ) {
			return array();
		}

		$content = preg_replace( '/^#\s+[^\n]+\n*/m', '', $content, 1 );

		$utility_sections = array(
			'changelog',
			'requirements',
			'installation',
			'faq',
			'project structure',
			'acknowledgements',
			'license',
			'contributing',
			'security',
		);

		$parts        = preg_split( '/^##\s+/m', $content );
		$description  = trim( $parts[0] ?? '' );
		$installation = '';
		$faq          = '';
		$changelog    = '';

		for ( $index = 1, $count = count( $parts ); $index < $count; $index++ ) {
			$lines = explode( "\n", $parts[ $index ], 2 );
			$title = strtolower( trim( $lines[0] ) );
			$body  = trim( $lines[1] ?? '' );

			if ( 'installation' === $title ) {
				$installation .= $body . "\n\n";
			} elseif ( 'faq' === $title ) {
				$faq .= $body . "\n\n";
			} elseif ( 'changelog' === $title ) {
				$changelog .= $body . "\n\n";
			} elseif ( ! in_array( $title, $utility_sections, true ) ) {
				$description .= "\n\n## " . trim( $lines[0] ) . "\n" . $body;
			}
		}

		return array(
			'description'  => self::markdown_to_html( trim( $description ) ),
			'installation' => self::markdown_to_html( trim( $installation ) ),
			'faq'          => self::markdown_to_html( trim( $faq ) ),
			'changelog'    => self::markdown_to_html( trim( $changelog ) ),
		);
	}

	private static function markdown_to_html( string $markdown ): string {
		if ( '' === $markdown ) {
			return '';
		}

		$markdown = preg_replace( '/!\[[^\]]*\]\([^\)]+\)/', '', $markdown );
		$markdown = preg_replace( '/<p\b[^>]*>\s*(?:(?:<a\b[^>]*>\s*)?<img\b[^>]*>\s*(?:<\/a>\s*)?)+<\/p>\s*/is', '', $markdown );
		$markdown = preg_replace( '/(?:<a\b[^>]*>\s*)?<img\b[^>]*>\s*(?:<\/a>)?/i', '', $markdown );

		if ( ! class_exists( 'Parsedown' ) ) {
			require_once __DIR__ . '/Parsedown.php';
		}

		$parsedown = new Parsedown();
		$parsedown->setSafeMode( true );
		$html = $parsedown->text( $markdown );

		return self::tables_to_divs( $html );
	}

	private static function tables_to_divs( string $html ): string {
		return (string) preg_replace_callback(
			'/<table>(.*?)<\/table>/s',
			static function ( array $matches ): string {
				$table_html = $matches[1];
				$output     = '<div class="md-table">';

				preg_match_all( '/<tr>(.*?)<\/tr>/s', $table_html, $rows );
				foreach ( $rows[1] as $index => $row_content ) {
					$is_header = 0 === $index && false !== strpos( $table_html, '<thead>' );
					$row_class = $is_header ? 'md-tr md-th' : 'md-tr';

					preg_match_all( '/<t[hd][^>]*>(.*?)<\/t[hd]>/s', $row_content, $cells );

					$output .= '<div class="' . $row_class . '">';
					foreach ( $cells[1] as $cell ) {
						$output .= '<span>' . $cell . '</span>';
					}
					$output .= '</div>';
				}

				$output .= '</div>';

				return $output;
			},
			$html
		);
	}

	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $upgrader );
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || self::PLUGIN_FILE !== $hook_extra['plugin'] ) {
			return $source;
		}

		$correct_folder = dirname( self::PLUGIN_FILE );
		$source_folder  = basename( untrailingslashit( $source ) );
		if ( $source_folder === $correct_folder ) {
			return $source;
		}

		$new_source = trailingslashit( $remote_source ) . $correct_folder . '/';

		if ( $wp_filesystem && $wp_filesystem->move( $source, $new_source ) ) {
			return $new_source;
		}

		if ( $wp_filesystem && $wp_filesystem->copy( $source, $new_source, true ) && $wp_filesystem->delete( $source, true ) ) {
			return $new_source;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '%s updater: failed to rename update folder from %s to %s', self::PLUGIN_NAME, $source, $new_source ) );
		}

		return new WP_Error(
			'rename_failed',
			__( 'Unable to rename the update folder. Please retry or update manually.', self::TEXT_DOMAIN )
		);
	}
}

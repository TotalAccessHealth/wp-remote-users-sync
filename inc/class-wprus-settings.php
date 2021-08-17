<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Wprus_Settings {
	const DEFAULT_TOKEN_EXPIRY_LENGTH = HOUR_IN_SECONDS / 2;
	const DEFAULT_MIN_LOG             = 100;

	public static $actions;

	protected static $settings;
	protected static $settings_page_id = 'settings_page_wprus';

	protected $aes_key;
	protected $hmac_key;
	protected $sites;
	protected static $plugin_basefile;

	protected static $tables = [];

	public function __construct( $init_hooks = false, string $plugin_basefile = null ) {
		$this->load_textdomain();
		self::$actions = apply_filters(
			'wprus_actions',
			array(
				'login'    => __( 'Login', 'wprus' ),
				'logout'   => __( 'Logout', 'wprus' ),
				'create'   => __( 'Create', 'wprus' ),
				'update'   => __( 'Update', 'wprus' ),
				'delete'   => __( 'Delete', 'wprus' ),
				'password' => __( 'Password', 'wprus' ),
				'role'     => __( 'Roles', 'wprus' ),
				'meta'     => __( 'Metadata', 'wprus' ),
			)
		);

		if ( $init_hooks ) {
			add_action( 'init', array( $this, 'load_textdomain' ), PHP_INT_MIN - 10, 0 );
			add_action( 'init', array( $this, 'set_cache_policy' ), PHP_INT_MIN - 10, 0 );
			add_action( 'admin_menu', array( $this, 'plugin_options_menu_main' ), 10, 0 );
			add_action( 'add_meta_boxes', array( $this, 'add_settings_meta_boxes' ), 10, 0 );

			add_filter( 'pre_update_option_wprus', array( $this, 'require_flush' ), 10, 2 );
			add_filter( 'wprus_settings', array( $this, 'sanitize_settings' ), 10, 1 );
			add_filter( 'plugin_action_links_wp-remote-users-sync/wprus.php', array( $this, 'plugin_action_links' ), 10, 1 );
		}

		self::$settings = self::get_options();
		self::$plugin_basefile = $plugin_basefile; //\plugin_basename( __FILE__ );
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	public static function get_options() {
		self::$settings = get_option( 'wprus' );
		self::$settings = apply_filters( 'wprus_settings', self::$settings );

		return self::$settings;
	}

	public static function get_option( $key, $default = false ) {

		if ( ! $default && method_exists( get_class(), 'get_default_' . $key . '_option' ) ) {
			$default = call_user_func( array( get_class(), 'get_default_' . $key . '_option' ) );
		}

		$options = self::$settings;
		$value   = isset( $options[ $key ] ) ? $options[ $key ] : $default;

		return apply_filters( 'wprus_option', $value, $key );
	}

	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . admin_url( 'options-general.php?page=wprus' ) . '">' . __( 'Settings' ) . '</a>';

		return $links;
	}

	public function require_flush( $settings, $old_settings ) {
		set_transient( 'wprus_flush', 1, 60 );
		wp_cache_flush();

		return $settings;
	}

	public function set_cache_policy() {
		wp_cache_add_non_persistent_groups( 'wprus' );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wprus', false, 'wp-remote-users-sync/languages' );
	}

	public function validate() {
		$encryption = self::get_option( 'encryption' );

		if ( empty( $encryption ) || empty( $encryption['hmac_key'] ) || empty( $encryption['aes_key'] ) ) {
			$error  = '<ul>';
			$error .= ( empty( $this->aes_key ) ) ? '<li>' . __( 'Missing Encryption Key', 'wprus' ) . '</li>' : '';
			$error .= ( empty( $this->hmac_key ) ) ? '<li>' . __( 'Missing Authentication Key', 'wprus' ) . '</li>' : '';
			$error .= '</ul>';

			$this->error = $error;

			add_action( 'admin_notices', array( $this, 'missing_config' ) );

			return false;
		}

		return apply_filters( 'wprus_settings_valid', true, self::$settings );
	}

	public function missing_config() {
		$href    = admin_url( 'options-general.php?page=wprus' );
		$link    = ' <a href="' . $href . '">' . __( 'Edit configuration', 'wprus' ) . '</a>';
		$class   = 'notice notice-error is-dismissible';
		$message = __( 'WP Remote Users Sync is not ready. ', 'wprus' );

		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message . $link . $this->error ); // @codingStandardsIgnoreLine
	}

	public function plugin_options_menu_main() {
		register_setting(
			'wprus',
			'wprus',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		$title         = __( 'WP Remote Users Sync', 'wprus' );
		$capability    = 'manage_options';
		$menu_slug     = 'wprus';
		$parent_slug   = 'options-general.php';
		$callback      = array( $this, 'plugin_main_page' );
		$page_hook_id  = self::$settings_page_id;
		$settings_page = add_submenu_page( $parent_slug, $title, $title, $capability, $menu_slug, $callback );

		if ( ! empty( $settings_page ) ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 10, 1 );

			add_filter( 'screen_layout_columns', array( $this, 'screen_layout_column' ), 10, 2 );
		}
	}

	public function admin_enqueue_scripts( $hook_suffix ) {
		$page_hook_id = self::$settings_page_id;

		if ( $hook_suffix === $page_hook_id ) {
			$locale            = get_locale();
			$long_locales_list = array(
				'pt-BR',
				'sr-Cyrl',
				'zh-CN',
				'zh-TW',
			);

			if ( function_exists( 'pll_current_language' ) ) {
				$locale = pll_current_language();
			} elseif ( has_filter( 'wpml_current_language' ) ) {
				$locale = apply_filters( 'wpml_current_language', null );
			}

			if ( ! in_array( $locale, $long_locales_list, true ) && 2 < strlen( $locale ) ) {
				$locale = substr( $locale, 0, 1 );
			}

			$debug         = apply_filters( 'wprus_debug', WP_DEBUG );
			$user          = wp_get_current_user();
			$styles        = array(
				'lib/select2/select2',
				'admin/main',
			);
			$scripts       = array(
				'lib/select2/select2.min',
				'lib/select2/i18n/' . $locale,
				'admin/main',
			);
			$script_params = apply_filters(
				'wprus_js_parameters',
				array(
					'ajax_url'               => admin_url( 'admin-ajax.php' ),
					'download_url'           => trailingslashit( home_url( 'wprus_download' ) ),
					'delete_site_confirm'    => __( 'Are you sure you want to remove this site? It will only take effect after saving the settings.', 'wprus' ),
					'undefined_error'        => __( 'An undefined error occured - please visit the permalinks settings page and try again.', 'wprus' ),
					'http_required'          => __( "The Remote Site address must start with \"http\".\nIdeally, only secure websites (address starting with \"https\") should be connected together.", 'wprus' ),
					'invalid_file_name'      => __( 'Error: invalid file name. Please use a file exported with WP Remote Users Sync.', 'wprus' ),
					'invalid_file'           => __( 'Error: invalid file. Please use a non-empty file exported with WP Remote Users Sync.', 'wprus' ),
					'undefined_import_error' => __( 'Error: the server hung up unexpectedly. Please try to import users in smaller batches.', 'wprus' ),
					'username'               => $user->user_login,
					'debug'                  => $debug,
					'locale'                 => $locale,
				)
			);

			foreach ( $styles as $index => $style ) {
				$is_lib  = ( false !== strpos( $style, 'lib/' ) );
				$css_ext = ( $debug || $is_lib ) ? '.css' : '.min.css';
				$version = filemtime( WPRUS_PLUGIN_PATH . 'css/' . $style . $css_ext );
				$key     = 'wprus-' . $style . '-style';

				wp_enqueue_style( $key, WPRUS_PLUGIN_URL . 'css/' . $style . $css_ext, array(), $version );
			}

			foreach ( $scripts as $script ) {
				$key     = 'wprus-' . $script . '-script';
				$is_lib  = ( false !== strpos( $script, 'lib/' ) );
				$js_ext  = ( $debug || $is_lib ) ? '.js' : '.min.js';

				if ( ! file_exists( WPRUS_PLUGIN_PATH . 'js/' . $script . $js_ext ) ) {

					continue;
				}

				$version = filemtime( WPRUS_PLUGIN_PATH . 'js/' . $script . $js_ext );

				wp_enqueue_script( $key, WPRUS_PLUGIN_URL . 'js/' . $script . $js_ext, array( 'jquery' ), $version, true );

				if ( ! $is_lib ) {
					wp_localize_script( $key, 'WPRUS', $script_params );
				}
			}

			wp_enqueue_script( 'common' );
			wp_enqueue_script( 'wp-lists' );
			wp_enqueue_script( 'postbox' );
		}
	}

	public function screen_layout_column( $columns, $screen ) {
		$page_hook_id = self::$settings_page_id;

		if ( $screen === $page_hook_id ) {
			$columns[ $page_hook_id ] = 2;
		}

		return $columns;
	}

	public function add_settings_meta_boxes() {
		$page_hook_id = self::$settings_page_id;
		$sites        = $this->get_sites();
		$meta_keys    = $this->get_user_meta_keys();
		$roles        = $this->get_roles();
		$metaboxes    = array(
			'submitdiv'       => array(
				'title'    => __( 'Save Settings', 'wprus' ),
				'callback' => 'get_submit_metabox',
				'position' => 'side',
				'priority' => 'high',
				'data'     => null,
			),
			'site_add'        => array(
				'title'    => __( 'Add Remote Site', 'wprus' ),
				'callback' => 'get_add_site_metabox',
				'position' => 'side',
				'priority' => 'default',
				'data'     => null,
			),
			'encryption'      => array(
				'title'    => __( 'Authentication & Encryption', 'wprus' ),
				'callback' => 'get_encryption_metabox',
				'position' => 'normal',
				'priority' => 'high',
				'data'     => null,
			),
			'browser_support' => array(
				'title'    => __( 'Browser Support', 'wprus' ),
				'callback' => 'get_browser_support_metabox',
				'position' => 'normal',
				'priority' => 'high',
				'data'     => null,
			),
			'ip_whitelist'    => array(
				'title'    => __( 'IP Whitelist', 'wprus' ),
				'callback' => 'get_ip_whitelist_metabox',
				'position' => 'side',
				'priority' => 'default',
				'data'     => null,
			),
			'logs'            => array(
				'title'    => __( 'Logs', 'wprus' ),
				'callback' => 'get_logs_metabox',
				'position' => 'normal',
				'priority' => 'high',
				'data'     => null,
			),
			'site_template'   => array(
				'title'    => '----',
				'callback' => 'get_site_metabox_template',
				'position' => 'normal',
				'priority' => 'default',
				'data'     => array(
					'meta_keys' => $meta_keys,
					'roles'     => $roles,
				),
			),
			'export'          => array(
				'title'    => __( 'Export Users', 'wprus' ),
				'callback' => 'get_export_metabox_template',
				'position' => 'normal',
				'priority' => 'default',
				'data'     => array(
					'meta_keys' => $meta_keys,
					'roles'     => $roles,
				),
			),
			'import'          => array(
				'title'    => __( 'Import Users', 'wprus' ),
				'callback' => 'get_import_metabox_template',
				'position' => 'normal',
				'priority' => 'default',
				'data'     => array(
					'meta_keys' => $meta_keys,
					'roles'     => $roles,
				),
			),
		);
		$metaboxes    = apply_filters( 'wprus_settings_metaboxes', $metaboxes );

		foreach ( $metaboxes as $metabox_id => $metabox ) {
			add_meta_box(
				$metabox_id,
				$metabox['title'],
				array( $this, $metabox['callback'] ),
				$page_hook_id,
				$metabox['position'],
				$metabox['priority'],
				$metabox['data']
			);
		}

		if ( ! empty( $sites ) ) {
			$index = 0;

			foreach ( $sites as $key => $site ) {
				$index++;

				add_meta_box(
					'site_' . $index,
					$site['url'],
					array( $this, 'get_site_metabox' ),
					$page_hook_id,
					'normal',
					'high',
					array(
						'site_id'   => $index,
						'site'      => $site,
						'meta_keys' => $meta_keys,
						'roles'     => $roles,
					)
				);
			}
		}

	}

	public function get_submit_metabox() {
		ob_start();

		include apply_filters(
			'wprus_template_submit-settings-metabox', // @codingStandardsIgnoreLine
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/submit-settings-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_add_site_metabox() {
		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_add-site-metabox',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/add-site-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_encryption_metabox() {
		$encryption_settings = self::get_option( 'encryption' );

		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_encryption-metabox',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/encryption-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_browser_support_metabox() {
		$browser_support_settings = self::get_option( 'browser_support' );

		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_browser-support-metabox',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/browser-support-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_ip_whitelist_metabox() {
		$ips = self::get_option( 'ip_whitelist' );

		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_ip-whitelist-metabox',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/ip-whitelist-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_logs_metabox() {
		$num_logs      = Wprus_Logger::get_logs_count();
		$logs          = Wprus_Logger::get_logs();
		$logs_settings = self::get_option(
			'logs',
			array(
				'enable'  => false,
				'min_num' => self::DEFAULT_MIN_LOG,
			)
		);

		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_logs-metabox',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/logs-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_site_metabox( $page_hook_id, $data ) {
		$site_id   = $data['args']['site_id'];
		$site      = $data['args']['site'];
		$labels    = self::$actions;
		$meta_keys = $data['args']['meta_keys'];
		$roles     = $data['args']['roles'];

		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_site-metabox',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/site-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_site_metabox_template( $page_hook_id, $data ) {
		$labels    = self::$actions;
		$meta_keys = $data['args']['meta_keys'];
		$roles     = $data['args']['roles'];

		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_site-metabox-template',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/site-metabox-template.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_export_metabox_template( $page_hook_id, $data ) {
		$meta_keys = $data['args']['meta_keys'];
		$roles     = $data['args']['roles'];

		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_export-metabox',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/export-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function get_import_metabox_template( $page_hook_id, $data ) {
		$meta_keys = $data['args']['meta_keys'];
		$roles     = $data['args']['roles'];

		ob_start();

		include apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_import-metabox',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/import-metabox.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function plugin_main_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sorry, you are not allowed to access this page.', 'wprus' ) ); // @codingStandardsIgnoreLine
		}

		global $hook_suffix;

		do_action( 'wprus_settings_page_init' );
		do_action( 'add_meta_boxes', $hook_suffix, null );

		ob_start();

		require_once apply_filters( // @codingStandardsIgnoreLine
			'wprus_template_main-settings-page',
			WPRUS_PLUGIN_PATH . 'inc/templates/admin/main-settings-page.php'
		);

		echo ob_get_clean(); // @codingStandardsIgnoreLine
	}

	public function sanitize_settings( $settings ) {
		$settings = ( is_array( $settings ) ) ? $settings : array();
		$settings = $this->sanitize_encryption_settings( $settings );
		$settings = $this->sanitize_logs_settings( $settings );
		$settings = $this->sanitize_sites_settings( $settings );
		$settings = $this->sanitize_browser_support_settings( $settings );

		return apply_filters( 'wprus_sanitize_settings', $settings );
	}

	public function get_sites( $action = false, $direction = false ) {

		if ( $direction && ! in_array( $direction, array( 'incoming', 'outgoing' ), true ) ) {

			return array();
		}

		if ( empty( $this->sites ) ) {
			$this->sites = self::get_option( 'sites' );
		}

		if ( $action && $direction ) {
			$sites = array();

			foreach ( $this->sites as $site ) {

				if (
					isset( $site[ $direction . '_actions' ][ $action ] ) &&
					$site[ $direction . '_actions' ][ $action ]
				) {
					$sites[] = $site;
				}
			}
		} else {
			$sites = $this->sites;
		}

		return $sites;
	}

	public function get_site( $url, $action = false, $direction = false ) {
		$sites = $this->get_sites( $action, $direction );

		if ( ! empty( $sites ) ) {

			foreach ( $sites as $site ) {

				if ( trailingslashit( $url ) === trailingslashit( $site['url'] ) ) {

					return $site;
				}
			}
		}

		return false;
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected static function get_default_encryption_option() {
		$default = array(
			'token_expiry' => self::DEFAULT_TOKEN_EXPIRY_LENGTH,
			'hmac_key'     => '',
			'aes_key'      => '',
		);

		return $default;
	}

	protected static function get_default_logs_option() {
		$default = array(
			'enable'  => false,
			'min_num' => self::DEFAULT_MIN_LOG,
		);

		return $default;
	}

	protected static function get_default_sites_option() {
		$default = array();

		return $default;
	}

	protected static function get_default_browser_support_option() {
		$default = array(
			'force_login_logout_strict'         => false,
			'force_disable_login_logout_strict' => false,
			'silent_login_logout_strict'        => false,
		);

		return $default;
	}

	protected function sanitize_encryption_settings( $settings ) {

		if ( ! isset( $settings['encryption'] ) ) {
			$settings['encryption'] = array();
		}

		if ( isset( $settings['encryption'] ) ) {

			if ( empty( $settings['encryption']['hmac_key'] ) ) {
				$settings['encryption']['hmac_key'] = '';
			}

			if ( empty( $settings['encryption']['aes_key'] ) ) {
				$settings['encryption']['aes_key'] = '';
			}

			if ( empty( $settings['encryption']['token_expiry'] ) ) {
				$settings['encryption']['token_expiry'] = self::DEFAULT_TOKEN_EXPIRY_LENGTH;
			} else {
				$settings['encryption']['token_expiry'] = absint( $settings['encryption']['token_expiry'] );
			}
		}

		if ( ! isset( $settings['encryption'] ) ) {
			$settings['encryption'] = array(
				'token_expiry' => self::DEFAULT_TOKEN_EXPIRY_LENGTH,
				'hmac_key'     => '',
				'aes_key'      => '',
			);
		}

		return $settings;
	}

	protected function sanitize_sites_settings( $settings ) {

		if ( ! isset( $settings['sites'] ) ) {
			$settings['sites'] = array();
		}

		if ( ! empty( $settings['sites'] ) ) {

			foreach ( $settings['sites'] as $site_id => $site ) {

				if ( ! isset( $site['url'] ) ) {
					unset( $settings['sites'][ $site_id ] );

					continue;
				}

				if ( ! isset( $site['incoming_actions'] ) ) {
					$settings['sites'][ $site_id ]['incoming_actions'] = array();
				}

				if ( ! isset( $site['outgoing_actions'] ) ) {
					$settings['sites'][ $site_id ]['outgoing_actions'] = array();
				}

				if ( ! isset( $site['incoming_meta'] ) ) {
					$settings['sites'][ $site_id ]['incoming_meta'] = array();
				}

				if ( ! isset( $site['outgoing_meta'] ) ) {
					$settings['sites'][ $site_id ]['outgoing_meta'] = array();
				}

				if ( ! isset( $site['incoming_roles'] ) ) {
					$settings['sites'][ $site_id ]['incoming_roles'] = array();
				}

				if ( ! isset( $site['incoming_roles_merge'] ) ) {
					$settings['sites'][ $site_id ]['incoming_roles_merge'] = false;
				}

				if ( ! isset( $site['outgoing_roles'] ) ) {
					$settings['sites'][ $site_id ]['outgoing_roles'] = array();
				}

				$default_actions                                   = array_fill_keys(
					array_keys( self::$actions ),
					''
				);
				$settings['sites'][ $site_id ]['incoming_actions'] = array_merge(
					$default_actions,
					$settings['sites'][ $site_id ]['incoming_actions']
				);
				$settings['sites'][ $site_id ]['outgoing_actions'] = array_merge(
					$default_actions,
					$settings['sites'][ $site_id ]['outgoing_actions']
				);
			}
		}

		return $settings;
	}

	protected function sanitize_logs_settings( $settings ) {

		if ( isset( $settings['logs'] ) ) {

			if ( empty( $settings['logs']['enable'] ) ) {
				$settings['logs']['enable'] = false;
			}

			if ( empty( $settings['logs']['min_num'] ) ) {
				$settings['logs']['min_num'] = self::DEFAULT_MIN_LOG;
			}
		}

		if ( ! isset( $settings['logs'] ) ) {
			$settings['logs'] = array(
				'enable'  => false,
				'min_num' => self::DEFAULT_MIN_LOG,
			);
		}

		return $settings;
	}

	protected function sanitize_browser_support_settings( $settings ) {

		if ( isset( $settings['browser_support'] ) ) {

			if ( empty( $settings['browser_support']['force_login_logout_strict'] ) ) {
				$settings['browser_support']['force_login_logout_strict'] = false;
			}

			if ( empty( $settings['browser_support']['force_disable_login_logout_strict'] ) ) {
				$settings['browser_support']['force_disable_login_logout_strict'] = false;
			}

			if ( empty( $settings['browser_support']['silent_login_logout_strict'] ) ) {
				$settings['browser_support']['silent_login_logout_strict'] = false;
			}
		}

		if ( ! isset( $settings['browser_support'] ) ) {
			$settings['browser_support'] = array(
				'force_login_logout_strict'         => false,
				'force_disable_login_logout_strict' => false,
				'silent_login_logout_strict'        => false,
			);
		}

		return $settings;
	}

	protected function get_excluded_meta() {
		$excluded = array(
			'user_url',
			'user_email',
			'display_name',
			'nickname',
			'first_name',
			'last_name',
			'description',
			'primary_blog',
			'use_ssl',
			'comment_shortcuts',
			'admin_color',
			'rich_editing',
			'syntax_highlighting',
			'show_admin_bar_front',
			'locale',
			'community-events-location',
			'show_try_gutenberg_panel',
			'closedpostboxes_post',
			'metaboxhidden_post',
			'closedpostboxes_dashboard',
			'metaboxhidden_dashboard',
			'dismissed_wp_pointers',
			'session_tokens',
			'source_domain',
		);

		return apply_filters( 'wprus_excluded_meta_keys', $excluded );
	}

	protected function get_excluded_meta_like() {
		$excluded = array(
			'%capabilities',
			'%user_level',
			'%user-settings',
			'%user-settings-time',
			'%dashboard_quick_press_last_post_id',
			'wprus%',
			'%wprus',
		);

		return apply_filters( 'wprus_excluded_meta_keys_like', $excluded );
	}

	protected function get_user_meta_keys() {
		global $wpdb;

		$meta_keys = wp_cache_get( 'wprus_meta_keys', 'wprus' );

		$_table_usermeta = Wprus_Settings::get_wpdb_table( 'usermeta' );

		if ( ! $meta_keys ) {
			$exclude      = $this->get_excluded_meta();
			$exclude_like = $this->get_excluded_meta_like();

			$sql = "
				SELECT DISTINCT meta_key
				FROM {$_table_usermeta} m
				WHERE m.meta_key NOT IN (" . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ')
				AND m.meta_key NOT LIKE 
				' . implode( ' AND m.meta_key NOT LIKE ', array_fill( 0, count( $exclude_like ), '%s' ) ) . '
				ORDER BY m.meta_key ASC
			;';

			$params    = array_merge( $exclude, $exclude_like );
			$query     = $wpdb->prepare( $sql, $params ); // @codingStandardsIgnoreLine
			$meta_keys = $wpdb->get_col( $query ); // @codingStandardsIgnoreLine

			wp_cache_set( 'wprus_meta_keys', $meta_keys, 'wprus' );
		}

		return $meta_keys;
	}

	protected function get_roles() {
		global $wp_roles;

		$roles = array_keys( $wp_roles->roles );

		return $roles;
	}

	/**
	 * Get WP Table name, adding correct prefix, depending if plugin is on multisite and network active or not
	 * 
	 * @since	1.2.8.1
	 * @param	string	$table
	 * @return	string
	*/
	public static function get_wpdb_table( string $table = null ):?string
	{

		if( is_null( $table ) ){

			return null;

		}

		// simple cache --> if a table name is already created, return stored value ##
		if( isset( self::$tables[$table] ) ){

			// error_log( 'Returning cache for table: '.$table.' --> '. self::$tables[$table] );

			return self::$tables[$table];

		}

		// if multisite, we need to know if the plugin is network active or install active ##
		// error_log( 'basefile: '.self::$plugin_basefile );
		// error_log( 'table: '.$table );

		// cache a check if the plugin is network active ##
		$_is_network_active = \is_plugin_active_for_network( self::$plugin_basefile );

		// import WP database object ##
		global $wpdb;

		// list tables which always use the base_prefix
		$_use_base_prefix = [
			'users',
			'usermeta'
		];

		// @todo - apply_filters ##

		if(
			\is_multisite() // running on a network
		){

			// error_log( 'This is a WP Network' );

			if( $_is_network_active ){

				// error_log( 'Plugin is Network active' );

				// filter in wprus nonce and log table, as these need to use base_prefix if network active ##
				$_use_base_prefix = array_merge( $_use_base_prefix, [
					'wprus_logs',
					'wprus_nonce'
				]);

				// __log( $_use_base_prefix );

				// @todo - apply_filters.. again ##

				if( in_array( $table, $_use_base_prefix ) ){

					// error_log( 'Table: '.$table.' should use base_prefix' );

					$_table = $wpdb->base_prefix.$table;

				} else {
 
					$_table = $wpdb->prefix.$table;

				}

			} else {

				// error_log( 'Plugin is NOT Network active' );

				if( in_array( $table, $_use_base_prefix ) ){

					$_table = $wpdb->base_prefix.$table;

				} else {

					$_table = $wpdb->prefix.$table;

				}

			}

		} else {

			// error_log( 'This is NOT a WP Network - return with wpdb->prefix' );

			$_table = $wpdb->prefix.$table;

		}

		// error_log( 'final table: '.$_table );

		// cache ##
		self::$tables[$table] = $_table;

		// return ##
		return $_table;

	}

}

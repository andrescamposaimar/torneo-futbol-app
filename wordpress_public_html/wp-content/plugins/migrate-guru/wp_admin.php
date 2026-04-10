<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('MGWPAdmin')) :
class MGWPAdmin {
	public $settings;
	public $siteinfo;
	public $bvinfo;
	public $bvapi;

	function __construct($settings, $siteinfo, $bvapi = null) {
		$this->settings = $settings;
		$this->siteinfo = $siteinfo;
		$this->bvapi = $bvapi;
		$this->bvinfo = new MGInfo($this->settings);
	}

	public function mainUrl($_params = '') {
		if (function_exists('network_admin_url')) {
			return network_admin_url('admin.php?page='.$this->bvinfo->plugname.$_params);
		} else {
			return admin_url('admin.php?page='.$this->bvinfo->plugname.$_params);
		}
	}

	function removeAdminNotices() {
		if (MGHelper::getRawParam('REQUEST', 'page') === $this->bvinfo->plugname) {
			remove_all_actions('admin_notices');
			remove_all_actions('all_admin_notices');
		}
	}

	public function initHandler() {
		if (!current_user_can('activate_plugins'))
			return;

		if ($this->bvinfo->isActivateRedirectSet()) {
			$this->settings->updateOption($this->bvinfo->plug_redirect, 'no');
			if (!wp_doing_ajax()) {
				wp_redirect($this->mainUrl());
			}
		}
	}

	public function menu() {
		$brand = $this->bvinfo->getBrandInfo();
		if (!is_array($brand) || (!array_key_exists('hide', $brand) && !array_key_exists('hide_from_menu', $brand))) {
			$bname = $this->bvinfo->getBrandName();
			$icon = $this->bvinfo->getBrandIcon();
			add_menu_page($bname, $bname, 'manage_options', $this->bvinfo->plugname,
					array($this, 'adminPage'), plugins_url($icon,  __FILE__ ));
		}
	}

	public function hidePluginDetails($plugin_metas, $slug) {
		$brand = $this->bvinfo->getBrandInfo();
		$bvslug = $this->bvinfo->slug;

		if ($slug === $bvslug && is_array($brand) && array_key_exists('hide_plugin_details', $brand)) {
			foreach ($plugin_metas as $pluginKey => $pluginValue) {
				// phpcs:ignore WordPress.WP.I18n.MissingArgDomain
				if (strpos($pluginValue, sprintf('>%s<', __('View details')))) {
					unset($plugin_metas[$pluginKey]);
					break;
				}
			}
		}
		return $plugin_metas;
	}

	public function settingsLink($links, $file) {
		if ( $file == plugin_basename( dirname(__FILE__).'/migrateguru.php' ) ) {
			$FAQ = '<a href="https://migrateguru.freshdesk.com/support/solutions/33000046011" target="_blank">'.'FAQs'.'</a>';
			array_unshift( $links, $FAQ );
			// phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			$Support = '<a href="https://wordpress.org/support/plugin/migrate-guru" target="_blank">'.__( 'Support' ).'</a>';
			array_unshift( $links, $Support );
			$RateUs = '<a href="https://wordpress.org/support/plugin/migrate-guru/reviews/?filter=5#new-post" target="_blank">'.'Rate Us'.'</a>';
			array_unshift( $links, $RateUs );
			$MigrateLink = '<a href="'.$this->mainUrl().'">'.'Migrate'.'</a>';
			array_unshift( $links, $MigrateLink );
		}
		return $links;
	}

	public function mgsecAdminMenu($hook) {
		if ($hook === 'toplevel_page_migrateguru') {
			// Enqueue Google Fonts (Arimo, Montserrat, Happy Monkey)
			wp_enqueue_style('google-fonts-mg', 'https://fonts.googleapis.com/css2?family=Arimo:wght@400;500;600;700&family=Montserrat:wght@700&family=Happy+Monkey&display=swap', array(), null);
			
			$css_path = plugin_dir_path(__FILE__) . 'css/onboarding.css';
			$css_version = file_exists($css_path) ? filemtime($css_path) : $this->bvinfo->version;
			// Enqueue new onboarding styles (cache-busted by filemtime)
			wp_enqueue_style('mg-onboarding', plugins_url('css/onboarding.css', __FILE__), array(), $css_version);
			
			// Enqueue jQuery (WordPress core)
			wp_enqueue_script('jquery');
			
			$js_path = plugin_dir_path(__FILE__) . 'js/migrateguru-onboarding.js';
			$js_version = file_exists($js_path) ? filemtime($js_path) : $this->bvinfo->version;
			// Enqueue new onboarding JavaScript (cache-busted by filemtime)
			wp_enqueue_script('mg-onboarding-js', plugins_url('js/migrateguru-onboarding.js', __FILE__), array('jquery'), $js_version, true);
		}
	}

	public function getPluginLogo() {
		$brand = $this->bvinfo->getBrandInfo();
		if ($brand && array_key_exists('logo', $brand)) {
			return $brand['logo'];
		}
		return $this->bvinfo->logo;
	}

	public function getWebPage() {
		$brand = $this->bvinfo->getBrandInfo();
		if ($brand && array_key_exists('webpage', $brand)) {
			return $brand['webpage'];
		}
		return $this->bvinfo->webpage;
	}

	public function siteInfoTags() {
		require_once dirname( __FILE__ ) . '/recover.php';
		$secret = MGRecover::defaultSecret($this->settings);
		$public = MGAccount::getApiPublicKey($this->settings);
		$server_ip = MGHelper::getStringParamEscaped('SERVER', 'SERVER_ADDR', 'attr');
		$tags = "<input type='hidden' name='url' value='".esc_attr($this->siteinfo->wpurl())."'/>\n".
				"<input type='hidden' name='homeurl' value='".esc_attr($this->siteinfo->homeurl())."'/>\n".
				"<input type='hidden' name='siteurl' value='".esc_attr($this->siteinfo->siteurl())."'/>\n".
				"<input type='hidden' name='dbsig' value='".esc_attr($this->siteinfo->dbsig(false))."'/>\n".
				"<input type='hidden' name='plug' value='".esc_attr($this->bvinfo->plugname)."'/>\n".
				"<input type='hidden' name='adminurl' value='".esc_attr($this->mainUrl())."'/>\n".
				"<input type='hidden' name='bvversion' value='".esc_attr($this->bvinfo->version)."'/>\n".
	 			"<input type='hidden' name='serverip' value='".$server_ip."'/>\n".
				"<input type='hidden' name='abspath' value='".esc_attr(ABSPATH)."'/>\n".
				"<input type='hidden' name='secret' value='".esc_attr($secret)."'/>\n".
				"<input type='hidden' name='public' value='".esc_attr($public)."'/>\n";
		return $tags;
	}

	public function activateWarning() {
		global $hook_suffix;
		if (!MGAccount::isConfigured($this->settings) && $hook_suffix == 'index.php' ) {
?>
			<div id="message" class="updated" style="padding: 8px; font-size: 16px; background-color: #dff0d8">
						<a class="button-primary" href="<?php echo esc_url($this->mainUrl()); ?>">Activate Migrate Guru</a>
						&nbsp;&nbsp;&nbsp;<b>Almost Done:</b> Activate your Migrate Guru account to migrate your site.
			</div>
<?php
		}
	}

	public function adminPage() {
		require_once dirname( __FILE__ ) . '/admin/main_page.php';
	}

	public function initWhitelabel($plugins) {
		$slug = $this->bvinfo->slug;

		if (!is_array($plugins) || !isset($slug, $plugins)) {
			return $plugins;
		}

		$brand = $this->bvinfo->getBrandInfo();
		if (is_array($brand)) {
			if (array_key_exists('hide', $brand)) {
				unset($plugins[$slug]);
			} else {
				if (array_key_exists('name', $brand)) {
					$plugins[$slug]['Name'] = $brand['name'];
				}
				if (array_key_exists('title', $brand)) {
					$plugins[$slug]['Title'] = $brand['title'];
				}
				if (array_key_exists('description', $brand)) {
					$plugins[$slug]['Description'] = $brand['description'];
				}
				if (array_key_exists('authoruri', $brand)) {
					$plugins[$slug]['AuthorURI'] = $brand['authoruri'];
				}
				if (array_key_exists('author', $brand)) {
					$plugins[$slug]['Author'] = $brand['author'];
				}
				if (array_key_exists('authorname', $brand)) {
					$plugins[$slug]['AuthorName'] = $brand['authorname'];
				}
				if (array_key_exists('pluginuri', $brand)) {
					$plugins[$slug]['PluginURI'] = $brand['pluginuri'];
				}
			}
		}
		return $plugins;
	}

	private function validate_api_key($key) {
		if (empty($key)) {
			return new WP_Error('invalid_key', 'Migration key is required.');
		}

		$decoded = base64_decode($key, true);
		if ($decoded === false || $decoded === '') {
			return new WP_Error('invalid_key', 'Migration key is not properly encoded.');
		}

		$parts = explode(':', $decoded);
		if (count($parts) < 2) {
			return new WP_Error('invalid_key', 'Migration key appears to be incomplete.');
		}

		$version = array_shift($parts);
		if ($version !== 'v2') {
			return new WP_Error('invalid_key', 'This migration key is from an older plugin version. Install the latest MigrateGuru plugin on both sites and copy the key again.');
		}

		$payload = implode(':', $parts);
		$secret = '';
		$url = '';
		$plugname = '';

		$inner = explode(':', $payload, 3);
		if (count($inner) < 2) {
			return new WP_Error('invalid_key', 'Migration key appears to be incomplete.');
		}
		$secret = $inner[0];
		$encoded_url = $inner[1];
		$plugname = isset($inner[2]) ? $inner[2] : '';
		$url = base64_decode($encoded_url, true);
		if ($url === false || $url === '') {
			return new WP_Error('invalid_key', 'Migration key URL is invalid.');
		}

		if (strlen($secret) < 32) {
			return new WP_Error('invalid_key', 'Migration key secret is invalid.');
		}

		if (empty($url)) {
			return new WP_Error('invalid_key', 'Migration key URL is invalid.');
		}

		return array(
			'secret' => $secret,
			'url' => $url,
			'plugname' => $plugname
		);
	}
		/**
	 * AJAX Handler: Validate migration key
	 */
	public function ajaxValidateKey() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mg_onboarding_nonce')) {
			wp_send_json_error(array('message' => 'Security check failed.'));
			return;
		}

		// Get keys
		$destination_key = isset($_POST['destination_key']) ? sanitize_text_field(wp_unslash($_POST['destination_key'])) : '';
		$source_key = isset($_POST['source_key']) ? sanitize_text_field(wp_unslash($_POST['source_key'])) : '';

		if (empty($destination_key) || empty($source_key)) {
			wp_send_json_error(array('message' => 'Migration keys are required.'));
			return;
		}

		// Check if destination key matches source key (same site)
		if ($destination_key === $source_key) {
			wp_send_json_error(array('message' => 'You cannot use the same site key. Please enter the migration key from your destination site (the site you are migrating to).'));
			return;
		}

		$validation_result = $this->validate_api_key($destination_key);
		if (is_wp_error($validation_result)) {
			wp_send_json_error(array('message' => $validation_result->get_error_message()));
			return;
		}

		// Success
		wp_send_json_success(array(
			'message' => 'Keys validated successfully.',
			'destination_key' => $destination_key
		));
	}

	/**
	 * AJAX Handler: Initiate migration
	 */
	public function ajaxInitiateMigration() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mg_onboarding_nonce')) {
			wp_send_json_error(array('message' => 'Security check failed.'));
			return;
		}

		// Get form data
		$email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
		$destination_key = isset($_POST['destination_key']) ? sanitize_text_field(wp_unslash($_POST['destination_key'])) : '';
		$source_key = isset($_POST['source_key']) ? sanitize_text_field(wp_unslash($_POST['source_key'])) : '';
		$site_info = isset($_POST['site_info']) ? array_map('sanitize_text_field', wp_unslash($_POST['site_info'])) : array();

		// Validate email
		if (empty($email) || !is_email($email)) {
			wp_send_json_error(array('message' => 'Valid email address is required.'));
			return;
		}

		// Validate keys
		if (empty($destination_key) || empty($source_key)) {
			wp_send_json_error(array('message' => 'Migration keys are required.'));
			return;
		}

		// Check if destination key matches source key (same site)
		if ($destination_key === $source_key) {
			wp_send_json_error(array('message' => 'You cannot use the same site key. Please enter the migration key from your destination site (the site you are migrating to).'));
			return;
		}

		$validation_result = $this->validate_api_key($destination_key);
		if (is_wp_error($validation_result)) {
			wp_send_json_error(array('message' => $validation_result->get_error_message()));
			return;
		}

		// Prepare data for API call
		$api_data = array(
			'email' => $email,
			'bv_dest_migration_key' => $destination_key,
			'bv_src_migration_key' => $source_key
		);

		// Add site info to API data
		$api_data = array_merge($api_data, $site_info);

		// Make API call to key_based_migration endpoint
		$api_url = $this->bvinfo->appUrl() . '/migration/execute';

		// Use WordPress HTTP API directly if bvapi not available
		if ($this->bvapi && method_exists($this->bvapi, 'http_request')) {
			$response = $this->bvapi->http_request($api_url, $api_data);
		} else {
			// Fallback to wp_remote_post
			$response = wp_remote_post($api_url, array(
				'method' => 'POST',
				'timeout' => 15,
				'body' => $api_data
			));
		}

		// Check response
		if (is_wp_error($response)) {
			wp_send_json_error(array(
				'message' => 'Failed to connect to migration service: ' . $response->get_error_message()
			));
			return;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		if ($response_code === 200 && isset($response_data['message'])) {
			$success_data = array(
				'message' => $response_data['message']
			);
			// Include URL if present in response
			if (isset($response_data['url'])) {
				$success_data['url'] = esc_url_raw($response_data['url']);
			}
			wp_send_json_success($success_data);
		} else {
			// Handle both direct message and nested error.message structure
			$error_message = 'Migration initiation failed. Please try again.';
			
			if (isset($response_data['error']['message'])) {
				// Rails render_bad_request format: { "error": { "message": "..." } }
				$error_message = $response_data['error']['message'];
			} elseif (isset($response_data['message'])) {
				// Direct message format: { "message": "..." }
				$error_message = $response_data['message'];
			} elseif (isset($response_data['error']) && is_string($response_data['error'])) {
				// Simple error string: { "error": "..." }
				$error_message = $response_data['error'];
			}
			
			wp_send_json_error(array(
				'message' => $error_message
			));
		}
	}
}
endif;
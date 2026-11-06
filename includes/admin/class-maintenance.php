<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Common\Store as Common_Store;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\License\Store;
use Tonkatsutei\Gachi_Review_AI\Network_Setup;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Maintenance extends Screen
{
	public const ACTION_BACKUP = 'gachireai_maintenance_backup';
	public const ACTION_UPLOAD = 'gachireai_maintenance_upload';
	public const ACTION_APPLY = 'gachireai_maintenance_apply';
	private const NONCE_BACKUP = 'gachireai_maintenance_backup';
	private const NONCE_UPLOAD = 'gachireai_maintenance_upload';
	private const NONCE_APPLY = 'gachireai_maintenance_apply';
	private const MAP_NONE = 'none';
	private const FORMAT = 'gachi-review-ai-backup';
	private const FORMAT_VERSION = 2;
	private const MAX_UPLOAD_BYTES = 5242880;
	private const PAYLOAD_TRANSIENT_PREFIX = 'gachireai_restore_payload_';
	private const PAYLOAD_TTL = 1800;
	public const RESULT_ARG = 'gachireai_maintenance_result';
	private const BACKUP_SITES_FIELD = 'gachireai_backup_sites';
	private const RESTORE_FILE_FIELD = 'gachireai_restore_file';
	private const RESTORE_MAP_FIELD  = 'gachireai_restore_map';
	private const RESTORE_OVERWRITE_FIELD = 'gachireai_restore_overwrite';
	private const BACKUP_COMMON_FIELD = 'gachireai_backup_common';
	public const ACTION_RESET = 'gachireai_maintenance_reset';
	private const NONCE_RESET = 'gachireai_maintenance_reset';
	private const RESET_CONFIRM_FIELD = 'gachireai_reset_confirm';
	private const COMMON_FIELD = 'gachireai_common_mode';
	public const ACTION_NETWORK = 'gachireai_maintenance_network';
	private const NONCE_NETWORK = 'gachireai_maintenance_network';
	private const NETWORK_STEP_FIELD = 'gachireai_network_step';
	private const NETWORK_CONFIRM_FIELD = 'gachireai_network_confirm';
	private const NETWORK_ERROR_TRANSIENT = 'gachireai_network_error_';
	public const ACTION_INSTALL_PLUGIN = 'gachireai_maintenance_install_plugin';
	private const NONCE_INSTALL_PLUGIN = 'gachireai_maintenance_install_plugin';
	private const INSTALL_PLUGIN_FIELD = 'gachireai_install_plugin';
	private const PLUGIN_ERROR_TRANSIENT = 'gachireai_plugin_error_';
	public const ACTION_WP_SETTINGS = 'gachireai_maintenance_wp_settings';
	private const NONCE_WP_SETTINGS = 'gachireai_maintenance_wp_settings';
	private const WP_SETTINGS_ERROR_TRANSIENT = 'gachireai_wp_settings_error_';
	public const ACTION_INSTALL_THEME = 'gachireai_maintenance_install_theme';
	private const NONCE_INSTALL_THEME = 'gachireai_maintenance_install_theme';
	private const THEME_ERROR_TRANSIENT = 'gachireai_theme_error_';
	public const ACTION_THEME_SETTINGS = 'gachireai_maintenance_theme_settings';
	private const NONCE_THEME_SETTINGS = 'gachireai_maintenance_theme_settings';
	private const THEME_SETTINGS_ERROR_TRANSIENT = 'gachireai_theme_settings_error_';
	public function render(): void
	{
		if (! current_user_can($this->capability())) {
			return;
		}
		$this->render_template('admin/maintenance', $this->model());
	}
	public function handle_backup(): void
	{
		$this->require_manager(self::NONCE_BACKUP);
		$selected = isset($_POST[self::BACKUP_SITES_FIELD]) && is_array($_POST[self::BACKUP_SITES_FIELD])
			? array_map('absint', (array) wp_unslash($_POST[self::BACKUP_SITES_FIELD]))
			: array();
		$include_common = isset($_POST[self::BACKUP_COMMON_FIELD]) && '1' === sanitize_key(wp_unslash((string) $_POST[self::BACKUP_COMMON_FIELD]));
		$json = wp_json_encode(
			$this->export_payload($selected, $include_common),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if (! is_string($json) || '' === $json) {
			wp_die(
				esc_html__('バックアップを作成できませんでした。', GACHIREAI_TEXT_DOMAIN),
				esc_html__('gachi-review-ai', GACHIREAI_TEXT_DOMAIN),
				array('response' => 500)
			);
		}
		$filename = 'gachi-review-ai-backup-' . current_time('Ymd-His') . '.json';
		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . (string) strlen($json));
		echo $json; 
		exit;
	}
	private function require_manager(string $nonce_action): void
	{
		if (! current_user_can($this->is_common_request() ? 'manage_network' : 'manage_options')) {
			wp_die(
				esc_html__('この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN),
				esc_html__('gachi-review-ai', GACHIREAI_TEXT_DOMAIN),
				array('response' => 403)
			);
		}
		check_admin_referer($nonce_action);
	}
	public function handle_upload(): void
	{
		$this->require_manager(self::NONCE_UPLOAD);
		$payload = $this->read_upload();
		if (null === $payload) {
			$this->redirect('upload_error');
		}
		delete_transient(self::PAYLOAD_TRANSIENT_PREFIX . get_current_user_id());
		set_transient(
			self::PAYLOAD_TRANSIENT_PREFIX . get_current_user_id(),
			$payload,
			self::PAYLOAD_TTL
		);
		$this->redirect('mapping');
	}
	public function handle_apply(): void
	{
		$this->require_manager(self::NONCE_APPLY);
		$payload = $this->restore_payload();
		if (null === $payload) {
			$this->redirect('mapping_missing');
		}
		$map = isset($_POST[self::RESTORE_MAP_FIELD]) && is_array($_POST[self::RESTORE_MAP_FIELD])
			? (array) wp_unslash($_POST[self::RESTORE_MAP_FIELD])
			: array();
		$overwrite = isset($_POST[self::RESTORE_OVERWRITE_FIELD]) && '1' === sanitize_key(wp_unslash((string) $_POST[self::RESTORE_OVERWRITE_FIELD]));
		$result = $this->apply_backup($payload, $map, $overwrite);
		delete_transient(self::PAYLOAD_TRANSIENT_PREFIX . get_current_user_id());
		$this->redirect(
			'restored',
			array(
				'applied' => (string) $result['applied'],
				'skipped' => (string) $result['skipped'],
			)
		);
	}
	public function handle_reset(): void
	{
		$this->require_manager(self::NONCE_RESET);
		$confirmed = isset($_POST[self::RESET_CONFIRM_FIELD])
			&& '1' === sanitize_key(wp_unslash((string) $_POST[self::RESET_CONFIRM_FIELD]));
		if (! $confirmed) {
			$this->redirect('reset_unconfirmed');
		}
		if ($this->is_common_request()) {
			$this->reset_common();
			$this->record_setting_save(__('共通設定の初期化', GACHIREAI_TEXT_DOMAIN));
		} else {
			$this->reset_current_site();
			$this->record_setting_save(__('このサイトのデータの初期化', GACHIREAI_TEXT_DOMAIN));
		}
		$this->redirect('reset_done');
	}
	public function handle_network_setup(): void
	{
		$this->require_manager(self::NONCE_NETWORK);
		$confirmed = isset($_POST[self::NETWORK_CONFIRM_FIELD])
			&& '1' === sanitize_key(wp_unslash((string) $_POST[self::NETWORK_CONFIRM_FIELD]));
		if (! $confirmed) {
			$this->redirect('network_unconfirmed');
		}
		if (! Status::capabilities()->can_use_multisite()) {
			$this->redirect('network_not_pro');
		}
		$step = isset($_POST[self::NETWORK_STEP_FIELD])
			? sanitize_key(wp_unslash((string) $_POST[self::NETWORK_STEP_FIELD]))
			: '';
		$setup  = new Network_Setup();
		$result = 'network_error';
		try {
			if ('allow' === $step) {
				$setup->write_allow_multisite();
				$result = 'network_allow_done';
			} elseif ('apply' === $step) {
				$setup->write_constants_and_htaccess();
				$result = 'network_apply_done';
			} else {
				set_transient(
					self::NETWORK_ERROR_TRANSIENT . get_current_user_id(),
					esc_html__('手順の指定が不正です。', GACHIREAI_TEXT_DOMAIN),
					300
				);
			}
		} catch (\Throwable $e) {
			set_transient(
				self::NETWORK_ERROR_TRANSIENT . get_current_user_id(),
				mb_substr(trim($e->getMessage()), 0, 500),
				300
			);
		}
		$this->redirect($result);
	}
	public function handle_install_plugin(): void
	{
		$this->require_manager(self::NONCE_INSTALL_PLUGIN);
		if (! current_user_can('install_plugins')) {
			$this->redirect('plugin_no_permission');
		}
		$slug = isset($_POST[self::INSTALL_PLUGIN_FIELD])
			? sanitize_key(wp_unslash((string) $_POST[self::INSTALL_PLUGIN_FIELD]))
			: '';
		$definitions = $this->plugin_definitions();
		if (! isset($definitions[$slug])) {
			$this->redirect('plugin_invalid');
		}
		$result = 'plugin_error';
		try {
			$plugin_file = $this->install_and_activate_plugin($definitions[$slug]);
			$this->enable_plugin_auto_update($plugin_file);
			$updated = $this->update_plugin_if_available($plugin_file);
			$this->record_setting_save(
				sprintf(
					$updated
						? __('「%s」のインストール・更新', GACHIREAI_TEXT_DOMAIN)
						: __('「%s」のインストール', GACHIREAI_TEXT_DOMAIN),
					$definitions[$slug]['name']
				)
			);
			$result = $updated ? 'plugin_updated' : 'plugin_done';
		} catch (\Throwable $e) {
			set_transient(
				self::PLUGIN_ERROR_TRANSIENT . get_current_user_id(),
				mb_substr(trim($e->getMessage()), 0, 500),
				300
			);
		}
		$this->redirect($result, array('plugin' => $slug));
	}
	public function handle_wp_settings(): void
	{
		$this->require_manager(self::NONCE_WP_SETTINGS);
		if ($this->is_common_request()) {
			$this->redirect('wp_settings_unavailable');
		}
		try {
			$this->apply_wp_settings();
			$this->record_setting_save(__('WordPress設定', GACHIREAI_TEXT_DOMAIN));
			$this->redirect('wp_settings_done');
		} catch (\Throwable $e) {
			set_transient(
				self::WP_SETTINGS_ERROR_TRANSIENT . get_current_user_id(),
				mb_substr(trim($e->getMessage()), 0, 500),
				300
			);
			$this->redirect('wp_settings_error');
		}
	}
	public function handle_install_theme(): void
	{
		$this->require_manager(self::NONCE_INSTALL_THEME);
		if (! current_user_can('install_themes') || ! current_user_can('switch_themes')) {
			$this->redirect('theme_no_permission');
		}
		$result = 'theme_error';
		try {
			if ($this->is_cocoon_active()) {
				$this->redirect('theme_already_active');
			}
			$this->install_and_activate_theme();
			$updated = $this->update_theme_if_available();
			$this->record_setting_save(
				$updated
					? __('推奨テーマ（Cocoon）のインストール・更新', GACHIREAI_TEXT_DOMAIN)
					: __('推奨テーマ（Cocoon）のインストール', GACHIREAI_TEXT_DOMAIN)
			);
			$result = $updated ? 'theme_updated' : 'theme_done';
		} catch (\Throwable $e) {
			set_transient(
				self::THEME_ERROR_TRANSIENT . get_current_user_id(),
				mb_substr(trim($e->getMessage()), 0, 500),
				300
			);
		}
		$this->redirect($result);
	}
	public function handle_theme_settings(): void
	{
		$this->require_manager(self::NONCE_THEME_SETTINGS);
		if ($this->is_common_request()) {
			$this->redirect('theme_settings_unavailable');
		}
		try {
			$this->apply_theme_settings();
			$this->record_setting_save(__('推奨テーマ（Cocoon）の推奨設定', GACHIREAI_TEXT_DOMAIN));
			$this->redirect('theme_settings_done');
		} catch (\Throwable $e) {
			set_transient(
				self::THEME_SETTINGS_ERROR_TRANSIENT . get_current_user_id(),
				mb_substr(trim($e->getMessage()), 0, 500),
				300
			);
			$this->redirect('theme_settings_error');
		}
	}
	private function apply_wp_settings(): void
	{
		if (! in_array('ja', get_available_languages(), true)) {
			if (! function_exists('wp_download_language_pack')) {
				require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			}
			require_once ABSPATH . 'wp-admin/includes/file.php'; 
			if (function_exists('wp_download_language_pack')) {
				wp_download_language_pack('ja');
			}
		}
		update_option('WPLANG', 'ja');
		update_option('timezone_string', 'Asia/Tokyo');
		update_option('date_format', 'Y年n月j日');
		update_option('time_format', 'g:i A');
		update_option('start_of_week', 1);
		update_option('blog_public', 1);
		update_option('default_pingback_flag', 0);
		update_option('default_ping_status', 'closed');
		update_option('default_comment_status', 'closed');
		update_option('require_name_email', 1);
		update_option('comment_registration', 1);
		update_option('comments_notify', 0);
		update_option('moderation_notify', 0);
		update_option('comment_moderation', 1);
		update_option('comment_whitelist', 0);
		update_option('permalink_structure', $this->permalink_structure());
		flush_rewrite_rules();
	}
	private function permalink_structure(): string
	{
		return is_multisite() ? '/blog/%postname%/' : '/%postname%/';
	}
	private function apply_theme_settings(): void
	{
		set_theme_mod('all_thumbnail_visible', 1);
		set_theme_mod('facebook_ogp_enable', 0);
		$top_eyecatch_url = $this->top_eyecatch_url();
		if ('' !== $top_eyecatch_url) {
			set_theme_mod('ogp_home_image_url', $top_eyecatch_url);
		}
		set_theme_mod('analytics_admin_include', 0);
		set_theme_mod('single_comment_visible', 0);
		set_theme_mod('page_comment_visible', 0);
		set_theme_mod('eyecatch_visible', 0);
		set_theme_mod('auto_post_thumbnail_enable', 0);
		if ('' !== $top_eyecatch_url) {
			set_theme_mod('no_image_url', $top_eyecatch_url);
		}
		set_theme_mod('admin_list_author_visible', 0);
		set_theme_mod('admin_list_comments_visible', 0);
		set_theme_mod('admin_list_post_id_visible', 0);
		set_theme_mod('admin_list_word_count_visible', 0);
		set_theme_mod('admin_list_memo_visible', 0);
		set_theme_mod('featured_image_from_title_enable', 0);
	}
	private function top_eyecatch_url(): string
	{
		$site_images = (new Site_Profile())->get_site_images();
		$id          = isset($site_images['top_eyecatch']) && is_scalar($site_images['top_eyecatch'])
			? absint((string) $site_images['top_eyecatch'])
			: 0;
		if (0 < $id) {
			$base_id  = (int) get_post_meta($id, '_gachireai_image_base_attachment_id', true);
			$image_id = 0 < $base_id ? $base_id : $id;
			$url      = wp_get_attachment_image_url($image_id, 'full');
			if (is_string($url) && '' !== $url) {
				return $url;
			}
		}
		return '';
	}
	private function is_cocoon_active(): bool
	{
		return 0 === strpos(get_stylesheet(), 'cocoon')
			|| 0 === strpos(get_template(), 'cocoon');
	}
	private function install_and_activate_plugin(array $definition): string
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if (file_exists(ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php')) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
		$plugin_file = $this->find_plugin_file($definition);
		if ('' === $plugin_file) {
			$skin = class_exists('\Automatic_Upgrader_Skin')
				? new \Automatic_Upgrader_Skin()
				: new \WP_Upgrader_Skin();
			$upgrader  = new \Plugin_Upgrader($skin);
			$installed = $upgrader->install($definition['zip']);
			if (is_wp_error($installed)) {
				throw new \RuntimeException($installed->get_error_message());
			}
			if (true !== $installed) {
				throw new \RuntimeException(__('プラグインをインストールできませんでした。', GACHIREAI_TEXT_DOMAIN));
			}
			$errors = method_exists($skin, 'get_errors') ? $skin->get_errors() : null;
			if ($errors instanceof \WP_Error && $errors->has_errors()) {
				throw new \RuntimeException($errors->get_error_message());
			}
			$plugin_file = $upgrader->plugin_info();
			if (! is_string($plugin_file) || '' === $plugin_file) {
				throw new \RuntimeException(__('インストールしたプラグインのメインファイルを特定できませんでした。', GACHIREAI_TEXT_DOMAIN));
			}
		}
		$is_active = is_plugin_active($plugin_file)
			|| (is_multisite() && is_plugin_active_for_network($plugin_file));
		if (! $is_active) {
			$activated = activate_plugin($plugin_file, '', is_multisite(), false);
			if (is_wp_error($activated)) {
				throw new \RuntimeException($activated->get_error_message());
			}
		}
		return $plugin_file;
	}
	private function enable_plugin_auto_update(string $plugin_file): void
	{
		$basename     = plugin_basename($plugin_file);
		$auto_updates = (array) get_site_option('auto_update_plugins', array());
		if (in_array($basename, $auto_updates, true)) {
			return;
		}
		$auto_updates[] = $basename;
		update_site_option('auto_update_plugins', array_values(array_unique($auto_updates)));
	}
	private function update_plugin_if_available(string $plugin_file): bool
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if (file_exists(ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php')) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
		delete_site_transient('update_plugins');
		wp_update_plugins();
		$current = get_site_transient('update_plugins');
		if (! is_object($current) || ! isset($current->response[$plugin_file])) {
			return false;
		}
		$skin = class_exists('\Automatic_Upgrader_Skin')
			? new \Automatic_Upgrader_Skin()
			: new \WP_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader($skin);
		$updated  = $upgrader->upgrade($plugin_file);
		if (is_wp_error($updated)) {
			throw new \RuntimeException($updated->get_error_message());
		}
		if (true !== $updated) {
			throw new \RuntimeException(__('プラグインを更新できませんでした。', GACHIREAI_TEXT_DOMAIN));
		}
		$is_active = is_plugin_active($plugin_file)
			|| (is_multisite() && is_plugin_active_for_network($plugin_file));
		if (! $is_active) {
			$activated = activate_plugin($plugin_file, '', is_multisite(), false);
			if (is_wp_error($activated)) {
				throw new \RuntimeException($activated->get_error_message());
			}
		}
		return true;
	}
	private function install_and_activate_theme(): void
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if (file_exists(ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php')) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
		foreach ($this->theme_definitions() as $definition) {
			if ($this->theme_exists($definition['stylesheet'])) {
				continue;
			}
			$skin = class_exists('\Automatic_Upgrader_Skin')
				? new \Automatic_Upgrader_Skin()
				: new \WP_Upgrader_Skin();
			$upgrader  = new \Theme_Upgrader($skin);
			$installed = $upgrader->install($definition['zip']);
			if (is_wp_error($installed)) {
				throw new \RuntimeException($installed->get_error_message());
			}
			if (true !== $installed) {
				throw new \RuntimeException(
					sprintf(
						__('「%s」をインストールできませんでした。', GACHIREAI_TEXT_DOMAIN),
						$definition['name']
					)
				);
			}
			$errors = method_exists($skin, 'get_errors') ? $skin->get_errors() : null;
			if ($errors instanceof \WP_Error && $errors->has_errors()) {
				throw new \RuntimeException($errors->get_error_message());
			}
		}
		if ($this->theme_exists('cocoon-child-master') && 'cocoon-child-master' !== get_stylesheet()) {
			switch_theme('cocoon-child-master');
		}
	}
	private function theme_exists(string $stylesheet): bool
	{
		if (! function_exists('wp_get_theme')) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		return wp_get_theme($stylesheet)->exists();
	}
	private function theme_definitions(): array
	{
		return array(
			array(
				'name'       => 'Cocoon',
				'zip'        => 'https://wp-cocoon.com/download/8442/',
				'stylesheet' => 'cocoon-master',
			),
			array(
				'name'       => 'Cocoon Child',
				'zip'        => 'https://wp-cocoon.com/download/726/',
				'stylesheet' => 'cocoon-child-master',
			),
		);
	}
	private function update_theme_if_available(): bool
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if (file_exists(ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php')) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
		delete_site_transient('update_themes');
		wp_update_themes();
		$current = get_site_transient('update_themes');
		$themes  = is_object($current) && isset($current->response) && is_array($current->response)
			? $current->response
			: array();
		$updated = false;
		foreach ($this->theme_definitions() as $definition) {
			$stylesheet = $definition['stylesheet'];
			if (! isset($themes[$stylesheet])) {
				continue;
			}
			$skin = class_exists('\Automatic_Upgrader_Skin')
				? new \Automatic_Upgrader_Skin()
				: new \WP_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader($skin);
			$result   = $upgrader->upgrade($stylesheet);
			if (is_wp_error($result)) {
				throw new \RuntimeException($result->get_error_message());
			}
			if (true !== $result) {
				throw new \RuntimeException(
					sprintf(
						__('「%s」を更新できませんでした。', GACHIREAI_TEXT_DOMAIN),
						$definition['name']
					)
				);
			}
			$updated = true;
		}
		if ($this->theme_exists('cocoon-child-master') && 'cocoon-child-master' !== get_stylesheet()) {
			switch_theme('cocoon-child-master');
		}
		return $updated;
	}
	private function recommended_plugins_model(): array
	{
		$groups = array(
			'tonkatsutei' => array(
				'group_title' => esc_html__('ton活亭の無料プラグイン', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(),
			),
			'external'    => array(
				'group_title' => esc_html__('社外製のプラグイン', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(),
			),
		);
		foreach ($this->plugin_definitions() as $definition) {
			$group = 'tonkatsutei' === $definition['group'] ? 'tonkatsutei' : 'external';
			$state = $this->plugin_state($definition);
			$groups[$group]['items'][] = array(
				'item_slug'        => esc_attr($definition['slug']),
				'item_name'        => esc_html($definition['name']),
				'item_description' => esc_html($definition['description']),
				'item_state_label' => esc_html($this->plugin_state_label($state)),
			);
		}
		$groups = array_values(
			array_filter(
				$groups,
				static function (array $group): bool {
					return array() !== $group['items'];
				}
			)
		);
		return array(
			'recommended_plugins_title'         => esc_html__('推奨プラグイン', GACHIREAI_TEXT_DOMAIN),
			'recommended_plugins_description'   => esc_html__('当プラグインと組み合わせて使うことを推奨するプラグインです。［インストール］を押すと、インストール・有効化・自動更新の有効化・更新チェック（更新があれば更新）をまとめて行います（マルチサイトではサイトネットワークで有効化します）。', GACHIREAI_TEXT_DOMAIN),
			'recommended_plugins_action_url'    => esc_url(admin_url('admin-post.php')),
			'recommended_plugins_hidden_fields' => $this->hidden_fields(self::ACTION_INSTALL_PLUGIN, self::NONCE_INSTALL_PLUGIN),
			'recommended_plugins_field'         => esc_attr(self::INSTALL_PLUGIN_FIELD),
			'recommended_plugins_groups'        => $groups,
			'recommended_plugins_install_label' => esc_html__('インストール', GACHIREAI_TEXT_DOMAIN),
			'recommended_plugins_messages'      => $this->recommended_plugins_messages(),
		);
	}
	private function settings_legend(): array
	{
		return array(
			array('legend_text' => esc_html__('凡例 : ☑ ON , ☐ OFF', GACHIREAI_TEXT_DOMAIN)),
		);
	}
	private function wp_settings_model(): array
	{
		$hidden = array(
			'wp_settings_visible'         => '',
			'wp_settings_title'           => '',
			'wp_settings_recommend_label' => '',
			'wp_settings_description'     => '',
			'wp_settings_action_url'      => '',
			'wp_settings_hidden_fields'   => array(),
			'wp_settings_button_label'    => '',
			'wp_settings_messages'        => array(),
			'wp_settings_details_title'   => '',
			'wp_settings_legend'          => array(),
			'wp_settings_groups'          => array(),
		);
		if ($this->common_mode) {
			return $hidden;
		}
		return array(
			'wp_settings_visible'         => '1',
			'wp_settings_title'           => esc_html__('WordPress設定', GACHIREAI_TEXT_DOMAIN),
			'wp_settings_recommend_label' => esc_html__('推奨設定', GACHIREAI_TEXT_DOMAIN),
			'wp_settings_description'     => esc_html__("当プラグイン向けのWordPress設定をワンボタンで一括設定します。\n推奨設定の内容をここで個別に変更することはできませんので、必要に応じて後で個別に変更するか、この機能を使わずに独自に設定してください。", GACHIREAI_TEXT_DOMAIN),
			'wp_settings_action_url'      => esc_url(admin_url('admin-post.php')),
			'wp_settings_hidden_fields'   => $this->hidden_fields(self::ACTION_WP_SETTINGS, self::NONCE_WP_SETTINGS),
			'wp_settings_button_label'    => esc_html__('一括設定', GACHIREAI_TEXT_DOMAIN),
			'wp_settings_messages'        => $this->wp_settings_messages(),
			'wp_settings_details_title'   => esc_html__('設定内容', GACHIREAI_TEXT_DOMAIN),
			'wp_settings_legend'          => $this->settings_legend(),
			'wp_settings_groups'          => $this->wp_settings_definitions(),
		);
	}
	private function theme_model(): array
	{
		$hidden = array(
			'theme_visible'                => '',
			'theme_title'                  => '',
			'theme_description'            => '',
			'theme_install_label'          => '',
			'theme_install_description'    => '',
			'theme_install_action_url'     => '',
			'theme_install_hidden_fields'  => array(),
			'theme_install_button_label'   => '',
			'theme_install_messages'       => array(),
			'theme_settings_label'         => '',
			'theme_settings_description'   => '',
			'theme_settings_action_url'    => '',
			'theme_settings_hidden_fields' => array(),
			'theme_settings_button_label'  => '',
			'theme_settings_messages'      => array(),
			'theme_details_label'          => '',
			'theme_settings_legend'        => array(),
			'theme_settings_groups'        => array(),
		);
		if ($this->common_mode) {
			return $hidden;
		}
		return array(
			'theme_visible'                => '1',
			'theme_title'                  => esc_html__('推奨テーマ（Cocoon）', GACHIREAI_TEXT_DOMAIN),
			'theme_description'            => esc_html__('無料でありながら、有料テーマに匹敵するほどの高い機能性とコストパフォーマンスを誇る、国内で圧倒的な人気を持つテーマです。', GACHIREAI_TEXT_DOMAIN),
			'theme_install_label'          => esc_html__('インストール', GACHIREAI_TEXT_DOMAIN),
			'theme_install_description'    => esc_html__('公式サイトからダウンロードしてインストールおよび有効化まで自動で一括処理します。', GACHIREAI_TEXT_DOMAIN),
			'theme_install_action_url'     => esc_url(admin_url('admin-post.php')),
			'theme_install_hidden_fields'  => $this->hidden_fields(self::ACTION_INSTALL_THEME, self::NONCE_INSTALL_THEME),
			'theme_install_button_label'   => esc_html__('インストール', GACHIREAI_TEXT_DOMAIN),
			'theme_install_messages'       => $this->theme_install_messages(),
			'theme_settings_label'         => esc_html__('推奨設定', GACHIREAI_TEXT_DOMAIN),
			'theme_settings_description'   => esc_html__("当プラグイン向けの推奨設定に一括変更します。\n最低限の設定であり、全項目を設定する訳ではありませんので、必要に応じて個別に設定してください。\n尚、Cocoonのバックアップ&リストア機能を使えば、お気に入りの設定を他サイトで再現できお勧めです。", GACHIREAI_TEXT_DOMAIN),
			'theme_settings_action_url'    => esc_url(admin_url('admin-post.php')),
			'theme_settings_hidden_fields' => $this->hidden_fields(self::ACTION_THEME_SETTINGS, self::NONCE_THEME_SETTINGS),
			'theme_settings_button_label'  => esc_html__('実行', GACHIREAI_TEXT_DOMAIN),
			'theme_settings_messages'      => $this->theme_settings_messages(),
			'theme_details_label'          => esc_html__('設定内容', GACHIREAI_TEXT_DOMAIN),
			'theme_settings_legend'        => $this->settings_legend(),
			'theme_settings_groups'        => $this->theme_settings_definitions(),
		);
	}
	private function wp_settings_definitions(): array
	{
		return array(
			array(
				'group_title' => esc_html__('一般', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('サイトの言語 : 日本語', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('タイムゾーン : 東京', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('日付形式 : Y年n月j日', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('時刻形式 : g:i A', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('週の始まり : 月曜日', GACHIREAI_TEXT_DOMAIN)),
				),
				'subgroups'   => array(),
			),
			array(
				'group_title' => esc_html__('表示設定', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☐ 検索エンジンがサイトをインデックスしないようにする', GACHIREAI_TEXT_DOMAIN)),
				),
				'subgroups'   => array(),
			),
			array(
				'group_title' => esc_html__('ディスカッション', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☐ 投稿中からリンクしたすべてのブログへの通知を試みる', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ 新しい投稿に対し他のブログからの通知（ピンバック・トラックバック）を受け付ける', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ 新しい投稿へのコメントを許可する', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☑ コメントの投稿者の名前とメールアドレスの入力を必須にする', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☑ ユーザー登録してログインしたユーザーのみコメントをつけられるようにする', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ コメントが投稿されたとき', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ コメントがモデレーションのために保留されたとき', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☑ コメントの手動承認を必須にする', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ すでに承認されたコメントの投稿者のコメントを許可し、それ以外のコメントを承認待ちにする', GACHIREAI_TEXT_DOMAIN)),
				),
				'subgroups'   => array(),
			),
			array(
				'group_title' => esc_html__('パーマリンク', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array(
						'item_text' => sprintf(
							esc_html__('（シングルサイト）%1$s　（マルチサイト）%2$s', GACHIREAI_TEXT_DOMAIN),
							esc_url(home_url('/%postname%/')),
							esc_url(home_url('/blog/%postname%/'))
						),
					),
				),
				'subgroups'   => array(),
			),
		);
	}
	private function theme_settings_definitions(): array
	{
		return array(
			array(
				'group_title' => esc_html__('全体', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☑ サイト全体のサムネイルを表示する', GACHIREAI_TEXT_DOMAIN)),
				),
			),
			array(
				'group_title' => esc_html__('OGP', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☐ OGPタグを挿入する', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('ホームイメージ : トップページのアイキャッチ画像', GACHIREAI_TEXT_DOMAIN)),
				),
			),
			array(
				'group_title' => esc_html__('アクセス解析・認証', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☐ サイト管理者も含めてアクセス解析する', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ コメントを表示する', GACHIREAI_TEXT_DOMAIN)),
				),
			),
			array(
				'group_title' => esc_html__('固定ページ', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☐ コメントを表示する', GACHIREAI_TEXT_DOMAIN)),
				),
			),
			array(
				'group_title' => esc_html__('画像', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☐ 本文上にアイキャッチを表示する', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ アイキャッチ自動設定を有効にする（※投稿・固定ページのみ）', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('NO IMAGE画像 : トップページのアイキャッチ画像', GACHIREAI_TEXT_DOMAIN)),
				),
			),
			array(
				'group_title' => esc_html__('管理者画面', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☐ 投稿者を表示する', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ コメントを表示する', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ IDを表示する', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ 文字数を表示する', GACHIREAI_TEXT_DOMAIN)),
					array('item_text' => esc_html__('☐ メモの内容を表示する', GACHIREAI_TEXT_DOMAIN)),
				),
			),
			array(
				'group_title' => esc_html__('エディター', GACHIREAI_TEXT_DOMAIN),
				'items'       => array(
					array('item_text' => esc_html__('☐ 「タイトルからアイキャッチを生成する」機能を有効にする', GACHIREAI_TEXT_DOMAIN)),
				),
			),
		);
	}
	private function wp_settings_messages(): array
	{
		$result = $this->result();
		if ('wp_settings_done' === $result) {
			return array(
				$this->message('success', esc_html__('WordPress の設定を一括で適用しました。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('wp_settings_unavailable' === $result) {
			return array(
				$this->message('warning', esc_html__('WordPress の設定はサイトごとに異なるため、共通設定からは実行できません。各サイトのメンテナンスタブで実行してください。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('wp_settings_error' === $result) {
			$error = get_transient(self::WP_SETTINGS_ERROR_TRANSIENT . get_current_user_id());
			delete_transient(self::WP_SETTINGS_ERROR_TRANSIENT . get_current_user_id());
			return array(
				$this->message(
					'error',
					'' !== (string) $error
						? esc_html((string) $error)
						: esc_html__('WordPress の設定を適用できませんでした。', GACHIREAI_TEXT_DOMAIN)
				),
			);
		}
		return array();
	}
	private function theme_install_messages(): array
	{
		$result = $this->result();
		if ('theme_already_active' === $result) {
			return array(
				$this->message('info', esc_html__('Cocoon はすでに有効化されています。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('theme_updated' === $result) {
			return array(
				$this->message('success', esc_html__('Cocoon をインストール・有効化し、最新版へ更新しました。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('theme_done' === $result) {
			return array(
				$this->message('success', esc_html__('Cocoon をインストールし、子テーマを有効化しました。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('theme_no_permission' === $result) {
			return array(
				$this->message('error', esc_html__('テーマをインストール・有効化する権限がありません。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('theme_error' === $result) {
			$error = get_transient(self::THEME_ERROR_TRANSIENT . get_current_user_id());
			delete_transient(self::THEME_ERROR_TRANSIENT . get_current_user_id());
			return array(
				$this->message(
					'error',
					'' !== (string) $error
						? esc_html((string) $error)
						: esc_html__('テーマのインストール・有効化に失敗しました。', GACHIREAI_TEXT_DOMAIN)
				),
			);
		}
		return array();
	}
	private function theme_settings_messages(): array
	{
		$result = $this->result();
		if ('theme_settings_done' === $result) {
			return array(
				$this->message('success', esc_html__('Cocoon の推奨設定を一括で適用しました。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('theme_settings_unavailable' === $result) {
			return array(
				$this->message('warning', esc_html__('テーマの設定はサイトごとに異なるため、共通設定からは実行できません。各サイトのメンテナンスタブで実行してください。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('theme_settings_error' === $result) {
			$error = get_transient(self::THEME_SETTINGS_ERROR_TRANSIENT . get_current_user_id());
			delete_transient(self::THEME_SETTINGS_ERROR_TRANSIENT . get_current_user_id());
			return array(
				$this->message(
					'error',
					'' !== (string) $error
						? esc_html((string) $error)
						: esc_html__('Cocoon の推奨設定を適用できませんでした。', GACHIREAI_TEXT_DOMAIN)
				),
			);
		}
		return array();
	}
	private function plugin_definitions(): array
	{
		return array(
			'image-size-manager'     => array(
				'slug'        => 'image-size-manager',
				'group'       => 'tonkatsutei',
				'name'        => 'Image Size Manager',
				'description' => __("自動生成される解像度違いの大量の画像で、サーバー容量を圧迫しないように制限するプラグイン。\nただし表示速度の適正化とはトレードになります。", GACHIREAI_TEXT_DOMAIN),
				'zip'         => 'https://membership.tonkatsutei.com/tools/image_size_manager/image_size_manager.zip',
				'folder'      => 'image-size-manager',
			),
			'disable-auto-shaping'   => array(
				'slug'        => 'disable-auto-shaping',
				'group'       => 'tonkatsutei',
				'name'        => 'Disable Auto Shaping',
				'description' => __('WordPressのお節介な文字変換で、文字化けなどの事故を防止するプラグイン。', GACHIREAI_TEXT_DOMAIN),
				'zip'         => 'https://membership.tonkatsutei.com/tools/VOD/dl/Disable_Auto_Shaping.zip',
				'folder'      => 'Disable_Auto_Shaping',
			),
			'ad-changer-by-category' => array(
				'slug'        => 'ad-changer-by-category',
				'group'       => 'tonkatsutei',
				'name'        => 'Ad Changer By Category',
				'description' => __("WordPressのカテゴリーごとにアフィリエイト広告を一括管理できるプラグイン。\nガチレビュー.AIではカテゴリーが自動生成されるために使いどころは難しいかも。", GACHIREAI_TEXT_DOMAIN),
				'zip'         => 'https://membership.tonkatsutei.com/tools/Ad-Changer-By-Category/dl/ad-changer-by-category.zip',
				'folder'      => 'ad-changer-by-category',
			),
			'id-guard'               => array(
				'slug'        => 'id-guard',
				'group'       => 'tonkatsutei',
				'name'        => 'ID Guard',
				'description' => __('ログインIDの流出を防ぐセキュリティプラグイン。', GACHIREAI_TEXT_DOMAIN),
				'zip'         => 'https://membership.tonkatsutei.com/tools/Id_Guard/Id_Guard.zip',
				'folder'      => 'Id_Guard',
			),
			'google-site-kit'        => array(
				'slug'        => 'google-site-kit',
				'group'       => 'external',
				'name'        => 'Site Kit by Google',
				'description' => __("Googleの複数サービスを一括管理できる無料のGoogle公式プラグイン。\n（Googleアナリティクス、Googleサーチコンソール、Googleアドセンス、PageSpeed Insights、Googleタグマネージャーなど）", GACHIREAI_TEXT_DOMAIN),
				'zip'         => 'https://downloads.wordpress.org/plugin/google-site-kit.1.190.0.zip',
				'folder'      => 'google-site-kit',
			),
		);
	}
	private function plugin_state(array $definition): string
	{
		$plugin_file = $this->find_plugin_file($definition);
		if ('' === $plugin_file) {
			return 'not_installed';
		}
		if (is_plugin_active($plugin_file)) {
			return 'active';
		}
		if (is_multisite() && is_plugin_active_for_network($plugin_file)) {
			return 'active';
		}
		return 'inactive';
	}
	private function plugin_state_label(string $state): string
	{
		switch ($state) {
			case 'active':
				return __('有効化済み', GACHIREAI_TEXT_DOMAIN);
			case 'inactive':
				return __('インストール済み（未使用）', GACHIREAI_TEXT_DOMAIN);
		}
		return __('未インストール', GACHIREAI_TEXT_DOMAIN);
	}
	private function find_plugin_file(array $definition): string
	{
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$folders = array($definition['folder'], $definition['slug']);
		foreach (array_keys($plugins) as $plugin_file) {
			$folder = dirname((string) $plugin_file);
			if (in_array($folder, $folders, true)) {
				return (string) $plugin_file;
			}
		}
		foreach ($plugins as $plugin_file => $data) {
			$name = isset($data['Name']) && is_scalar($data['Name']) ? trim((string) $data['Name']) : '';
			if ('' !== $name && false !== mb_strpos($name, $definition['name'])) {
				return (string) $plugin_file;
			}
		}
		return '';
	}
	private function recommended_plugins_messages(): array
	{
		$result = $this->result();
		if ('' === $result) {
			return array();
		}
		$name = $this->result_plugin_name();
		if ('plugin_updated' === $result) {
			return array(
				$this->message(
					'success',
					sprintf(
						esc_html__('「%s」をインストール・有効化・更新し、自動更新を有効にしました。', GACHIREAI_TEXT_DOMAIN),
						'' !== $name ? esc_html($name) : esc_html__('プラグイン', GACHIREAI_TEXT_DOMAIN)
					)
				),
			);
		}
		if ('plugin_done' === $result) {
			return array(
				$this->message(
					'success',
					sprintf(
						esc_html__('「%s」をインストール・有効化し、自動更新を有効にしました。', GACHIREAI_TEXT_DOMAIN),
						'' !== $name ? esc_html($name) : esc_html__('プラグイン', GACHIREAI_TEXT_DOMAIN)
					)
				),
			);
		}
		if ('plugin_invalid' === $result) {
			return array(
				$this->message('error', esc_html__('インストールするプラグインの指定が不正です。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('plugin_no_permission' === $result) {
			return array(
				$this->message('error', esc_html__('プラグインをインストールする権限がありません。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('plugin_error' === $result) {
			$error = get_transient(self::PLUGIN_ERROR_TRANSIENT . get_current_user_id());
			delete_transient(self::PLUGIN_ERROR_TRANSIENT . get_current_user_id());
			return array(
				$this->message(
					'error',
					'' !== (string) $error
						? esc_html((string) $error)
						: esc_html__('プラグインのインストール・有効化に失敗しました。', GACHIREAI_TEXT_DOMAIN)
				),
			);
		}
		return array();
	}
	private function result_plugin_name(): string
	{
		$slug = isset($_GET['plugin']) ? sanitize_key(wp_unslash((string) $_GET['plugin'])) : '';
		if ('' === $slug) {
			return '';
		}
		$definitions = $this->plugin_definitions();
		return isset($definitions[$slug]) ? $definitions[$slug]['name'] : '';
	}
	private function redirect(string $result, array $args = array()): void
	{
		$args = array_merge(array(self::RESULT_ARG => $result), $args);
		$url = $this->is_common_request()
			? Common_Tabs::url(Common_Tabs::TAB_MAINTENANCE, $args)
			: Tabs::url(Tabs::TAB_MAINTENANCE, $args);
		wp_safe_redirect($url);
		exit;
	}
	private function is_common_request(): bool
	{
		if ($this->common_mode) {
			return true;
		}
		return isset($_POST[self::COMMON_FIELD]) && '1' === sanitize_key(wp_unslash((string) $_POST[self::COMMON_FIELD]));
	}
	private function capability(): string
	{
		return $this->common_mode ? 'manage_network' : 'manage_options';
	}
	private function model(): array
	{
		$model = array(
			'page_title' => $this->common_mode
				? esc_html(Common_Tabs::heading(Common_Tabs::TAB_MAINTENANCE))
				: esc_html__('メンテナンス', GACHIREAI_TEXT_DOMAIN),
			'notices'    => array(),
			'tabs'       => $this->tab_nav(),
			'backup_title'         => esc_html__('バックアップ', GACHIREAI_TEXT_DOMAIN),
			'backup_description'   => esc_html__('ガチレビュー.AI の設定（ライセンスを含む）を JSON でダウンロードします。', GACHIREAI_TEXT_DOMAIN),
			'backup_action_url'    => esc_url(admin_url('admin-post.php')),
			'backup_hidden_fields' => $this->hidden_fields(self::ACTION_BACKUP, self::NONCE_BACKUP),
			'backup_sites_label'       => esc_html__('対象サイト', GACHIREAI_TEXT_DOMAIN),
			'backup_site_name'         => esc_attr(self::BACKUP_SITES_FIELD . '[]'),
			'backup_sites'             => $this->backup_sites_model(),
			'backup_check_all_label'   => esc_html__('すべてチェック', GACHIREAI_TEXT_DOMAIN),
			'backup_uncheck_all_label' => esc_html__('すべて解除', GACHIREAI_TEXT_DOMAIN),
			'backup_note'              => esc_html__('ライセンスIDと、チェックしたサイトの設定を含みます。', GACHIREAI_TEXT_DOMAIN),
			'backup_common_label'   => esc_html__('共通設定', GACHIREAI_TEXT_DOMAIN),
			'backup_common_name'    => esc_attr(self::BACKUP_COMMON_FIELD),
			'backup_common_checked' => true,
			'backup_button_label'      => esc_html__('バックアップ', GACHIREAI_TEXT_DOMAIN),
			'restore_title'         => esc_html__('リストア', GACHIREAI_TEXT_DOMAIN),
			'restore_description'   => esc_html__("バックアップ JSON を選んで［読み込み］を押すと、下にリストア先ごとの対応表が表示されます。\nバックアップに無い項目は規定値になります。", GACHIREAI_TEXT_DOMAIN),
			'restore_action_url'    => esc_url(admin_url('admin-post.php')),
			'restore_hidden_fields' => $this->hidden_fields(self::ACTION_UPLOAD, self::NONCE_UPLOAD),
			'restore_file_field'    => esc_attr(self::RESTORE_FILE_FIELD),
			'restore_upload_label'  => esc_html__('読み込み', GACHIREAI_TEXT_DOMAIN),
			'overwrite_name'        => esc_attr(self::RESTORE_OVERWRITE_FIELD),
			'overwrite_yes_label'   => esc_html__('上書きする', GACHIREAI_TEXT_DOMAIN),
			'overwrite_no_label'    => esc_html__('上書きしない', GACHIREAI_TEXT_DOMAIN),
			'overwrite_yes_checked' => false,
			'overwrite_no_checked'  => true,
			'overwrite_note'        => esc_html__('「上書きする」を選ぶと空値も上書きします。「共通」から「サイト」へリストアした場合、共通に含まれない項目はすべて空値になります。', GACHIREAI_TEXT_DOMAIN),
			'restore_messages'      => $this->restore_messages(),
			'reset_title'         => esc_html__('初期化', GACHIREAI_TEXT_DOMAIN),
			'reset_description'   => $this->common_mode
				? esc_html__('共通設定（全サイト共通の ID・APIキー等）を初期状態に戻します。サイトの設定・投稿・メディアには触れません。', GACHIREAI_TEXT_DOMAIN)
				: esc_html__('このサイトのプラグインのデータ（設定・収集したレビュー・商品台帳など）を初期状態に戻します。WordPress の設定（サイト名など）・投稿・メディアには触れません。', GACHIREAI_TEXT_DOMAIN),
			'reset_action_url'    => esc_url(admin_url('admin-post.php')),
			'reset_hidden_fields' => $this->hidden_fields(self::ACTION_RESET, self::NONCE_RESET),
			'reset_button_label'  => esc_html__('初期化を実行', GACHIREAI_TEXT_DOMAIN),
			'reset_confirm_title' => esc_html__('初期化の前に確認してください', GACHIREAI_TEXT_DOMAIN),
			'reset_confirm_description' => $this->common_mode
				? esc_html__('共通設定を初期化します。この操作は取り消せません。実行すると、全サイト共通の ASP / AI / スクレイピング / レビュー設定が初期状態に戻ります。', GACHIREAI_TEXT_DOMAIN)
				: esc_html__('このサイトのプラグインのデータをすべて初期化します。この操作は取り消せません。実行すると、サイト設定・収集したレビュー・商品台帳などが初期状態に戻ります（WordPress の設定・投稿・メディアは残ります）。', GACHIREAI_TEXT_DOMAIN),
			'reset_confirm_label' => esc_html__('リスクを理解しました', GACHIREAI_TEXT_DOMAIN),
			'reset_confirm_name'  => esc_attr(self::RESET_CONFIRM_FIELD),
			'reset_confirm_button' => esc_html__('初期化を実行', GACHIREAI_TEXT_DOMAIN),
			'reset_cancel_label'  => esc_html__('キャンセル', GACHIREAI_TEXT_DOMAIN),
			'reset_messages'      => $this->reset_messages(),
			'has_mapping' => '',
		);
		$payload = $this->restore_payload();
		if (null !== $payload && 'mapping' === $this->result()) {
			$model                = array_merge($model, $this->mapping_model($payload));
			$model['has_mapping'] = '1';
		}
		$model = array_merge($model, $this->network_setup_model());
		$model = array_merge($model, $this->recommended_plugins_model());
		$model = array_merge($model, $this->wp_settings_model());
		$model = array_merge($model, $this->theme_model());
		return $model;
	}
	private function hidden_fields(string $action, string $nonce_action): array
	{
		$fields = array(
			array(
				'name'  => esc_attr('action'),
				'value' => esc_attr($action),
			),
			array(
				'name'  => esc_attr('_wpnonce'),
				'value' => esc_attr(wp_create_nonce($nonce_action)),
			),
		);
		if ($this->common_mode) {
			$fields[] = array(
				'name'  => esc_attr(self::COMMON_FIELD),
				'value' => '1',
			);
		}
		return $fields;
	}
	private function backup_sites_model(): array
	{
		$checked = ! is_multisite();
		$sites   = array();
		foreach ($this->site_entries() as $entry) {
			$sites[] = array(
				'value'   => esc_attr($entry['value']),
				'label'   => esc_html($entry['label']),
				'checked' => $checked,
			);
		}
		return $sites;
	}
	private function restore_messages(): array
	{
		$result = $this->result();
		if ('mapping' === $result) {
			return array(
				$this->message('success', esc_html__("バックアップを読み込みました。\nリストア先ごとにリストア元を選び、［リストア］を押してください。", GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('restored' === $result) {
			$applied = isset($_GET['applied']) ? absint(wp_unslash((string) $_GET['applied'])) : 0;
			$skipped = isset($_GET['skipped']) ? absint(wp_unslash((string) $_GET['skipped'])) : 0;
			return array(
				$this->message(
					'success',
					esc_html(
						sprintf(
							__('リストアしました（%1$d 件／スキップ %2$d 件）。', GACHIREAI_TEXT_DOMAIN),
							$applied,
							$skipped
						)
					)
				),
			);
		}
		if ('upload_error' === $result) {
			return array(
				$this->message('error', esc_html__("バックアップファイルを読み込めませんでした。\n当プラグインのバックアップ JSON を選んでください。", GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('mapping_missing' === $result) {
			return array(
				$this->message('error', esc_html__("リストアの対象がありません。\nバックアップファイルを選び直してください。", GACHIREAI_TEXT_DOMAIN)),
			);
		}
		return array();
	}
	private function reset_messages(): array
	{
		$result = $this->result();
		if ('reset_done' === $result) {
			return array(
				$this->message(
					'success',
					$this->common_mode
						? esc_html__('共通設定を初期化しました。', GACHIREAI_TEXT_DOMAIN)
						: esc_html__('このサイトのデータを初期化しました。', GACHIREAI_TEXT_DOMAIN)
				),
			);
		}
		if ('reset_unconfirmed' === $result) {
			return array(
				$this->message(
					'error',
					esc_html__('「リスクを理解しました」にチェックを入れてから実行してください。', GACHIREAI_TEXT_DOMAIN)
				),
			);
		}
		return array();
	}
	private function network_setup_model(): array
	{
		$hidden = array(
			'network_visible'             => '',
			'network_title'               => '',
			'network_description'         => '',
			'network_show_not_pro'        => '',
			'network_pro_notice'          => '',
			'network_status_label'        => '',
			'network_paths_note'          => '',
			'network_show_network_setup'  => '',
			'network_network_setup_url'   => '',
			'network_network_setup_label' => '',
			'network_network_setup_guide' => '',
			'network_show_form'           => '',
			'network_action_url'          => '',
			'network_hidden_fields'       => array(),
			'network_step_field'          => '',
			'network_step_value'          => '',
			'network_button_label'        => '',
			'network_messages'            => array(),
			'network_confirm_title'       => '',
			'network_confirm_description' => '',
			'network_confirm_label'       => '',
			'network_confirm_name'        => '',
			'network_confirm_button'      => '',
			'network_cancel_label'        => '',
			'network_complete'            => '',
			'network_complete_message'    => '',
			'network_server_note'         => '',
		);
		if ($this->common_mode) {
			return $hidden;
		}
		$is_pro = Status::capabilities()->can_use_multisite();
		$setup  = new Network_Setup();
		$state  = $setup->state();
		$wp_config = (string) $setup->wp_config_path();
		$model = array(
			'network_visible'             => '1',
			'network_title'               => esc_html__('マルチサイト化', GACHIREAI_TEXT_DOMAIN),
			'network_description'         => esc_html__("このサイトをマルチサイト（ネットワーク）に切り替えます。\nWordPress は wp-config.php と .htaccess の編集を自動で行わないため、この画面がその 2 ファイルの書き換えを代行します。", GACHIREAI_TEXT_DOMAIN),
			'network_show_not_pro'        => $is_pro ? '' : '1',
			'network_pro_notice'          => esc_html__('マルチサイト化は PRO ライセンスでのみ利用できます。', GACHIREAI_TEXT_DOMAIN),
			'network_status_label'        => esc_html($this->network_status_label($state)),
			'network_paths_note'          => esc_html($this->network_paths_note($setup, $wp_config)),
			'network_show_network_setup'  => '',
			'network_network_setup_url'   => esc_url(admin_url('network.php')),
			'network_network_setup_label' => esc_html__('ネットワーク設定を開く', GACHIREAI_TEXT_DOMAIN),
			'network_network_setup_guide' => esc_html__("WP_ALLOW_MULTISITE を追記しました。次は「ツール → ネットワーク設定」でネットワークを作成してください。\n作成が終わったらこの画面に戻り、残りの反映を行います。", GACHIREAI_TEXT_DOMAIN),
			'network_show_form'           => '',
			'network_action_url'          => esc_url(admin_url('admin-post.php')),
			'network_hidden_fields'       => $this->hidden_fields(self::ACTION_NETWORK, self::NONCE_NETWORK),
			'network_step_field'          => esc_attr(self::NETWORK_STEP_FIELD),
			'network_step_value'          => '',
			'network_button_label'        => '',
			'network_messages'            => $this->network_messages(),
			'network_confirm_title'       => esc_html__('実行の前に確認してください', GACHIREAI_TEXT_DOMAIN),
			'network_confirm_description' => '',
			'network_confirm_label'       => esc_html__('リスクを理解しました', GACHIREAI_TEXT_DOMAIN),
			'network_confirm_name'        => esc_attr(self::NETWORK_CONFIRM_FIELD),
			'network_confirm_button'      => '',
			'network_cancel_label'        => esc_html__('キャンセル', GACHIREAI_TEXT_DOMAIN),
			'network_complete'            => '',
			'network_complete_message'    => esc_html__('既にマルチサイト化が完了しています。', GACHIREAI_TEXT_DOMAIN),
			'network_server_note'         => esc_html__('サブドメイン型を選ぶ場合は、サーバー側でワイルドカード DNS と SSL 証明書の設定が必要です（サーバー側の設定はこのプラグインでは行いません）。', GACHIREAI_TEXT_DOMAIN),
		);
		if (Network_Setup::STATE_COMPLETE === $state) {
			$model['network_complete']     = '1';
			$model['network_show_not_pro'] = '';
			$model['network_button_label'] = esc_html__('マルチサイト化を開始', GACHIREAI_TEXT_DOMAIN);
			return $model;
		}
		if (! $is_pro) {
			return $model;
		}
		if (Network_Setup::STATE_NOT_DEFINED === $state) {
			$model['network_show_form']           = '1';
			$model['network_step_value']          = 'allow';
			$model['network_button_label']        = esc_html__('マルチサイト化を開始', GACHIREAI_TEXT_DOMAIN);
			$model['network_confirm_description'] = esc_html__('wp-config.php に WP_ALLOW_MULTISITE を追記します。この定数は機能に影響しません（ネットワーク設定のメニューが出るだけです）。書き込み前に wp-config.php を手動でバックアップすることを推奨します。', GACHIREAI_TEXT_DOMAIN);
			$model['network_confirm_button']      = esc_html__('追記する', GACHIREAI_TEXT_DOMAIN);
		} elseif (Network_Setup::STATE_ALLOW_DEFINED === $state) {
			$model['network_show_network_setup'] = '1';
		} elseif (Network_Setup::STATE_TABLES_EXIST === $state) {
			$model['network_show_form']           = '1';
			$model['network_step_value']          = 'apply';
			$model['network_button_label']        = esc_html__('定数と .htaccess を反映', GACHIREAI_TEXT_DOMAIN);
			$model['network_confirm_description'] = esc_html__('wp-config.php に 6 つの定数（MULTISITE など）を追記し、.htaccess をマルチサイト用のリライトルールに置き換えます。反映後は再ログインが必要です。書き込み前に wp-config.php と .htaccess を手動でバックアップすることを推奨します。', GACHIREAI_TEXT_DOMAIN);
			$model['network_confirm_button']      = esc_html__('反映する', GACHIREAI_TEXT_DOMAIN);
		}
		return $model;
	}
	private function network_status_label(string $state): string
	{
		switch ($state) {
			case Network_Setup::STATE_ALLOW_DEFINED:
				return __('現在の状態: WP_ALLOW_MULTISITE まで追記済み（次はネットワーク設定）', GACHIREAI_TEXT_DOMAIN);
			case Network_Setup::STATE_TABLES_EXIST:
				return __('現在の状態: ネットワークテーブル作成済み（次は定数と .htaccess の反映）', GACHIREAI_TEXT_DOMAIN);
			case Network_Setup::STATE_COMPLETE:
				return __('現在の状態: マルチサイト化が完了しています', GACHIREAI_TEXT_DOMAIN);
		}
		return __('現在の状態: 未着手', GACHIREAI_TEXT_DOMAIN);
	}
	private function network_paths_note(Network_Setup $setup, string $wp_config): string
	{
		$wp_ok = $setup->is_wp_config_writable() ? __('書き込み可', GACHIREAI_TEXT_DOMAIN) : __('書き込み不可', GACHIREAI_TEXT_DOMAIN);
		$ht_ok = $setup->is_htaccess_writable() ? __('書き込み可', GACHIREAI_TEXT_DOMAIN) : __('書き込み不可', GACHIREAI_TEXT_DOMAIN);
		return sprintf(
			__('対象ファイル: %1$s（%2$s）／ %3$s（%4$s）', GACHIREAI_TEXT_DOMAIN),
			'' !== $wp_config ? $wp_config : __('不明', GACHIREAI_TEXT_DOMAIN),
			$wp_ok,
			$setup->htaccess_path(),
			$ht_ok
		);
	}
	private function network_messages(): array
	{
		$result = $this->result();
		if ('network_allow_done' === $result) {
			return array(
				$this->message('success', esc_html__("wp-config.php に WP_ALLOW_MULTISITE を追記しました。\n画面を再読み込みしてから「ツール → ネットワーク設定」へ進んでください。", GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('network_apply_done' === $result) {
			return array(
				$this->message('success', esc_html__("定数と .htaccess を反映しました。再ログインするとマルチサイトとして動作します。\nサブドメイン型の場合は、サーバー側のワイルドカード DNS／SSL の設定も確認してください。", GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('network_unconfirmed' === $result) {
			return array(
				$this->message('error', esc_html__('「リスクを理解しました」にチェックを入れてから実行してください。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('network_not_pro' === $result) {
			return array(
				$this->message('error', esc_html__('マルチサイト化は PRO ライセンスでのみ実行できます。', GACHIREAI_TEXT_DOMAIN)),
			);
		}
		if ('network_error' === $result) {
			$error = get_transient(self::NETWORK_ERROR_TRANSIENT . get_current_user_id());
			delete_transient(self::NETWORK_ERROR_TRANSIENT . get_current_user_id());
			return array(
				$this->message(
					'error',
					'' !== (string) $error
						? esc_html((string) $error)
						: esc_html__('マルチサイト化に失敗しました。', GACHIREAI_TEXT_DOMAIN)
				),
			);
		}
		return array();
	}
	private function mapping_model(array $payload): array
	{
		$sites   = isset($payload['sites']) && is_array($payload['sites']) ? $payload['sites'] : array();
		$sources = array();
		foreach ($sites as $blog_id => $entry) {
			$sources[] = array(
				'value' => esc_attr((string) $blog_id),
				'label' => esc_html($this->entry_label($entry, (string) $blog_id)),
			);
		}
		$destinations = array();
		if (isset($sites['0'])) {
			$options = array(
				array(
					'value'    => esc_attr(self::MAP_NONE),
					'label'    => esc_html__('リストアしない', GACHIREAI_TEXT_DOMAIN),
					'selected' => false,
				),
			);
			foreach ($sources as $source) {
				$options[] = array(
					'value'    => $source['value'],
					'label'    => $source['label'],
					'selected' => '0' === (string) $source['value'],
				);
			}
			$destinations[] = array(
				'dest_label' => esc_html__('共通設定', GACHIREAI_TEXT_DOMAIN),
				'field_name' => esc_attr(self::RESTORE_MAP_FIELD . '[0]'),
				'options'    => $options,
			);
		}
		foreach ($this->site_entries() as $entry) {
			$blog_id = (string) $entry['value'];
			$initial = isset($sites[$blog_id]) ? $blog_id : self::MAP_NONE;
			$options = array(
				array(
					'value'    => esc_attr(self::MAP_NONE),
					'label'    => esc_html__('リストアしない', GACHIREAI_TEXT_DOMAIN),
					'selected' => self::MAP_NONE === $initial,
				),
			);
			foreach ($sources as $source) {
				$options[] = array(
					'value'    => $source['value'],
					'label'    => $source['label'],
					'selected' => $source['value'] === esc_attr($initial),
				);
			}
			$destinations[] = array(
				'dest_label' => esc_html($entry['label']),
				'field_name' => esc_attr(self::RESTORE_MAP_FIELD . '[' . $blog_id . ']'),
				'options'    => $options,
			);
		}
		return array(
			'mapping_action_url'    => esc_url(admin_url('admin-post.php')),
			'mapping_hidden_fields' => $this->hidden_fields(self::ACTION_APPLY, self::NONCE_APPLY),
			'mapping_dest_label'    => esc_html__('リストア先', GACHIREAI_TEXT_DOMAIN),
			'mapping_source_label'  => esc_html__('リストア元', GACHIREAI_TEXT_DOMAIN),
			'mapping_destinations'  => $destinations,
			'mapping_note'          => esc_html(
				sprintf(
					__('バックアップ: %1$s（app %2$s・%3$d 件）', GACHIREAI_TEXT_DOMAIN),
					(string) ($payload['exported_at'] ?? ''),
					(string) ($payload['app_version'] ?? ''),
					count($sites)
				)
			),
			'mapping_button_label'  => esc_html__('リストア', GACHIREAI_TEXT_DOMAIN),
		);
	}
	private function entry_label($entry, string $fallback): string
	{
		if (is_array($entry) && isset($entry['label']) && is_scalar($entry['label'])) {
			$label = trim((string) $entry['label']);
			if ('' !== $label) {
				return $label;
			}
		}
		return $fallback;
	}
	private function export_payload(array $selected, bool $include_common = false): array
	{
		$sites      = array();
		$site_shape = array();
		foreach ($this->site_ids($selected) as $blog_id) {
			$snapshot                   = $this->site_snapshot($blog_id);
			$sites[(string) $blog_id] = $snapshot;
			if (isset($snapshot['settings']) && is_array($snapshot['settings'])) {
				foreach (array_keys($snapshot['settings']) as $key) {
					$site_shape[$key] = true;
				}
			}
		}
		$entries = array();
		if ($include_common) {
			$common = Common_Store::data();
			foreach (array_keys($site_shape) as $key) {
				if (! array_key_exists($key, $common)) {
					$common[$key] = array();
				}
			}
			$entries['0'] = array(
				'label'        => __('共通設定', GACHIREAI_TEXT_DOMAIN),
				'blogname'     => '',
				'settings'     => $common,
				'site_profile' => array(),
			);
		}
		foreach ($sites as $blog_id => $snapshot) {
			$entries[$blog_id] = $snapshot;
		}
		return array(
			'plugin'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'app_version'    => GACHIREAI_APP_VERSION,
			'exported_at'    => gmdate('c'),
			'license'        => array(
				'key'   => Store::key(),
				'cache' => Store::cache(),
			),
			'sites'          => $entries,
		);
	}
	private function site_ids(array $selected): array
	{
		if (! is_multisite()) {
			return array(get_current_blog_id());
		}
		$existing = array_map('intval', get_sites(array('fields' => 'ids', 'number' => 0)));
		sort($existing);
		$ids = array_values(array_intersect(array_map('intval', $selected), $existing));
		sort($ids);
		return array() === $ids ? array(get_current_blog_id()) : $ids;
	}
	private function site_entries(): array
	{
		$ids = is_multisite()
			? array_map('intval', get_sites(array('fields' => 'ids', 'number' => 0)))
			: array(get_current_blog_id());
		sort($ids);
		$entries = array();
		foreach ($ids as $blog_id) {
			$entries[] = array(
				'value' => (string) $blog_id,
				'label' => $this->site_label($blog_id),
			);
		}
		return $entries;
	}
	private function site_label(int $blog_id): string
	{
		if (! is_multisite()) {
			return __('メインサイト', GACHIREAI_TEXT_DOMAIN);
		}
		$site = function_exists('get_site') ? get_site($blog_id) : null;
		if (! is_object($site)) {
			return (string) $blog_id;
		}
		$path = trim((string) ($site->path ?? ''), '/');
		if ('' !== $path) {
			return $path;
		}
		$domain = (string) ($site->domain ?? '');
		$main   = $this->main_domain();
		if ('' !== $main && $domain === $main) {
			return __('メインサイト', GACHIREAI_TEXT_DOMAIN);
		}
		if ('' !== $main && str_ends_with($domain, '.' . $main)) {
			return substr($domain, 0, -strlen('.' . $main));
		}
		return '' !== $domain ? $domain : (string) $blog_id;
	}
	private function main_domain(): string
	{
		if (! function_exists('get_main_site_id') || ! function_exists('get_site')) {
			return '';
		}
		$main = get_site((int) get_main_site_id());
		return is_object($main) ? (string) ($main->domain ?? '') : '';
	}
	private function site_snapshot(int $blog_id): array
	{
		$switched = false;
		if (is_multisite() && get_current_blog_id() !== $blog_id) {
			switch_to_blog($blog_id);
			$switched = true;
		}
		$settings = (new Settings())->all();
		$profile  = (new Site_Profile())->get();
		$blogname = (string) get_option('blogname', '');
		if ($switched) {
			restore_current_blog();
		}
		return array(
			'label'        => $this->site_label($blog_id),
			'blogname'     => $blogname,
			'settings'     => $settings,
			'site_profile' => $profile,
		);
	}
	private function reset_current_site(): void
	{
		$options = array(
			Settings::OPTION_KEY,
			'gachireai_site_profile',
			'gachireai_auto_post_last',
			'gachireai_auto_pipeline',
			'gachireai_migrated_ec_to_scope',
			'gachi_site_profile',
		);
		foreach ($options as $option) {
			delete_option($option);
		}
		wp_unschedule_hook('gachireai_auto_post');
		wp_clear_scheduled_hook('gachireai_retention_cleanup');
		global $wpdb;
		if (! is_object($wpdb)) {
			return;
		}
		$table_suffixes = array(
			'gachireai_threads',
			'gachireai_comments',
			'gachireai_events',
			'gachireai_candidates',
			'gachireai_genres',
			'gachireai_products',
			'gachireai_prices',
			'gachi_threads',
			'gachi_comments',
			'gachi_events',
			'gachi_candidates',
		);
		foreach ($table_suffixes as $suffix) {
			$table = $wpdb->prefix . $suffix;
			$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
			if ((string) $table !== (string) $exists) {
				continue;
			}
			$wpdb->query("TRUNCATE TABLE {$table}");
		}
	}
	private function reset_common(): void
	{
		Common_Store::clear();
	}
	private function result(): string
	{
		return isset($_GET[self::RESULT_ARG])
			? sanitize_key(wp_unslash((string) $_GET[self::RESULT_ARG]))
			: '';
	}
	private function restore_payload(): ?array
	{
		$payload = get_transient(self::PAYLOAD_TRANSIENT_PREFIX . get_current_user_id());
		return is_array($payload) ? $payload : null;
	}
	private function read_upload(): ?array
	{
		if (! isset($_FILES[self::RESTORE_FILE_FIELD]) || ! is_array($_FILES[self::RESTORE_FILE_FIELD])) {
			return null;
		}
		$file = (array) $_FILES[self::RESTORE_FILE_FIELD];
		if (UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
			return null;
		}
		$tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
		if ('' === $tmp || ! is_uploaded_file($tmp)) {
			return null;
		}
		if (self::MAX_UPLOAD_BYTES < (int) ($file['size'] ?? 0)) {
			return null;
		}
		$raw = (string) file_get_contents($tmp);
		if ('' === $raw) {
			return null;
		}
		$data = json_decode($raw, true);
		if (! is_array($data)) {
			return null;
		}
		return $this->validate_payload($data);
	}
	private function validate_payload(array $data): ?array
	{
		if (self::FORMAT !== (string) ($data['plugin'] ?? '')) {
			return null;
		}
		$sites = $data['sites'] ?? null;
		if (! is_array($sites) || array() === $sites) {
			return null;
		}
		$normalized = array();
		foreach ($sites as $blog_id => $entry) {
			$blog_id = (int) $blog_id;
			if (0 > $blog_id || ! is_array($entry)) {
				continue;
			}
			$normalized[(string) $blog_id] = array(
				'label'        => isset($entry['label']) && is_scalar($entry['label']) ? (string) $entry['label'] : (string) $blog_id,
				'blogname'     => isset($entry['blogname']) && is_scalar($entry['blogname']) ? (string) $entry['blogname'] : '',
				'settings'     => isset($entry['settings']) && is_array($entry['settings']) ? $entry['settings'] : array(),
				'site_profile' => isset($entry['site_profile']) && is_array($entry['site_profile']) ? $entry['site_profile'] : array(),
			);
		}
		if (array() === $normalized) {
			return null;
		}
		$license = isset($data['license']) && is_array($data['license']) ? $data['license'] : array();
		return array(
			'plugin'         => self::FORMAT,
			'format_version' => (int) ($data['format_version'] ?? 0),
			'app_version'    => isset($data['app_version']) && is_scalar($data['app_version']) ? (string) $data['app_version'] : '',
			'exported_at'    => isset($data['exported_at']) && is_scalar($data['exported_at']) ? (string) $data['exported_at'] : '',
			'license'        => array(
				'key'   => isset($license['key']) && is_scalar($license['key']) ? (string) $license['key'] : '',
				'cache' => isset($license['cache']) && is_array($license['cache']) ? $license['cache'] : array(),
			),
			'sites'          => $normalized,
		);
	}
	private function apply_backup(array $payload, array $map, bool $overwrite = false): array
	{
		$sites = isset($payload['sites']) && is_array($payload['sites']) ? $payload['sites'] : array();
		$destinations = is_multisite()
			? array_map('intval', get_sites(array('fields' => 'ids', 'number' => 0)))
			: array(get_current_blog_id());
		sort($destinations);
		$destinations = array_map('strval', $destinations);
		if (isset($sites['0'])) {
			$destinations[] = '0';
		}
		$applied = 0;
		$skipped = 0;
		foreach ($destinations as $dest_id) {
			$source = isset($map[(string) $dest_id]) && is_scalar($map[(string) $dest_id])
				? (string) $map[(string) $dest_id]
				: self::MAP_NONE;
			if (self::MAP_NONE === $source || '' === $source) {
				$skipped++;
				continue;
			}
			if (! isset($sites[$source]) || ! is_array($sites[$source])) {
				$skipped++;
				continue;
			}
			$entry = $sites[$source];
			$normalized = $this->normalize_site(
				isset($entry['settings']) && is_array($entry['settings']) ? $entry['settings'] : array(),
				isset($entry['site_profile']) && is_array($entry['site_profile']) ? $entry['site_profile'] : array()
			);
			if ('0' === (string) $dest_id) {
				$current_common = (new Common_Store())->all();
				$merged_common  = $this->merge_settings($current_common, $normalized['settings'], $overwrite);
				(new Common_Store())->replace_all($merged_common);
				$applied++;
				continue;
			}
			$dest_id  = (int) $dest_id;
			$switched = false;
			if (is_multisite() && get_current_blog_id() !== $dest_id) {
				switch_to_blog($dest_id);
				$switched = true;
			}
			$current = (new Settings())->all();
			$merged  = $this->merge_settings($current, $normalized['settings'], $overwrite);
			(new Settings())->replace_all($merged);
			if ('0' !== (string) $source) {
				(new Site_Profile())->save($normalized['site_profile']);
			}
			if ($switched) {
				restore_current_blog();
			}
			$applied++;
		}
		$license = isset($payload['license']) && is_array($payload['license']) ? $payload['license'] : array();
		if (isset($license['key']) && is_scalar($license['key'])) {
			Store::save_key((string) $license['key']);
		}
		if (isset($license['cache']) && is_array($license['cache'])) {
			Store::save_cache($license['cache']);
		}
		$this->record_setting_save(__('バックアップのリストア', GACHIREAI_TEXT_DOMAIN));
		return array(
			'applied' => $applied,
			'skipped' => $skipped,
		);
	}
	private function merge_settings(array $dest, array $src, bool $overwrite): array
	{
		if ($overwrite) {
			return $src;
		}
		foreach ($src as $key => $value) {
			$current = $dest[$key] ?? null;
			if ($this->is_empty_setting($current)) {
				$dest[$key] = is_array($value)
					? $this->merge_settings(is_array($current) ? $current : array(), $value, false)
					: $value;
				continue;
			}
			if (is_array($current) && is_array($value)) {
				$dest[$key] = $this->merge_settings($current, $value, false);
			}
		}
		return $dest;
	}
	private function is_empty_setting($value): bool
	{
		return null === $value || '' === $value || (is_array($value) && array() === $value);
	}
	private function normalize_site(array $settings, array $profile): array
	{
		if (isset($settings['ec']) && is_array($settings['ec'])) {
			$ec = $settings['ec'];
			if (! isset($profile['scope']) || ! is_array($profile['scope'])) {
				$profile['scope'] = array();
			}
			foreach (array('category' => 'category', 'keywords' => 'products') as $from => $to) {
				$value = isset($ec[$from]) && is_scalar($ec[$from]) ? trim((string) $ec[$from]) : '';
				if ('' !== $value) {
					$profile['scope'][$to] = $value;
				}
				unset($ec[$from]);
			}
			$settings['ec'] = $ec;
		}
		$groups = array('reddit', 'fivech', 'youtube', 'hackernews', 'mastodon');
		$carry  = array();
		$gachi  = array();
		foreach ($groups as $group) {
			if (! isset($settings[$group]) || ! is_array($settings[$group])) {
				continue;
			}
			$enabled = isset($settings[$group]['enabled']) && is_scalar($settings[$group]['enabled'])
				? trim((string) $settings[$group]['enabled'])
				: '';
			if ('1' === $enabled) {
				$gachi[] = $group;
			}
			unset($settings[$group]['enabled']);
			foreach (array('retention', 'credit') as $key) {
				$value = isset($settings[$group][$key]) && is_scalar($settings[$group][$key])
					? trim((string) $settings[$group][$key])
					: '';
				if ('retention' === $key && 'permanent' === $value) {
					$value = (string) Source_Settings::DEFAULT_RETENTION_DAYS;
				}
				if ('' !== $value && ! isset($carry[$key])) {
					$carry[$key] = $value;
				}
				unset($settings[$group][$key]);
			}
			unset($settings[$group]['period'], $settings[$group]['show_credit']);
		}
		if (array() !== $carry) {
			if (! isset($settings[Source_Settings::GROUP]) || ! is_array($settings[Source_Settings::GROUP])) {
				$settings[Source_Settings::GROUP] = array();
			}
			$settings[Source_Settings::GROUP] = array_merge($carry, $settings[Source_Settings::GROUP]);
		}
		if (array() !== $gachi) {
			if (! isset($settings[Source_Settings::PARTNER_GROUP]) || ! is_array($settings[Source_Settings::PARTNER_GROUP])) {
				$settings[Source_Settings::PARTNER_GROUP] = array();
			}
			$current = isset($settings[Source_Settings::PARTNER_GROUP][Source_Settings::PARTNER_GACHI_KEY])
				&& is_scalar($settings[Source_Settings::PARTNER_GROUP][Source_Settings::PARTNER_GACHI_KEY])
				? trim((string) $settings[Source_Settings::PARTNER_GROUP][Source_Settings::PARTNER_GACHI_KEY])
				: '';
			if ('' === $current) {
				$settings[Source_Settings::PARTNER_GROUP][Source_Settings::PARTNER_GACHI_KEY] = implode(',', $gachi);
			}
		}
		if (isset($settings['article']) && is_array($settings['article'])) {
			unset($settings['article']['post_times']);
		}
		return array(
			'settings'     => $settings,
			'site_profile' => $profile,
		);
	}
}

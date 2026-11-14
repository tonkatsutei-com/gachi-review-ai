<?php
/**
 * Plugin Name:       ガチレビュー.AI
 * Description:       商品リサーチから口コミ収集、レビュー記事の生成を、AIを使って自動化します。
 * Version:           0.241.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Tonkatsutei
 * License:           GPL-2.0-or-later
 * Text Domain:       gachi-review-ai
 *
 * @package gachi-review-ai
 */
declare(strict_types=1);
if (!defined('ABSPATH')) {
	exit;
}
require_once __DIR__ . '/includes/version.php';
define('GACHIREAI_PLUGIN_FILE', __FILE__);
define('GACHIREAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GACHIREAI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GACHIREAI_TEXT_DOMAIN', 'gachi-review-ai');
define('GACHIREAI_DB_VERSION', '1.13.0');
if (! defined('GACHIREAI_LLM_GATEWAY')) {
	define('GACHIREAI_LLM_GATEWAY', 'https://tonkatsutei.com/tools_api/gachi-review-ai/');
}
if (! defined('GACHIREAI_IMAGE_GATEWAY')) {
	define('GACHIREAI_IMAGE_GATEWAY', 'https://tonkatsutei.com/tools_api/gachi-review-ai/');
}
if (! defined('GACHIREAI_AMAZON_CATEGORY_GATEWAY')) {
	define('GACHIREAI_AMAZON_CATEGORY_GATEWAY', 'https://tonkatsutei.com/tools_api/gachi-review-ai/');
}
require_once GACHIREAI_PLUGIN_DIR . 'includes/bootstrap.php';
add_action(
	'plugins_loaded',
	static function () {
		$site    = (array) get_option('active_plugins', array());
		$network = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array();
		$active  = static function (string $plugin) use ($site, $network): bool {
			return in_array($plugin, $site, true) || in_array($plugin, $network, true);
		};
		if (! $active('free-amzn-creators-api/free-amzn-creators-api.php')) {
			require_once GACHIREAI_PLUGIN_DIR . 'includes/scraping/class-free-amzn-creators-api-client.php';
			require_once GACHIREAI_PLUGIN_DIR . 'includes/scraping/class-free-amzn-creators-api.php';
		}
		if (! $active('get-ec-reviews-api/get-ec-reviews-api.php')) {
			require_once GACHIREAI_PLUGIN_DIR . 'includes/scraping/class-get-ec-reviews-api-client.php';
			require_once GACHIREAI_PLUGIN_DIR . 'includes/scraping/class-get-ec-reviews-api.php';
		}
		if (! $active('get-sns-reviews-api/get-sns-reviews-api.php')) {
			require_once GACHIREAI_PLUGIN_DIR . 'includes/scraping/class-get-sns-reviews-api-client.php';
			require_once GACHIREAI_PLUGIN_DIR . 'includes/scraping/class-get-sns-reviews-api.php';
		}
	},
	0
);
register_activation_hook(__FILE__, array('Tonkatsutei\Gachi_Review_AI\Activator', 'activate'));
add_action(
	'plugins_loaded',
	static function () {
		\Tonkatsutei\Gachi_Review_AI\Plugin::instance()->init();
	}
);

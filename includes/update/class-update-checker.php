<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Update;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Update_Checker {
	public const REPOSITORY_URL = 'https://github.com/tonkatsutei-com/gachi-review-ai';
	public const SLUG = 'gachi-review-ai';
	public const CHECK_PERIOD = 12;
	public static function register(): void {
		if ( defined( 'GACHIREAI_AUTO_UPDATE' ) && ! GACHIREAI_AUTO_UPDATE ) {
			return;
		}
		$loader = GACHIREAI_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return;
		}
		require_once $loader;
		if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPOSITORY_URL,
			GACHIREAI_PLUGIN_FILE,
			self::SLUG,
			self::CHECK_PERIOD
		);
		$checker->setBranch( 'main' );
	}
}

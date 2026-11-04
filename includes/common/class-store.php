<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Common;
use Tonkatsutei\Gachi_Review_AI\Settings;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Store extends Settings {
	public const OPTION_KEY = 'gachireai_common_settings';
	protected bool $common_fallback = false;
	private static ?array $cache = null;
	public static function data(): array {
		if ( null === self::$cache ) {
			$saved       = get_site_option( self::OPTION_KEY, array() );
			self::$cache = is_array( $saved ) ? $saved : array();
		}
		return self::$cache;
	}
	protected function load_data(): array {
		return self::data();
	}
	protected function persist(): void {
		update_site_option( self::OPTION_KEY, $this->data );
		self::$cache = $this->data;
	}
	public static function clear(): void {
		delete_site_option( self::OPTION_KEY );
		self::$cache = null;
	}
}

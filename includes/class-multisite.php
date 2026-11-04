<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Multisite {
	public static function is_enabled_on_current_blog(): bool {
		if ( ! is_multisite() ) {
			return true;
		}
		if ( self::allows_multisite() ) {
			return true;
		}
		return self::main_site_id() === get_current_blog_id();
	}
	public static function should_show_network_notice(): bool {
		return is_multisite() && ! self::allows_multisite();
	}
	public static function render_network_notice(): void {
		if ( ! self::should_show_network_notice() ) {
			return;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_network' ) ) {
			return;
		}
		try {
			$html = ( new Div_Renderer() )->render(
				'admin/partials/notice',
				array(
					'type' => 'warning',
					'text' => esc_html__( 'gachi-review-ai のマルチサイト機能は PRO ライセンスでのみ利用できます。現在のライセンスではメインサイトのみ動作します。PRO にすると、ネットワーク内のすべてのサイトで利用できます。', GACHIREAI_TEXT_DOMAIN ),
				)
			);
		} catch ( \RuntimeException $e ) {
			return;
		}
		echo $html; 
	}
	private static function allows_multisite(): bool {
		return Status::capabilities()->can_use_multisite();
	}
	public static function is_main_site(): bool {
		return is_multisite() && self::main_site_id() === get_current_blog_id();
	}
	private static function main_site_id(): int {
		if ( function_exists( 'get_main_site_id' ) ) {
			return (int) get_main_site_id();
		}
		if ( function_exists( 'get_current_site' ) ) {
			$site = get_current_site();
			if ( is_object( $site ) && isset( $site->blog_id ) ) {
				return (int) $site->blog_id;
			}
		}
		return 1;
	}
}

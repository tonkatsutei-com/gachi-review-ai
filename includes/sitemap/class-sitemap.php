<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Sitemap;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Multisite;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Sitemap {
	public const PROVIDER_NAME = 'gachireai-subsites';
	private const SITEMAP_FILE = 'wp-sitemap.xml';
	public static function register(): void {
		if ( ! self::should_register() ) {
			return;
		}
		add_action( 'wp_sitemaps_init', array( self::class, 'add_provider' ) );
	}
	public static function add_provider( $wp_sitemaps ): void {
		if ( ! is_object( $wp_sitemaps ) || ! isset( $wp_sitemaps->registry ) ) {
			return;
		}
		if ( ! is_object( $wp_sitemaps->registry ) || ! method_exists( $wp_sitemaps->registry, 'add_provider' ) ) {
			return;
		}
		$urls = self::subsite_sitemap_urls();
		if ( array() === $urls ) {
			return;
		}
		$wp_sitemaps->registry->add_provider(
			self::PROVIDER_NAME,
			new Subsite_Provider( $urls )
		);
	}
	public static function should_register(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( function_exists( 'is_subdomain_install' ) && is_subdomain_install() ) {
			return false;
		}
		if ( ! Multisite::is_main_site() ) {
			return false;
		}
		return Status::capabilities()->can_use_multisite();
	}
	public static function subsite_sitemap_urls(): array {
		if ( ! is_multisite() ) {
			return array();
		}
		$main = function_exists( 'get_main_site_id' )
			? (int) get_main_site_id()
			: (int) get_current_site()->blog_id;
		$urls  = array();
		$sites = get_sites(
			array(
				'public'   => 1,
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
				'number'   => 0,
			)
		);
		foreach ( (array) $sites as $site ) {
			$blog_id = isset( $site->blog_id ) ? (int) $site->blog_id : 0;
			if ( $blog_id <= 0 || $blog_id === $main ) {
				continue;
			}
			$home = get_home_url( $blog_id );
			if ( ! is_string( $home ) || '' === $home ) {
				continue;
			}
			$urls[] = trailingslashit( $home ) . self::SITEMAP_FILE;
		}
		return $urls;
	}
}

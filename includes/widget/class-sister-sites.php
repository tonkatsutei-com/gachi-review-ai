<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Widget;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Sister_Sites {
	public const WIDGET_ID_BASE = 'gachireai_sister_sites';
	private const MODE_OPTION = 'gachireai_sister_sites_mode';
	public const MODE_SIDEBAR = 'sidebar';
	public const MODE_CONTENT = 'content';
	private const IMAGE_SIZE = 'medium';
	private const DESCRIPTION_MAX = 160;
	private static bool $content_appended = false;
	public static function register(): void {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'widgets_init', array( Sister_Sites_Widget::class, 'register' ) );
		add_filter( 'the_content', array( self::class, 'append_to_content' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_front' ) );
		add_action( 'admin_init', array( self::class, 'maybe_auto_install' ) );
	}
	public static function enqueue_front(): void {
		if ( ! is_multisite() ) {
			return;
		}
		$path = GACHIREAI_PLUGIN_DIR . 'assets/css/sister-sites.css';
		if ( ! is_readable( $path ) ) {
			return;
		}
		wp_enqueue_style(
			'gachireai-sister-sites',
			GACHIREAI_PLUGIN_URL . 'assets/css/sister-sites.css',
			array(),
			(string) filemtime( $path )
		);
	}
	public static function append_to_content( $content ) {
		if ( is_admin() || ! is_multisite() || self::$content_appended ) {
			return $content;
		}
		if ( self::MODE_CONTENT !== (string) get_option( self::MODE_OPTION, '' ) ) {
			return $content;
		}
		if ( ! is_string( $content ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		self::$content_appended = true;
		return $content . self::render( __( '姉妹サイト', GACHIREAI_TEXT_DOMAIN ) );
	}
	public static function sites(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		if ( ! is_multisite() ) {
			return $cache;
		}
		$current = (int) get_current_blog_id();
		$all     = get_sites(
			array(
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
				'number'   => 0,
			)
		);
		foreach ( (array) $all as $site ) {
			$blog_id = isset( $site->blog_id ) ? (int) $site->blog_id : 0;
			if ( $blog_id <= 0 || $blog_id === $current ) {
				continue;
			}
			$item = self::site_item( $blog_id );
			if ( null !== $item ) {
				$cache[] = $item;
			}
		}
		return $cache;
	}
	private static function site_item( int $blog_id ): ?array {
		$details       = function_exists( 'get_blog_details' ) ? get_blog_details( $blog_id ) : null;
		$fallback_name = ( is_object( $details ) && isset( $details->blogname ) ) ? (string) $details->blogname : '';
		$url           = ( is_object( $details ) && isset( $details->home ) ) ? (string) $details->home : '';
		if ( '' === $url ) {
			$url = (string) get_home_url( $blog_id );
		}
		$name        = '';
		$description = '';
		$image_url   = '';
		switch_to_blog( $blog_id );
		try {
			$profile  = get_option( 'gachireai_site_profile', array() );
			$elements = ( is_array( $profile ) && isset( $profile['site_elements'] ) && is_array( $profile['site_elements'] ) ) ? $profile['site_elements'] : array();
			$images   = ( is_array( $profile ) && isset( $profile['site_images'] ) && is_array( $profile['site_images'] ) ) ? $profile['site_images'] : array();
			$name        = ( isset( $elements['site_name'] ) && is_scalar( $elements['site_name'] ) ) ? trim( (string) $elements['site_name'] ) : '';
			$description = ( isset( $elements['description'] ) && is_scalar( $elements['description'] ) ) ? trim( (string) $elements['description'] ) : '';
			$image_id = ( isset( $images['top_eyecatch'] ) && is_scalar( $images['top_eyecatch'] ) ) ? absint( (string) $images['top_eyecatch'] ) : 0;
			if ( 0 < $image_id ) {
				$base_id = (int) get_post_meta( $image_id, '_gachireai_image_base_attachment_id', true );
				if ( 0 < $base_id ) {
					$image_id = $base_id;
				}
				$resolved  = wp_get_attachment_image_url( $image_id, self::IMAGE_SIZE );
				$image_url = is_string( $resolved ) ? $resolved : '';
			}
		} finally {
			restore_current_blog();
		}
		if ( '' === $name ) {
			$name = $fallback_name;
		}
		if ( '' === $name ) {
			return null;
		}
		$description = preg_replace( '/\s+/u', ' ', $description );
		$description = is_string( $description ) ? trim( $description ) : '';
		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX ) {
			$description = mb_substr( $description, 0, self::DESCRIPTION_MAX ) . '…';
		}
		return array(
			'site_url'         => esc_url( $url ),
			'site_name'        => esc_html( $name ),
			'has_image'        => '' !== $image_url,
			'image_url'        => esc_url( $image_url ),
			'has_description'  => '' !== $description,
			'site_description' => esc_html( $description ),
		);
	}
	public static function render( string $title = '', ?array $sites = null ): string {
		if ( null === $sites ) {
			$sites = self::sites();
		}
		if ( array() === $sites ) {
			return '';
		}
		$title = trim( $title );
		try {
			return ( new Div_Renderer() )->render(
				'front/sister-sites',
				array(
					'title'     => esc_html( $title ),
					'has_title' => '' !== $title,
					'sites'     => $sites,
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	public static function ensure_installed(): void {
		if ( ! is_multisite() ) {
			return;
		}
		if ( self::is_installed() ) {
			return;
		}
		if ( self::add_to_sidebar() ) {
			update_option( self::MODE_OPTION, self::MODE_SIDEBAR );
			return;
		}
		update_option( self::MODE_OPTION, self::MODE_CONTENT );
	}
	public static function maybe_auto_install(): void {
		if ( ! is_multisite() ) {
			return;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::ensure_installed();
	}
	private static function is_installed(): bool {
		$mode = (string) get_option( self::MODE_OPTION, '' );
		if ( self::MODE_SIDEBAR === $mode || self::MODE_CONTENT === $mode ) {
			return true;
		}
		return self::has_widget_instance();
	}
	private static function has_widget_instance(): bool {
		$sidebars = wp_get_sidebars_widgets();
		if ( ! is_array( $sidebars ) ) {
			return false;
		}
		foreach ( $sidebars as $id => $widgets ) {
			if ( 'array_version' === $id || ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget ) {
				if ( is_string( $widget ) && str_starts_with( $widget, self::WIDGET_ID_BASE . '-' ) ) {
					return true;
				}
			}
		}
		return false;
	}
	private static function add_to_sidebar(): bool {
		global $wp_registered_sidebars;
		$registered = is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : array();
		$target     = self::pick_sidebar( array_keys( $registered ) );
		if ( null === $target ) {
			return false;
		}
		$instances = get_option( 'widget_' . self::WIDGET_ID_BASE, array() );
		if ( ! is_array( $instances ) ) {
			$instances = array();
		}
		$index                     = self::free_instance_index( $instances );
		$instances[ $index ]       = array( 'title' => __( '姉妹サイト', GACHIREAI_TEXT_DOMAIN ) );
		$instances['_multiwidget'] = 1;
		update_option( 'widget_' . self::WIDGET_ID_BASE, $instances );
		$sidebars = wp_get_sidebars_widgets();
		if ( ! is_array( $sidebars ) ) {
			$sidebars = array();
		}
		$widgets   = ( isset( $sidebars[ $target ] ) && is_array( $sidebars[ $target ] ) ) ? $sidebars[ $target ] : array();
		$widget_id = self::WIDGET_ID_BASE . '-' . $index;
		if ( ! in_array( $widget_id, $widgets, true ) ) {
			$widgets[] = $widget_id;
		}
		$sidebars[ $target ] = $widgets;
		wp_set_sidebars_widgets( $sidebars );
		return true;
	}
	private static function pick_sidebar( array $ids ): ?string {
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ): bool {
					return is_string( $id ) && '' !== $id && 'wp_inactive_widgets' !== $id;
				}
			)
		);
		if ( array() === $ids ) {
			return null;
		}
		$preferred = array( 'sidebar-1', 'sidebar', 'sidebar-primary', 'primary', 'main', 'widget-area-1' );
		foreach ( $preferred as $want ) {
			if ( in_array( $want, $ids, true ) ) {
				return $want;
			}
		}
		return (string) $ids[0];
	}
	private static function free_instance_index( array $instances ): int {
		$index = 2;
		while ( isset( $instances[ $index ] ) ) {
			++$index;
		}
		return $index;
	}
}

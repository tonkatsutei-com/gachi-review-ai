<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Widget;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Disclosure {
	public const WIDGET_ID_BASE = 'gachireai_disclosure';
	private const MODE_OPTION = 'gachireai_disclosure_mode';
	public const MODE_SIDEBAR = 'sidebar';
	public const MODE_CONTENT = 'content';
	private static bool $content_prepended = false;
	public static function register(): void {
		add_action( 'widgets_init', array( Disclosure_Widget::class, 'register' ) );
		add_filter( 'the_content', array( self::class, 'prepend_to_content' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_front' ) );
		add_action( 'admin_init', array( self::class, 'maybe_auto_install' ) );
	}
	public static function enqueue_front(): void {
		$path = GACHIREAI_PLUGIN_DIR . 'assets/css/disclosure.css';
		if ( ! is_readable( $path ) ) {
			return;
		}
		wp_enqueue_style(
			'gachireai-disclosure',
			GACHIREAI_PLUGIN_URL . 'assets/css/disclosure.css',
			array(),
			(string) filemtime( $path )
		);
	}
	public static function prepend_to_content( $content ) {
		if ( is_admin() || self::$content_prepended ) {
			return $content;
		}
		if ( self::MODE_CONTENT !== (string) get_option( self::MODE_OPTION, '' ) ) {
			return $content;
		}
		if ( self::is_in_active_sidebar() ) {
			return $content;
		}
		if ( ! is_string( $content ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		self::$content_prepended = true;
		return self::render( self::instance_title() ) . $content;
	}
	public static function render( string $title = '' ): string {
		$settings = new Settings();
		$items    = array();
		foreach ( $settings->disclosure() as $item ) {
			if ( '1' !== (string) ( $item['enabled'] ?? '' ) ) {
				continue;
			}
			$text = trim( (string) ( $item['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$items[] = array( 'text' => esc_html( $text ) );
		}
		if ( array() === $items ) {
			return '';
		}
		$title = trim( $title );
		try {
			return ( new Div_Renderer() )->render(
				'front/disclosure-widget',
				array(
					'title'            => esc_html( $title ),
					'has_title'        => '' !== $title,
					'disclosure_items' => $items,
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	public static function ensure_installed(): void {
		if ( ! did_action( 'widgets_init' ) ) {
			return;
		}
		self::migrate_titles();
		if ( self::is_installed() ) {
			return;
		}
		$index = self::ensure_instance();
		if ( self::place_in_sidebar( $index ) ) {
			update_option( self::MODE_OPTION, self::MODE_SIDEBAR );
			return;
		}
		update_option( self::MODE_OPTION, self::MODE_CONTENT );
	}
	public static function sync_titles( string $heading ): void {
		$heading = trim( $heading );
		if ( '' === $heading ) {
			return;
		}
		$option    = 'widget_' . self::WIDGET_ID_BASE;
		$instances = get_option( $option, array() );
		if ( ! is_array( $instances ) || array() === $instances ) {
			return;
		}
		$changed = false;
		foreach ( $instances as $key => $instance ) {
			if ( ! is_numeric( $key ) || ! is_array( $instance ) ) {
				continue;
			}
			if ( (string) ( $instance['title'] ?? '' ) !== $heading ) {
				$instances[ $key ]['title'] = $heading;
				$changed                    = true;
			}
		}
		if ( $changed ) {
			update_option( $option, $instances );
		}
	}
	public static function purge_caches(): void {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'delete_all_theme_caches' ) ) {
			delete_all_theme_caches();
		}
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}
	public static function maybe_auto_install(): void {
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
	private static function is_in_active_sidebar(): bool {
		$sidebars = wp_get_sidebars_widgets();
		if ( ! is_array( $sidebars ) ) {
			return false;
		}
		foreach ( $sidebars as $id => $widgets ) {
			if ( 'array_version' === $id || 'wp_inactive_widgets' === $id || ! is_array( $widgets ) ) {
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
	private static function migrate_titles(): void {
		if ( get_option( 'gachireai_disclosure_title_migrated' ) ) {
			return;
		}
		$option    = 'widget_' . self::WIDGET_ID_BASE;
		$instances = get_option( $option, array() );
		if ( is_array( $instances ) ) {
			$changed = false;
			foreach ( $instances as $key => $instance ) {
				if ( is_numeric( $key ) && is_array( $instance ) && '' === trim( (string) ( $instance['title'] ?? '' ) ) ) {
					$instances[ $key ]['title'] = ( new Settings() )->disclosure_heading();
					$changed                    = true;
				}
			}
			if ( $changed ) {
				update_option( $option, $instances );
			}
		}
		update_option( 'gachireai_disclosure_title_migrated', '1' );
	}
	private static function ensure_instance(): int {
		$option    = 'widget_' . self::WIDGET_ID_BASE;
		$instances = get_option( $option, array() );
		if ( ! is_array( $instances ) ) {
			$instances = array();
		}
		foreach ( $instances as $key => $instance ) {
			if ( is_numeric( $key ) && is_array( $instance ) ) {
				return (int) $key;
			}
		}
		$index                     = self::free_instance_index( $instances );
		$instances[ $index ]       = array( 'title' => ( new Settings() )->disclosure_heading() );
		$instances['_multiwidget'] = 1;
		update_option( $option, $instances );
		return $index;
	}
	private static function instance_title(): string {
		$instances = get_option( 'widget_' . self::WIDGET_ID_BASE, array() );
		if ( ! is_array( $instances ) ) {
			return '';
		}
		foreach ( $instances as $key => $instance ) {
			if ( is_numeric( $key ) && is_array( $instance ) ) {
				return isset( $instance['title'] ) ? (string) $instance['title'] : '';
			}
		}
		return '';
	}
	private static function place_in_sidebar( int $index ): bool {
		global $wp_registered_sidebars;
		$registered = is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : array();
		$target     = self::pick_sidebar( array_keys( $registered ) );
		if ( null === $target ) {
			return false;
		}
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

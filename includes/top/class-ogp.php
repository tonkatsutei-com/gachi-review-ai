<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Top;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Ogp {
	private const TOP_EYECATCH_KEY = 'top_eyecatch';
	private const DESCRIPTION_WORDS = 55;
	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'render' ), 2 );
	}
	public static function render(): void {
		if ( is_admin() || is_paged() ) {
			return;
		}
		$settings = new Settings();
		if ( '1' !== $settings->image()['ogp'] ) {
			return;
		}
		if ( is_front_page() ) {
			self::render_top();
			return;
		}
		if ( is_singular( 'post' ) ) {
			self::render_article();
		}
	}
	private static function render_top(): void {
		$profile     = new Site_Profile();
		$site_images = $profile->get_site_images();
		$value       = $site_images[ self::TOP_EYECATCH_KEY ] ?? '';
		$image_id    = is_scalar( $value ) ? absint( (string) $value ) : 0;
		$image_url   = 0 < $image_id && function_exists( 'wp_get_attachment_image_url' )
			? (string) wp_get_attachment_image_url( $image_id, 'full' )
			: '';
		self::output(
			'website',
			(string) get_bloginfo( 'name' ),
			(string) get_bloginfo( 'description' ),
			home_url( '/' ),
			$image_url
		);
	}
	private static function render_article(): void {
		$post_id = (int) get_queried_object_id();
		if ( 0 >= $post_id ) {
			return;
		}
		self::output(
			'article',
			(string) get_the_title( $post_id ),
			self::article_description( $post_id ),
			(string) get_permalink( $post_id ),
			self::article_image_url( $post_id )
		);
	}
	private static function article_image_url( int $post_id ): string {
		$image_id = function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) : 0;
		if ( 0 >= $image_id || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}
		return (string) wp_get_attachment_image_url( $image_id, 'full' );
	}
	private static function article_description( int $post_id ): string {
		$excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
		if ( '' !== $excerpt ) {
			return wp_strip_all_tags( $excerpt );
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' === trim( $content ) ) {
			return '';
		}
		return wp_trim_words( strip_shortcodes( $content ), self::DESCRIPTION_WORDS, '…' );
	}
	private static function output( string $og_type, string $title, string $description, string $url, string $image_url ): void {
		$site_name = (string) get_bloginfo( 'name' );
		$has_image = '' !== $image_url;
		$has_desc  = '' !== trim( $description );
		$model = array(
			'og_type'         => esc_attr( $og_type ),
			'og_title'        => esc_attr( $title ),
			'og_description'  => esc_attr( $description ),
			'has_description' => $has_desc,
			'og_url'          => esc_url( $url ),
			'og_image'        => esc_url( $image_url ),
			'has_image'       => $has_image,
			'og_site_name'    => esc_attr( $site_name ),
			'og_locale'       => esc_attr( (string) get_locale() ),
			'twitter_card'    => $has_image ? 'summary_large_image' : 'summary',
		);
		try {
			echo ( new Div_Renderer() )->render( 'front/ogp', $model ); 
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

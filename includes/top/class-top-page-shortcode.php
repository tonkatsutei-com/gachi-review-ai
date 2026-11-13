<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Top;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Top_Page_Shortcode {
	public const TAG_PROFILE_PHOTO = 'gachireai_profile_photo';
	public const TAG_TOP_EYECATCH = 'gachireai_top_eyecatch';
	private const SITE_IMAGES_KEY = 'site_images';
	private const TOP_EYECATCH_KEY = 'top_eyecatch';
	public static function register(): void {
		add_shortcode( self::TAG_PROFILE_PHOTO, array( self::class, 'render_profile_photo' ) );
		add_shortcode( self::TAG_TOP_EYECATCH, array( self::class, 'render_top_eyecatch' ) );
	}
	public static function render_profile_photo( $atts = array(), $content = null, $tag = '' ): string {
		$profile = new Site_Profile();
		$persona = $profile->get_my_persona();
		$value   = $persona['photo'] ?? '';
		$photo   = is_scalar( $value ) ? absint( (string) $value ) : 0;
		$alt     = is_scalar( $persona['name'] ?? '' ) ? (string) $persona['name'] : '';
		return self::render_image( $photo, 'medium', $alt, 'top/profile-photo' );
	}
	public static function render_top_eyecatch( $atts = array(), $content = null, $tag = '' ): string {
		$settings = new Settings();
		$top_hero = $settings->image()['top_hero'];
		if ( Settings::TOP_HERO_HIDDEN === $top_hero ) {
			return '';
		}
		$profile     = new Site_Profile();
		$site_images = $profile->get_site_images();
		$value       = $site_images[ self::TOP_EYECATCH_KEY ] ?? '';
		$image_id    = is_scalar( $value ) ? absint( (string) $value ) : 0;
		$elements    = $profile->get_site_elements();
		$site_name   = is_scalar( $elements['site_name'] ?? '' ) ? (string) $elements['site_name'] : '';
		$title       = Settings::TOP_HERO_WITH_TITLE === $top_hero ? $site_name : '';
		$html = self::render_image( $image_id, 'full', $site_name, 'top/top-eyecatch', $title );
		if ( '' === $html ) {
			return '';
		}
		return $html . self::annotation_html( $settings->image_annotation()['image_top'] ?? array() );
	}
	private static function annotation_html( array $item ): string {
		if ( '1' !== (string) ( $item['enabled'] ?? '' ) ) {
			return '';
		}
		$text = trim( (string) ( $item['text'] ?? '' ) );
		if ( '' === $text ) {
			return '';
		}
		try {
			return trim(
				( new Div_Renderer() )->render(
					'article/eyecatch-notice',
					array( 'notice_text' => esc_html( $text ) )
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	private static function render_image( int $attachment_id, string $size, string $alt, string $template, string $title = '' ): string {
		if ( 0 >= $attachment_id || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}
		$url = (string) wp_get_attachment_image_url( $attachment_id, $size );
		if ( '' === $url ) {
			return '';
		}
		try {
			return ( new Div_Renderer() )->render(
				$template,
				array(
					'image_url' => esc_url( $url ),
					'image_alt' => esc_attr( $alt ),
					'has_title' => '' !== $title,
					'title'     => esc_html( $title ),
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}

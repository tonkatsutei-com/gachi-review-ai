<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Eyecatch_Shortcode {
	public const TAG_IMAGE = 'gachireai_eyecatch';
	public const TAG_NOTICE = 'gachireai_eyecatch_notice';
	public const META_ATTACHMENT_ID = 'gachireai_eyecatch_attachment_id';
	private const TEMPLATE_BLOCK = 'article/block-shortcode';
	public static function register(): void {
		add_shortcode( self::TAG_IMAGE, array( self::class, 'render_image' ) );
		add_shortcode( self::TAG_NOTICE, array( self::class, 'render_notice' ) );
	}
	public static function prefix_blocks(): string {
		try {
			$image  = self::shortcode_block( '[' . self::TAG_IMAGE . ']' );
			$notice = self::shortcode_block( '[' . self::TAG_NOTICE . ']' );
		} catch ( \Throwable $e ) {
			return '';
		}
		return trim( $image . "\n\n" . $notice );
	}
	public static function render_image( $atts = array(), $content = null, $tag = '' ): string {
		$settings = new Settings();
		if ( '1' !== $settings->image()['show_eyecatch'] ) {
			return '';
		}
		$post_id = (int) get_the_ID();
		if ( 0 >= $post_id ) {
			return '';
		}
		$value      = get_post_meta( $post_id, self::META_ATTACHMENT_ID, true );
		$attachment = is_scalar( $value ) ? absint( (string) $value ) : 0;
		if ( 0 >= $attachment || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}
		$url = (string) wp_get_attachment_image_url( $attachment, 'full' );
		if ( '' === $url ) {
			return '';
		}
		try {
			return trim(
				( new Div_Renderer() )->render(
					'article/eyecatch',
					array(
						'image_url' => esc_url( $url ),
						'image_alt' => esc_attr( (string) get_the_title( $post_id ) ),
					)
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	public static function render_notice( $atts = array(), $content = null, $tag = '' ): string {
		$settings = new Settings();
		$image    = $settings->image();
		if ( '1' !== $image['disclosure'] ) {
			return '';
		}
		$text = trim( (string) ( $image['disclosure_text'] ?? '' ) );
		if ( '' === $text ) {
			$text = Settings::DEFAULT_DISCLOSURE_TEXT;
		}
		try {
			return trim(
				( new Div_Renderer() )->render(
					'article/eyecatch-notice',
					array(
						'notice_text' => esc_html( $text ),
					)
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	private static function shortcode_block( string $shortcode ): string {
		return trim(
			( new Div_Renderer() )->render(
				self::TEMPLATE_BLOCK,
				array( 'block_shortcode' => $shortcode )
			)
		);
	}
}

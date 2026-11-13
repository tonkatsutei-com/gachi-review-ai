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
			return trim( $image . "\n\n" . $notice );
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	public static function render_image( $atts = array(), $content = null, $tag = '' ): string {
		$url = self::image_url();
		if ( '' === $url ) {
			return '';
		}
		try {
			return trim(
				( new Div_Renderer() )->render(
					'article/eyecatch',
					array(
						'image_url' => esc_url( $url ),
						'image_alt' => esc_attr( (string) get_the_title( (int) get_the_ID() ) ),
					)
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	private static function image_url(): string {
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
		return (string) wp_get_attachment_image_url( $attachment, 'full' );
	}
	public static function render_notice( $atts = array(), $content = null, $tag = '' ): string {
		return self::notice_html();
	}
	public static function notice_html(): string {
		if ( '' === self::image_url() ) {
			return '';
		}
		$item = ( new Settings() )->image_annotation()['image_article'] ?? array();
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
	private static function shortcode_block( string $shortcode ): string {
		return trim(
			( new Div_Renderer() )->render(
				self::TEMPLATE_BLOCK,
				array( 'block_shortcode' => $shortcode )
			)
		);
	}
}

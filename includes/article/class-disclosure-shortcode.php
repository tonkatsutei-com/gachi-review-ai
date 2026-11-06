<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Disclosure_Shortcode {
	public const TAG = 'gachireai_disclosure';
	private const TEMPLATE_BLOCK = 'article/block-shortcode';
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}
	public static function block( string $position = Settings::DISCLOSURE_POSITION_TOP ): string {
		if ( ! in_array( $position, array( Settings::DISCLOSURE_POSITION_TOP, Settings::DISCLOSURE_POSITION_BOTTOM ), true ) ) {
			return '';
		}
		try {
			return self::shortcode_block( '[' . self::TAG . ' position="' . $position . '"]' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	public static function render( $atts, $content = null, $tag = '' ): string {
		$atts = shortcode_atts(
			array( 'position' => Settings::DISCLOSURE_POSITION_TOP ),
			$atts,
			self::TAG
		);
		$position = (string) ( $atts['position'] ?? '' );
		if ( ! in_array( $position, array( Settings::DISCLOSURE_POSITION_TOP, Settings::DISCLOSURE_POSITION_BOTTOM ), true ) ) {
			return '';
		}
		$settings = new Settings();
		$items    = array();
		foreach ( $settings->disclosure() as $item ) {
			if ( '1' !== (string) ( $item['enabled'] ?? '' ) || $position !== (string) ( $item['position'] ?? '' ) ) {
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
		try {
			return trim(
				( new Div_Renderer() )->render(
					'article/disclosure',
					array( 'disclosure_items' => $items )
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

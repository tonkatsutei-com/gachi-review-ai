<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Card_Settings {
	public const GROUP_KEY = 'card';
	public const KEY_PATTERN = 'pattern';
	public const KEY_SHOW_PRICE = 'show_price';
	public const KEY_SHOW_NAME = 'show_name';
	public const KEY_SHOW_RAKUTEN = 'show_rakuten';
	public const KEY_SHOW_YAHOO = 'show_yahoo';
	public const KEY_SHOW_AMAZON = 'show_amazon';
	public const KEY_SHOW_DISCLOSURE = 'show_disclosure';
	public const KEY_NAME_COLOR = 'name_color';
	public const KEY_FRAME_COLOR = 'frame_color';
	public const KEY_BUTTON_COLOR = 'button_color';
	public const KEY_DECORATION_COLOR = 'decoration_color';
	public const KEY_POSITIONS = 'positions';
	public const POSITION_BEFORE_CONTENT = 'before_content';
	public const POSITION_AFTER_INTRO = 'after_intro';
	public const POSITION_AFTER_EACH_H2 = 'after_each_h2';
	public const POSITION_MIDDLE = 'middle';
	public const POSITION_AFTER_CONTENT = 'after_content';
	public static function normalize( array $raw ): array {
		$pattern = isset( $raw[ self::KEY_PATTERN ] ) && is_scalar( $raw[ self::KEY_PATTERN ] )
			? (string) $raw[ self::KEY_PATTERN ]
			: '';
		$pattern = Box_Renderer::normalize_pattern( $pattern );
		if ( ! Status::capabilities()->can_use_card_pattern( $pattern ) ) {
			$pattern = Box_Renderer::DEFAULT_PATTERN;
		}
		return array(
			self::KEY_PATTERN => $pattern,
		);
	}
	public static function pattern( Settings $settings ): string {
		$card = self::card( $settings );
		$pattern = Box_Renderer::normalize_pattern(
			isset( $card[ self::KEY_PATTERN ] ) && is_scalar( $card[ self::KEY_PATTERN ] )
				? (string) $card[ self::KEY_PATTERN ]
				: ''
		);
		if ( ! Status::capabilities()->can_use_card_pattern( $pattern ) ) {
			$pattern = Box_Renderer::DEFAULT_PATTERN;
		}
		return $pattern;
	}
	public static function position_options(): array {
		return array(
			self::POSITION_BEFORE_CONTENT => __( 'タイトル下（本文の先頭）', GACHIREAI_TEXT_DOMAIN ),
			self::POSITION_AFTER_INTRO    => __( '導入文の直後', GACHIREAI_TEXT_DOMAIN ),
			self::POSITION_AFTER_EACH_H2  => __( '各見出し（h2）の直後', GACHIREAI_TEXT_DOMAIN ),
			self::POSITION_MIDDLE         => __( '記事の中央', GACHIREAI_TEXT_DOMAIN ),
			self::POSITION_AFTER_CONTENT  => __( '記事下（本文の末尾）', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function default_positions(): array {
		return array(
			self::POSITION_AFTER_INTRO,
			self::POSITION_AFTER_CONTENT,
		);
	}
	public static function positions( Settings $settings ): array {
		$card = self::card( $settings );
		if ( ! array_key_exists( self::KEY_POSITIONS, $card ) ) {
			return self::default_positions();
		}
		$saved    = is_scalar( $card[ self::KEY_POSITIONS ] ) ? (string) $card[ self::KEY_POSITIONS ] : '';
		$selected = array_map( 'trim', explode( ',', $saved ) );
		$out      = array();
		foreach ( array_keys( self::position_options() ) as $slug ) {
			if ( in_array( $slug, $selected, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}
	public static function is_cta_position( string $position ): bool {
		return self::POSITION_AFTER_CONTENT === $position;
	}
	public static function toggle_defaults(): array {
		return array(
			self::KEY_SHOW_PRICE      => '1',
			self::KEY_SHOW_NAME       => '1',
			self::KEY_SHOW_DISCLOSURE => '1',
			self::KEY_SHOW_RAKUTEN    => '1',
			self::KEY_SHOW_YAHOO      => '1',
			self::KEY_SHOW_AMAZON     => '1',
		);
	}
	public static function ec_toggle_keys(): array {
		return array(
			Product_Selector::EC_RAKUTEN => self::KEY_SHOW_RAKUTEN,
			Product_Selector::EC_YAHOO   => self::KEY_SHOW_YAHOO,
			Product_Selector::EC_AMAZON  => self::KEY_SHOW_AMAZON,
		);
	}
	public static function toggles( Settings $settings ): array {
		$card = self::card( $settings );
		$out  = self::toggle_defaults();
		foreach ( array_keys( $out ) as $key ) {
			$value       = isset( $card[ $key ] ) && is_scalar( $card[ $key ] ) ? (string) $card[ $key ] : '';
			$out[ $key ] = '0' === $value ? '0' : '1';
		}
		return $out;
	}
	public static function normalize_toggle( string $value ): string {
		return '1' === $value ? '1' : '0';
	}
	public static function enabled_ecs( Settings $settings ): array {
		$toggles = self::toggles( $settings );
		$enabled = array();
		foreach ( self::ec_toggle_keys() as $ec => $key ) {
			if ( '0' !== $toggles[ $key ] ) {
				$enabled[] = (string) $ec;
			}
		}
		return $enabled;
	}
	public static function preview_classes( Settings $settings ): string {
		$toggles = self::toggles( $settings );
		$classes = array();
		if ( '0' === $toggles[ self::KEY_SHOW_PRICE ] ) {
			$classes[] = 'gachireai-preview-hide-price';
		}
		if ( '0' === $toggles[ self::KEY_SHOW_NAME ] ) {
			$classes[] = 'gachireai-preview-hide-name';
		}
		if ( '0' === $toggles[ self::KEY_SHOW_DISCLOSURE ] ) {
			$classes[] = 'gachireai-preview-hide-disclosure';
		}
		foreach ( self::ec_toggle_keys() as $ec => $key ) {
			if ( '0' === $toggles[ $key ] ) {
				$classes[] = 'gachireai-preview-hide-' . $ec;
			}
		}
		return implode( ' ', $classes );
	}
	public static function color_keys(): array {
		return array(
			self::KEY_NAME_COLOR       => '--gachireai-name',
			self::KEY_FRAME_COLOR      => '--gachireai-border',
			self::KEY_BUTTON_COLOR     => '--gachireai-button',
			self::KEY_DECORATION_COLOR => '--gachireai-accent',
		);
	}
	public static function valid_color( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^var\(--[A-Za-z0-9_-]+\)$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return $value;
		}
		return '';
	}
	public static function colors( Settings $settings ): array {
		$card = self::card( $settings );
		$out  = array();
		foreach ( array_keys( self::color_keys() ) as $key ) {
			$value       = isset( $card[ $key ] ) && is_scalar( $card[ $key ] ) ? (string) $card[ $key ] : '';
			$out[ $key ] = self::valid_color( $value );
		}
		return $out;
	}
	private static function rgb( string $color ): ?array {
		$color = strtolower( trim( $color ) );
		if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9_-]+)\)$/', $color, $matches ) ) {
			$color = self::palette_value( $matches[1] );
		}
		if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])(?:[0-9a-f])?$/', $color, $matches ) ) {
			return array(
				(int) hexdec( $matches[1] . $matches[1] ),
				(int) hexdec( $matches[2] . $matches[2] ),
				(int) hexdec( $matches[3] . $matches[3] ),
			);
		}
		if ( preg_match( '/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})(?:[0-9a-f]{2})?$/', $color, $matches ) ) {
			return array(
				(int) hexdec( $matches[1] ),
				(int) hexdec( $matches[2] ),
				(int) hexdec( $matches[3] ),
			);
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $color, $matches ) ) {
			return array( (int) $matches[1], (int) $matches[2], (int) $matches[3] );
		}
		return null;
	}
	private static function palette_value( string $slug ): string {
		$entries = self::palette_entries();
		return $entries[ $slug ] ?? '';
	}
	private static function palette_entries(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$palette = (array) wp_get_global_settings( array( 'color', 'palette' ) );
		$out     = array();
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $palette[ $origin ] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$slug = isset( $entry['slug'] ) && is_scalar( $entry['slug'] ) ? (string) $entry['slug'] : '';
				if ( '' === $slug || ! preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
					continue;
				}
				if ( ! isset( $entry['color'] ) || ! is_scalar( $entry['color'] ) ) {
					continue;
				}
				$color = self::safe_css_color( (string) $entry['color'] );
				if ( '' !== $color ) {
					$out[ $slug ] = $color;
				}
			}
		}
		return $out;
	}
	public static function palette_variables(): string {
		$entries = self::palette_entries();
		if ( array() === $entries ) {
			return '';
		}
		$css = ".gachireai-affiliate {\n";
		foreach ( $entries as $slug => $color ) {
			$css .= "\t--wp--preset--color--{$slug}: {$color};\n";
		}
		return $css . "}\n";
	}
	private static function safe_css_color( string $color ): string {
		$color = trim( $color );
		if ( '' === $color || preg_match( '/[;{}<>"\'\\\\]/', $color ) ) {
			return '';
		}
		return $color;
	}
	public static function contrast_text( string $color ): string {
		$rgb = self::rgb( $color );
		if ( null === $rgb ) {
			return '#ffffff';
		}
		$yiq = ( $rgb[0] * 299 + $rgb[1] * 587 + $rgb[2] * 114 ) / 1000;
		return $yiq >= 128 ? '#000000' : '#ffffff';
	}
	public static function styles( Settings $settings, bool $with_toggles = true ): string {
		$toggles = self::toggles( $settings );
		$colors  = self::colors( $settings );
		$css     = trim(
			( new Div_Renderer() )->render(
				'front/card-style',
				array(
					'card_hide_price'        => $with_toggles && '0' === $toggles[ self::KEY_SHOW_PRICE ],
					'card_hide_name'         => $with_toggles && '0' === $toggles[ self::KEY_SHOW_NAME ],
					'card_hide_disclosure'   => $with_toggles && '0' === $toggles[ self::KEY_SHOW_DISCLOSURE ],
					'card_color_name'        => $colors[ self::KEY_NAME_COLOR ],
					'card_color_frame'       => $colors[ self::KEY_FRAME_COLOR ],
					'card_color_button'      => $colors[ self::KEY_BUTTON_COLOR ],
					'card_color_button_text' => '' !== $colors[ self::KEY_BUTTON_COLOR ] ? self::contrast_text( $colors[ self::KEY_BUTTON_COLOR ] ) : '',
					'card_color_decoration'  => $colors[ self::KEY_DECORATION_COLOR ],
				)
			)
		);
		return str_contains( $css, '{' ) ? $css : '';
	}
	public const PATTERN_CSS_DIR = 'assets/css/card/';
	public const INLINE_HANDLE = 'gachireai-card-inline';
	public static function pattern_css_url( string $pattern ): string {
		if ( ! array_key_exists( $pattern, Box_Renderer::all_patterns() ) ) {
			return '';
		}
		$rel  = self::PATTERN_CSS_DIR . $pattern . '.css';
		$path = GACHIREAI_PLUGIN_DIR . $rel;
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$body = (string) file_get_contents( $path );
		if ( ! str_contains( $body, '{' ) ) {
			return '';
		}
		return GACHIREAI_PLUGIN_URL . $rel;
	}
	public static function enqueue_pattern( Settings $settings ): void {
		self::enqueue_pattern_code( self::pattern( $settings ) );
	}
	public static function enqueue_all_patterns(): void {
		foreach ( array_keys( Box_Renderer::all_patterns() ) as $pattern ) {
			self::enqueue_pattern_code( $pattern );
		}
	}
	private static function enqueue_pattern_code( string $pattern ): void {
		$url = self::pattern_css_url( $pattern );
		if ( '' === $url ) {
			return;
		}
		$path = GACHIREAI_PLUGIN_DIR . self::PATTERN_CSS_DIR . $pattern . '.css';
		wp_enqueue_style(
			'gachireai-card-' . $pattern,
			$url,
			array( 'gachireai-front' ),
			(string) filemtime( $path )
		);
	}
	private static function card( Settings $settings ): array {
		$card = $settings->get( self::GROUP_KEY, array() );
		return is_array( $card ) ? $card : array();
	}
}

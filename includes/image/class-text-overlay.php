<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Text_Overlay {
	private const FONT_FILE = 'assets/fonts/NotoSansJP-Bold.otf';
	private const TEXT_AREA_RATIO = 0.50;
	private const TITLE_MAX_SIZE = 58;
	private const TITLE_MIN_SIZE = 34;
	private const TITLE_STEP = 4;
	private const NO_LINE_START = '、。，．・：；？！ヽヾゝゞ々ー）〕］｝〉》」』】〙〗〟’”｠»';
	private const NO_LINE_END = '（〔［｛〈《「『【〘〖〝‘“｟«';
	public static function apply( string $binary, array $text, string $side = 'left', ?string $font = null ): array {
		$font = ( null !== $font && '' !== $font ) ? $font : GACHIREAI_PLUGIN_DIR . self::FONT_FILE;
		$fail = static function ( string $warning ) use ( $binary ): array {
			return array(
				'data'    => $binary,
				'mime'    => 'image/jpeg',
				'width'   => 0,
				'height'  => 0,
				'warning' => $warning,
			);
		};
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagettftext' ) || ! function_exists( 'imagejpeg' ) ) {
			return $fail( __( '文字入れをスキップしました（GD の FreeType が無効です）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( ! is_readable( $font ) ) {
			return $fail( __( '文字入れをスキップしました（日本語フォントが見つかりません）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$image = @imagecreatefromstring( $binary );
		if ( false === $image ) {
			return $fail( __( '文字入れをスキップしました（画像を読み込めませんでした）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$width  = imagesx( $image );
		$height = imagesy( $image );
		$side   = 'right' === $side ? 'right' : 'left';
		$site    = self::clean( (string) ( $text['site_name'] ?? '' ) );
		$title   = self::clean( (string) ( $text['title'] ?? '' ) );
		$product = self::clean( (string) ( $text['product_name'] ?? '' ) );
		if ( '' === $title ) {
			$title = $product;
		}
		if ( '' === $title ) {
			imagedestroy( $image );
			return $fail( __( '文字入れをスキップしました（タイトルが空です）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$margin       = max( 32, (int) round( $width * 0.055 ) );
		$margin_width = max( 1, (int) round( $width * self::TEXT_AREA_RATIO ) );
		$full_width   = max( 1, $width - $margin * 2 );
		$vertical_pad = 48;
		$layout = self::build_layout( $title, $site, $product, $font, $margin_width, $height, $vertical_pad );
		if ( null === $layout ) {
			$layout = self::build_layout( $title, $site, $product, $font, $full_width, $height, $vertical_pad );
		}
		if ( null === $layout ) {
			$layout = self::build_layout( $title, $site, $product, $font, $full_width, $height, $vertical_pad, true );
		}
		$title_width = $layout['width'];
		$title_size  = $layout['title']['size'];
		$title_lines = $layout['title']['lines'];
		$line_height = $layout['title']['line_height'];
		$coverage = max( 0.62, ( $margin + $title_width ) / $width );
		self::scrim( $image, $width, $height, $side, $coverage );
		$block_h = $layout['fixed_h'] + ( count( $title_lines ) * $line_height );
		$top     = max( $vertical_pad, (int) round( ( $height - $block_h ) / 2 ) );
		$x       = 'left' === $side ? $margin : $width - $margin - $title_width;
		$white  = imagecolorallocate( $image, 255, 255, 255 );
		$shadow = imagecolorallocatealpha( $image, 0, 0, 0, 80 );
		if ( false === $white ) {
			$white = imagecolorallocate( $image, 255, 255, 255 );
		}
		if ( false === $shadow ) {
			$shadow = $white;
		}
		$y = $top;
		foreach ( $layout['site']['lines'] as $line ) {
			$baseline = $y + $layout['site']['size'];
			imagettftext( $image, $layout['site']['size'], 0, $x + 2, $baseline + 2, $shadow, $font, $line );
			imagettftext( $image, $layout['site']['size'], 0, $x, $baseline, $white, $font, $line );
			$y += $layout['site']['line_height'];
		}
		if ( array() !== $layout['site']['lines'] ) {
			imagefilledrectangle( $image, $x, $y + 4, $x + 48, $y + 8, $white );
			$y += $layout['accent_gap'];
		}
		foreach ( $title_lines as $line ) {
			$baseline = $y + $title_size;
			imagettftext( $image, $title_size, 0, $x + 2, $baseline + 2, $shadow, $font, $line );
			imagettftext( $image, $title_size, 0, $x, $baseline, $white, $font, $line );
			$y += $line_height;
		}
		if ( array() !== $layout['product']['lines'] ) {
			$y += $layout['product_gap'];
			foreach ( $layout['product']['lines'] as $line ) {
				$baseline = $y + $layout['product']['size'];
				imagettftext( $image, $layout['product']['size'], 0, $x + 2, $baseline + 2, $shadow, $font, $line );
				imagettftext( $image, $layout['product']['size'], 0, $x, $baseline, $white, $font, $line );
				$y += $layout['product']['line_height'];
			}
		}
		ob_start();
		imagejpeg( $image, null, 88 );
		$output = ob_get_clean();
		imagedestroy( $image );
		if ( ! is_string( $output ) || '' === $output ) {
			return $fail( __( '文字入れ後の画像を生成できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return array(
			'data'    => $output,
			'mime'    => 'image/jpeg',
			'width'   => $width,
			'height'  => $height,
			'warning' => '',
		);
	}
	private static function scrim( $image, int $width, int $height, string $side, float $coverage = 0.62 ): void {
		$coverage = min( 1.0, max( 0.05, $coverage ) );
		$end      = max( 1, (int) round( $width * $coverage ) );
		$floor = $coverage > 0.62 ? 45 : 0;
		for ( $i = 0; $i < $end; $i++ ) {
			$alpha = (int) round( 105 - ( 105 - $floor ) * ( $i / $end ) );
			$color = imagecolorallocatealpha( $image, 0, 0, 0, 127 - $alpha );
			if ( false === $color ) {
				continue;
			}
			$x = 'left' === $side ? $i : $width - 1 - $i;
			imagefilledrectangle( $image, $x, 0, $x, $height - 1, $color );
		}
	}
	private static function wrap( string $text, string $font, int $size, int $max_width ): array {
		$chars  = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$chars  = is_array( $chars ) ? $chars : array( $text );
		$tokens = array();
		$buffer = '';
		foreach ( $chars as $char ) {
			$char = (string) $char;
			if ( 1 === preg_match( '/[A-Za-z0-9\-_.]/', $char ) ) {
				$buffer .= $char;
				continue;
			}
			if ( '' !== $buffer ) {
				$tokens[] = $buffer;
				$buffer   = '';
			}
			$tokens[] = $char;
		}
		if ( '' !== $buffer ) {
			$tokens[] = $buffer;
		}
		$fitted = array();
		foreach ( $tokens as $token ) {
			$token = (string) $token;
			if ( self::measure( $token, $font, $size ) <= $max_width ) {
				$fitted[] = $token;
				continue;
			}
			$token_chars = preg_split( '//u', $token, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( is_array( $token_chars ) ? $token_chars : array( $token ) as $character ) {
				$fitted[] = (string) $character;
			}
		}
		$lines = array();
		$line  = '';
		foreach ( $fitted as $token ) {
			$token = (string) $token;
			if ( '' === $token ) {
				continue;
			}
			$candidate = $line . $token;
			if ( '' === $line || self::measure( $candidate, $font, $size ) <= $max_width ) {
				$line = $candidate;
				continue;
			}
			if ( 1 === mb_strlen( $token ) && false !== mb_strpos( self::NO_LINE_START, $token ) ) {
				$line .= $token;
				continue;
			}
			$last = mb_substr( $line, -1 );
			if ( '' !== $last && false !== mb_strpos( self::NO_LINE_END, $last ) ) {
				$line    = mb_substr( $line, 0, -1 );
				$lines[] = $line;
				$line    = $last . $token;
			} else {
				$lines[] = $line;
				$line    = $token;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	}
	private static function build_layout( string $title, string $site, string $product, string $font, int $width, int $height, int $pad, bool $allow_truncate = false ): ?array {
		$site_size      = 20;
		$product_size   = 24;
		$site_line_h    = (int) round( $site_size * 1.5 );
		$product_line_h = (int) round( $product_size * 1.5 );
		$site_lines    = '' !== $site ? self::wrap( $site, $font, $site_size, $width ) : array();
		$product_lines = '' !== $product ? self::wrap( $product, $font, $product_size, $width ) : array();
		$accent_gap  = array() !== $site_lines ? 18 : 0;
		$product_gap = array() !== $product_lines ? 28 : 0;
		$fixed_h = ( count( $site_lines ) * $site_line_h ) + $accent_gap + ( count( $product_lines ) * $product_line_h ) + $product_gap;
		$budget  = max( 1, $height - $pad * 2 - $fixed_h );
		$layout = array(
			'site'        => array( 'size' => $site_size, 'lines' => $site_lines, 'line_height' => $site_line_h ),
			'product'     => array( 'size' => $product_size, 'lines' => $product_lines, 'line_height' => $product_line_h ),
			'width'       => $width,
			'accent_gap'  => $accent_gap,
			'product_gap' => $product_gap,
			'fixed_h'     => $fixed_h,
		);
		for ( $size = self::TITLE_MAX_SIZE; $size >= self::TITLE_MIN_SIZE; $size -= self::TITLE_STEP ) {
			$lines       = self::wrap( $title, $font, $size, $width );
			$line_height = (int) round( $size * 1.32 );
			if ( ( count( $lines ) * $line_height ) <= $budget ) {
				$layout['title'] = array( 'size' => $size, 'lines' => $lines, 'line_height' => $line_height );
				return $layout;
			}
		}
		if ( ! $allow_truncate ) {
			return null;
		}
		$size        = self::TITLE_MIN_SIZE;
		$line_height = (int) round( $size * 1.32 );
		$lines       = self::wrap( $title, $font, $size, $width );
		$max_lines   = max( 1, (int) floor( $budget / $line_height ) );
		if ( count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, 0, $max_lines );
			$last  = (string) array_pop( $lines );
			$lines[] = self::truncate( $last, $font, $size, $width );
		}
		$layout['title'] = array( 'size' => $size, 'lines' => array_values( $lines ), 'line_height' => $line_height );
		return $layout;
	}
	private static function truncate( string $text, string $font, int $size, int $max_width ): string {
		$text = rtrim( $text );
		while ( '' !== $text && self::measure( $text . '…', $font, $size ) > $max_width ) {
			$text = mb_substr( $text, 0, -1 );
		}
		return rtrim( $text ) . '…';
	}
	private static function measure( string $text, string $font, int $size ): int {
		$box = @imagettfbbox( $size, 0, $font, $text );
		if ( ! is_array( $box ) ) {
			return 0;
		}
		return (int) abs( $box[2] - $box[0] );
	}
	private static function clean( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );
		$text = (string) preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return $text;
	}
}

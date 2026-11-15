<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Simple_Image {
	private const EYECATCH_WIDTH  = 1200;
	private const EYECATCH_HEIGHT = 630;
	private const ICON_SIZE = 512;
	private const PROFILE_DASHICONS = array( 'admin-users', 'businessman', 'businessperson', 'businesswoman' );
	private const FALLBACK_DASHICONS = array(
		'admin-site'     => 0xF319,
		'admin-users'    => 0xF110,
		'businessman'    => 0xF338,
		'businessperson' => 0xF12E,
		'businesswoman'  => 0xF12F,
	);
	private const PALETTES = array(
		array( 'bg' => '#1e3a8a', 'accent' => '#3b82f6', 'light' => '#dbeafe' ),
		array( 'bg' => '#7c2d12', 'accent' => '#f97316', 'light' => '#ffedd5' ),
		array( 'bg' => '#134e4a', 'accent' => '#14b8a6', 'light' => '#ccfbf1' ),
		array( 'bg' => '#4c1d95', 'accent' => '#8b5cf6', 'light' => '#ede9fe' ),
		array( 'bg' => '#831843', 'accent' => '#ec4899', 'light' => '#fce7f3' ),
		array( 'bg' => '#374151', 'accent' => '#6b7280', 'light' => '#f3f4f6' ),
		array( 'bg' => '#065f46', 'accent' => '#10b981', 'light' => '#d1fae5' ),
		array( 'bg' => '#1e40af', 'accent' => '#0ea5e9', 'light' => '#e0f2fe' ),
	);
	private static ?array $dashicons = null;
	public static function is_available(): bool {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagecolorallocate' )
			&& function_exists( 'imagefilledrectangle' )
			&& function_exists( 'imagefilledellipse' )
			&& function_exists( 'imagejpeg' )
			&& function_exists( 'imagepng' );
	}
	public static function eyecatch( string $style, string $seed = '' ): array {
		if ( ! self::is_available() ) {
			throw new \RuntimeException( __( 'このサーバーでは GD が有効でないため、シンプル画像を生成できません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$width  = self::EYECATCH_WIDTH;
		$height = self::EYECATCH_HEIGHT;
		$image  = imagecreatetruecolor( $width, $height );
		if ( false === $image ) {
			throw new \RuntimeException( __( 'シンプル画像のキャンバスを作成できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( function_exists( 'imageantialias' ) ) {
			imageantialias( $image, true );
		}
		$palette = self::palette( $seed );
		switch ( $style ) {
			case 'simple-gradient':
				self::paint_gradient( $image, $width, $height, $palette );
				break;
			case 'simple-stripes':
				self::paint_stripes( $image, $width, $height, $palette );
				break;
			case 'simple-geometric':
				self::paint_geometric( $image, $width, $height, $palette, $seed );
				break;
			case 'simple-solid':
			default:
				self::paint_solid( $image, $width, $height, $palette );
				break;
		}
		ob_start();
		imagejpeg( $image, null, 88 );
		$data = (string) ob_get_clean();
		imagedestroy( $image );
		return array(
			'data'   => $data,
			'mime'   => 'image/jpeg',
			'width'  => $width,
			'height' => $height,
		);
	}
	public static function favicon( string $seed = '' ): array {
		$map  = self::dashicons_map();
		$pool = array_keys( $map );
		if ( array() === $pool ) {
			$pool = array_keys( self::FALLBACK_DASHICONS );
		}
		$slug = (string) $pool[ array_rand( $pool ) ];
		return self::icon( $slug, $seed );
	}
	public static function profile( string $seed = '' ): array {
		$map  = self::dashicons_map();
		$pool = array_values(
			array_filter(
				self::PROFILE_DASHICONS,
				static function ( string $slug ) use ( $map ): bool {
					return isset( $map[ $slug ] );
				}
			)
		);
		if ( array() === $pool ) {
			$pool = self::PROFILE_DASHICONS;
		}
		$slug = (string) $pool[ array_rand( $pool ) ];
		return self::icon( $slug, $seed );
	}
	private static function icon( string $slug, string $seed ): array {
		if ( ! self::is_available() ) {
			throw new \RuntimeException( __( 'このサーバーでは GD が有効でないため、シンプル画像を生成できません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$size  = self::ICON_SIZE;
		$image = imagecreatetruecolor( $size, $size );
		if ( false === $image ) {
			throw new \RuntimeException( __( 'シンプル画像のキャンバスを作成できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( function_exists( 'imageantialias' ) ) {
			imageantialias( $image, true );
		}
		if ( function_exists( 'imagesavealpha' ) ) {
			imagesavealpha( $image, true );
		}
		$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
		imagefilledrectangle( $image, 0, 0, $size - 1, $size - 1, $transparent );
		$palette    = self::palette( $seed );
		$background = self::alloc( $image, (string) $palette['bg'] );
		$foreground = self::alloc( $image, (string) $palette['light'] );
		$radius = (int) round( $size * 0.22 );
		imagefilledrectangle( $image, $radius, 0, $size - $radius - 1, $size - 1, $background );
		imagefilledrectangle( $image, 0, $radius, $size - 1, $size - $radius - 1, $background );
		imagefilledellipse( $image, $radius, $radius, $radius * 2, $radius * 2, $background );
		imagefilledellipse( $image, $size - $radius - 1, $radius, $radius * 2, $radius * 2, $background );
		imagefilledellipse( $image, $radius, $size - $radius - 1, $radius * 2, $radius * 2, $background );
		imagefilledellipse( $image, $size - $radius - 1, $size - $radius - 1, $radius * 2, $radius * 2, $background );
		self::draw_dashicon( $image, $slug, $size, $foreground, (int) ( $size / 2 ), (int) ( $size / 2 ) );
		ob_start();
		imagepng( $image );
		$data = (string) ob_get_clean();
		imagedestroy( $image );
		return array(
			'data'   => $data,
			'mime'   => 'image/png',
			'width'  => $size,
			'height' => $size,
		);
	}
	private static function paint_solid( $image, int $width, int $height, array $palette ): void {
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, self::alloc( $image, (string) $palette['bg'] ) );
	}
	private static function paint_gradient( $image, int $width, int $height, array $palette ): void {
		$from = self::rgb( (string) $palette['bg'] );
		$to   = self::rgb( (string) $palette['accent'] );
		for ( $y = 0; $y < $height; $y++ ) {
			$ratio = $height > 1 ? $y / ( $height - 1 ) : 0.0;
			$color = imagecolorallocate(
				$image,
				(int) round( $from[0] + ( $to[0] - $from[0] ) * $ratio ),
				(int) round( $from[1] + ( $to[1] - $from[1] ) * $ratio ),
				(int) round( $from[2] + ( $to[2] - $from[2] ) * $ratio )
			);
			imageline( $image, 0, $y, $width - 1, $y, $color );
		}
	}
	private static function paint_stripes( $image, int $width, int $height, array $palette ): void {
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, self::alloc( $image, (string) $palette['bg'] ) );
		$accent = self::alloc( $image, (string) $palette['accent'] );
		$stripe = max( 24, (int) round( $width / 26 ) );
		imagesetthickness( $image, $stripe );
		for ( $x = -$height; $x < $width + $height; $x += $stripe * 2 ) {
			imageline( $image, $x, 0, $x + $height, $height, $accent );
		}
		imagesetthickness( $image, 1 );
	}
	private static function paint_geometric( $image, int $width, int $height, array $palette, string $seed ): void {
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, self::alloc( $image, (string) $palette['bg'] ) );
		$colors = array(
			self::alloc( $image, (string) $palette['accent'] ),
			self::alloc( $image, (string) $palette['light'] ),
		);
		$cell  = max( 1, (int) round( $height / 6 ) );
		$base  = abs( (int) crc32( $seed ) ) & 0x7fffffff;
		$index = 0;
		for ( $gy = 0; $gy < $height; $gy += $cell ) {
			for ( $gx = 0; $gx < $width; $gx += $cell ) {
				$hash  = ( $base + $index * 2654435761 ) & 0x7fffffff;
				$size  = (int) round( $cell * ( 0.18 + ( $hash % 55 ) / 100 ) );
				$color = $colors[ $hash % 2 ];
				imagefilledellipse(
					$image,
					$gx + (int) ( $cell / 2 ),
					$gy + (int) ( $cell / 2 ),
					$size,
					$size,
					$color
				);
				++$index;
			}
		}
	}
	private static function draw_dashicon( $image, string $slug, int $size, int $color, int $center_x, int $center_y ): void {
		$font = self::dashicon_font_path();
		$map  = self::dashicons_map();
		$code = $map[ $slug ] ?? ( self::FALLBACK_DASHICONS[ $slug ] ?? null );
		if ( null === $code || '' === $font || ! function_exists( 'imagettftext' ) || ! function_exists( 'imagettfbbox' ) ) {
			$diameter = (int) round( $size * 0.5 );
			imagefilledellipse( $image, $center_x, $center_y, $diameter, $diameter, $color );
			return;
		}
		$char      = self::codepoint_to_utf8( (int) $code );
		$font_size = (int) round( $size * 0.58 );
		$box       = @imagettfbbox( $font_size, 0, $font, $char );
		if ( ! is_array( $box ) ) {
			$diameter = (int) round( $size * 0.5 );
			imagefilledellipse( $image, $center_x, $center_y, $diameter, $diameter, $color );
			return;
		}
		$x = (int) round( $center_x - ( $box[0] + $box[2] ) / 2 );
		$y = (int) round( $center_y - ( $box[1] + $box[7] ) / 2 );
		@imagettftext( $image, $font_size, 0, $x, $y, $color, $font, $char );
	}
	private static function palette( string $seed ): array {
		$palettes = self::PALETTES;
		if ( '' === $seed ) {
			return $palettes[0];
		}
		$index = abs( (int) crc32( $seed ) ) % count( $palettes );
		return $palettes[ $index ];
	}
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}
	private static function alloc( $image, string $hex ): int {
		$rgb = self::rgb( $hex );
		return (int) imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] );
	}
	private static function dashicons_map(): array {
		if ( null !== self::$dashicons ) {
			return self::$dashicons;
		}
		$map  = array();
		$path = ABSPATH . 'wp-includes/css/dashicons.css';
		if ( is_readable( $path ) ) {
			$css = (string) file_get_contents( $path ); 
			if ( 0 < preg_match_all( '/\.dashicons-([a-z0-9-]+):before\s*\{\s*content:\s*"\\\\([0-9a-fA-F]+)"/', $css, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$map[ (string) $match[1] ] = (int) hexdec( (string) $match[2] );
				}
			}
		}
		if ( array() === $map ) {
			$map = self::FALLBACK_DASHICONS;
		}
		self::$dashicons = $map;
		return $map;
	}
	private static function dashicon_font_path(): string {
		$path = ABSPATH . 'wp-includes/fonts/dashicons.ttf';
		return is_readable( $path ) ? $path : '';
	}
	private static function codepoint_to_utf8( int $code ): string {
		if ( function_exists( 'mb_chr' ) ) {
			$char = mb_chr( $code, 'UTF-8' );
			if ( false !== $char ) {
				return $char;
			}
		}
		return (string) html_entity_decode( '&#x' . dechex( $code ) . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

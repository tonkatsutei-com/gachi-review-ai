<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Font_Preview {
	private const WIDTH  = 1200;
	private const HEIGHT = 630;
	public static function render( string $font, string $weight, int $top_eyecatch_id = 0, ?string $site_name = null, ?string $description = null ): array {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
			throw new \RuntimeException( __( 'プレビューを生成できません（GD の FreeType が無効です）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$font_path = Font_Manager::resolve( $font, $weight );
		if ( null === $font_path ) {
			throw new \RuntimeException( __( 'フォントを取得できませんでした。時間をおいて、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$profile   = new Site_Profile();
		$elements  = $profile->get_site_elements();
		$site_name   = null !== $site_name ? $site_name : (string) ( $elements['site_name'] ?? '' );
		$description = null !== $description ? $description : (string) ( $elements['description'] ?? '' );
		$settings  = new Settings();
		$image     = $settings->image();
		$binary    = self::base_image( $profile, $top_eyecatch_id );
		if ( '' === $binary ) {
			throw new \RuntimeException( __( 'プレビュー用の画像を用意できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$overlay = Text_Overlay::apply(
			$binary,
			array(
				'site_name'    => '',
				'title'        => '' !== trim( $site_name ) ? $site_name : (string) get_bloginfo( 'name' ),
				'product_name' => self::subtitle( $description ),
			),
			(string) ( $image['text_side'] ?? 'left' ),
			$font_path
		);
		return array(
			'image'   => 'data:' . (string) $overlay['mime'] . ';base64,' . base64_encode( (string) $overlay['data'] ),
			'warning' => (string) $overlay['warning'],
		);
	}
	private static function base_image( Site_Profile $profile, int $top_eyecatch_id = 0 ): string {
		$top_id = $top_eyecatch_id;
		if ( 0 >= $top_id ) {
			$site_images = $profile->get_site_images();
			$top_id      = isset( $site_images['top_eyecatch'] ) && is_scalar( $site_images['top_eyecatch'] )
				? absint( (string) $site_images['top_eyecatch'] )
				: 0;
		}
		if ( 0 < $top_id ) {
			$base_id = (int) get_post_meta( $top_id, '_gachireai_image_base_attachment_id', true );
			$path    = 0 < $base_id ? (string) get_attached_file( $base_id ) : (string) get_attached_file( $top_id );
			if ( '' !== $path && is_readable( $path ) ) {
				$binary = @file_get_contents( $path ); 
				if ( is_string( $binary ) && '' !== $binary ) {
					return $binary;
				}
			}
		}
		return self::placeholder();
	}
	private static function placeholder(): string {
		$image = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		if ( false === $image ) {
			return '';
		}
		for ( $x = 0; $x < self::WIDTH; $x++ ) {
			$shade = 22 + (int) round( 18 * ( $x / max( 1, self::WIDTH - 1 ) ) );
			$color = imagecolorallocate( $image, $shade, $shade, $shade + 10 );
			imageline( $image, $x, 0, $x, self::HEIGHT, (int) $color );
		}
		ob_start();
		imagejpeg( $image, null, 82 );
		$binary = (string) ob_get_clean();
		imagedestroy( $image );
		return $binary;
	}
	private static function subtitle( string $description ): string {
		$description = trim( (string) preg_replace( '/\s+/u', ' ', $description ) );
		if ( '' === $description ) {
			return '';
		}
		return 60 < mb_strlen( $description )
			? mb_substr( $description, 0, 60 ) . '…'
			: $description;
	}
}

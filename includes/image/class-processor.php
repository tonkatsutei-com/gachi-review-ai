<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Processor {
	public static function square( string $binary, int $size = 512 ): array {
		return self::cover( $binary, $size, $size, 'image/png' );
	}
	public static function cover( string $binary, int $width, int $height, string $mime = 'image/jpeg' ): array {
		if ( '' === $binary ) {
			throw new \RuntimeException( __( '加工する画像がありません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) || ! function_exists( 'wp_get_image_editor' ) ) {
			throw new \RuntimeException( __( '画像加工に必要な WordPress の機能がありません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$input = wp_tempnam( 'gachireai-in-' );
		if ( ! is_string( $input ) || '' === $input || false === file_put_contents( $input, $binary ) ) { 
			@unlink( $input ); 
			throw new \RuntimeException( __( '画像の一時ファイルを作成できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$editor = wp_get_image_editor( $input );
		if ( is_wp_error( $editor ) ) {
			@unlink( $input ); 
			throw new \RuntimeException( sprintf(
				__( '画像を読み込めませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
				$editor->get_error_message()
			) );
		}
		$editor->set_quality( 88 );
		$resized = $editor->resize( max( 1, $width ), max( 1, $height ), true );
		if ( is_wp_error( $resized ) ) {
			@unlink( $input ); 
			throw new \RuntimeException( sprintf(
				__( '画像を加工できませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
				$resized->get_error_message()
			) );
		}
		$output = wp_tempnam( 'gachireai-out-' );
		$saved  = $editor->save( $output, $mime );
		@unlink( $input ); 
		if ( is_wp_error( $saved ) ) {
			@unlink( $output ); 
			throw new \RuntimeException( sprintf(
				__( '加工した画像を保存できませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
				$saved->get_error_message()
			) );
		}
		$path = (string) ( $saved['path'] ?? $output );
		$data = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		if ( $path !== $output ) {
			@unlink( $output ); 
		}
		@unlink( $path ); 
		if ( '' === $data ) {
			throw new \RuntimeException( __( '加工した画像を読み出せませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$info = Validator::image( $data );
		return array(
			'data'   => $data,
			'mime'   => (string) ( $saved['mime-type'] ?? $info['mime'] ),
			'width'  => (int) ( $saved['width'] ?? $info['width'] ),
			'height' => (int) ( $saved['height'] ?? $info['height'] ),
		);
	}
}

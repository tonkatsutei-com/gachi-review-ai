<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Media {
	public static function store( array $image, string $filename, string $title, string $alt = '', array $meta = array(), int $parent = 0 ): int {
		$data = (string) ( $image['data'] ?? '' );
		$mime = (string) ( $image['mime'] ?? '' );
		if ( '' === $data || '' === $mime ) {
			throw new \RuntimeException( __( '保存する画像がありません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$filename = sanitize_file_name( $filename );
		$upload   = wp_upload_bits( $filename, null, $data );
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
			throw new \RuntimeException( sprintf(
				__( '画像をアップロードできませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
				is_array( $upload ) ? (string) ( $upload['error'] ?? '' ) : ''
			) );
		}
		$file = (string) ( $upload['file'] ?? '' );
		$url  = (string) ( $upload['url'] ?? '' );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file,
			max( 0, $parent ),
			true
		);
		if ( is_wp_error( $attachment_id ) || 0 >= (int) $attachment_id ) {
			@unlink( $file ); 
			throw new \RuntimeException( sprintf(
				__( '画像をメディアへ登録できませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
				is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : ''
			) );
		}
		$attachment_id = (int) $attachment_id;
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		try {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( is_array( $metadata ) && array() !== $metadata ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		} catch ( \Throwable $e ) {
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $attachment_id, (string) $key, $value );
		}
		if ( '' !== $url ) {
			wp_update_post(
				array(
					'ID'   => $attachment_id,
					'guid' => $url,
				)
			);
		}
		return $attachment_id;
	}
	public static function delete( int $attachment_id ): void {
		if ( 0 < $attachment_id && function_exists( 'wp_delete_attachment' ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
}

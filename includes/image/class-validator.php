<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Validator {
	private const MAX_BYTES = 10485760;
	public static function image( string $data ): array {
		if ( '' === $data ) {
			throw new \RuntimeException( __( '画像生成AIから画像が返りませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( strlen( $data ) > self::MAX_BYTES ) {
			throw new \RuntimeException( __( '生成された画像が大きすぎます（10MB 以下にしてください）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$info = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $data ) : false;
		if ( ! is_array( $info ) ) {
			throw new \RuntimeException( __( '生成されたデータを画像として認識できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$mime    = (string) ( $info['mime'] ?? '' );
		$width   = (int) ( $info[0] ?? 0 );
		$height  = (int) ( $info[1] ?? 0 );
		$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
		if ( ! in_array( $mime, $allowed, true ) ) {
			throw new \RuntimeException( sprintf(
				__( '未対応の画像形式です（%s）。', GACHIREAI_TEXT_DOMAIN ),
				$mime
			) );
		}
		if ( $width < 256 || $height < 256 ) {
			throw new \RuntimeException( __( '生成された画像が小さすぎます。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return array(
			'mime'   => $mime,
			'width'  => $width,
			'height' => $height,
		);
	}
	public static function decode_base64( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			throw new \RuntimeException( __( '画像生成AIの応答に画像データがありませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$decoded = base64_decode( $value, true );
		if ( false === $decoded ) {
			$decoded = base64_decode( (string) preg_replace( '/\s+/', '', $value ), true );
		}
		if ( false === $decoded || '' === $decoded ) {
			throw new \RuntimeException( __( '画像生成AIの応答（Base64）をデコードできませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $decoded;
	}
	public static function api_message( array $body ): string {
		if ( isset( $body['error']['message'] ) && is_scalar( $body['error']['message'] ) ) {
			return trim( (string) $body['error']['message'] );
		}
		if ( isset( $body['errors'][0]['message'] ) && is_scalar( $body['errors'][0]['message'] ) ) {
			return trim( (string) $body['errors'][0]['message'] );
		}
		if ( isset( $body['message'] ) && is_scalar( $body['message'] ) ) {
			return trim( (string) $body['message'] );
		}
		return '';
	}
	public static function quota_hint( string $raw ): string {
		if ( false === stripos( $raw, 'daily free allocation' ) && false === stripos( $raw, 'neurons' ) ) {
			return '';
		}
		return __( "Cloudflare Workers AI の無料枠（1日 10,000 ニューロン）を使い切りました。\n① 翌日（UTC 0時／日本時間 9:00 頃）のリセットを待つ\n② Cloudflare の Workers Paid プランへアップグレードする\n③ 別の画像生成AI（Google AI Studio / OpenAI）へ切り替える", GACHIREAI_TEXT_DOMAIN );
	}
	public static function http_hint( int $status, string $raw = '' ): string {
		$quota = self::quota_hint( $raw );
		if ( '' !== $quota ) {
			return $quota;
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'API キー・トークンの権限を確認してください。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( 404 === $status ) {
			return __( 'モデル名を確認してください。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( 429 === $status ) {
			return __( '回数制限に達しました。時間をおいて再試行してください。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( 400 === $status && false !== strpos( $raw, 'model' ) ) {
			return __( 'モデル名またはパラメータを確認してください。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( $status >= 500 ) {
			return __( '一時的な障害の可能性があります。時間をおいて再試行してください。', GACHIREAI_TEXT_DOMAIN );
		}
		return '';
	}
	public static function api_exception( string $label, array $response, array $body, string $secret ): Provider_Exception {
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$message = self::api_message( $body );
		$hint    = self::http_hint( $code, $raw );
		$text = sprintf(
			'%1$s API エラー（HTTP %2$d）: %3$s%4$s',
			$label,
			$code,
			'' !== $message ? mb_substr( $message, 0, 200 ) : mb_substr( $raw, 0, 200 ),
			'' !== $hint ? '／' . $hint : ''
		);
		return new Provider_Exception( self::scrub( $text, $secret ), $code );
	}
	public static function scrub( string $message, string ...$secrets ): string {
		foreach ( $secrets as $secret ) {
			$secret = trim( $secret );
			if ( '' !== $secret ) {
				$message = str_replace( $secret, '***', $message );
			}
		}
		return $message;
	}
}

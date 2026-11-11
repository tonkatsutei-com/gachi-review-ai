<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
use Tonkatsutei\Gachi_Review_AI\Api_Error;
use Tonkatsutei\Gachi_Review_AI\License\Client as License_Client;
use Tonkatsutei\Gachi_Review_AI\License\Store as License_Store;
final class Gateway_Client {
	public const PATH = 'image/generate.php';
	public const TIMEOUT = 180;
	private string $base;
	public function __construct( ?string $base = null ) {
		$resolved   = null === $base ? self::base_url() : $base;
		$this->base = rtrim( trim( $resolved ), '/' ) . '/';
	}
	public static function base_url(): string {
		foreach ( array( 'GACHIREAI_IMAGE_GATEWAY', 'GACHIREAI_LLM_GATEWAY' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}
			$value = constant( $constant );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return License_Client::api_base();
	}
	public static function is_configured(): bool {
		foreach ( array( 'GACHIREAI_IMAGE_GATEWAY', 'GACHIREAI_LLM_GATEWAY' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== trim( (string) constant( $constant ) ) ) {
				return true;
			}
		}
		return false;
	}
	public function generate( array $image, string $api_key, string $prompt, array $opts = array() ): array {
		$license_id = License_Store::key();
		if ( '' === $license_id ) {
			throw new \RuntimeException( __( 'ライセンスキーが未設定のため、画像を生成できません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			throw new \RuntimeException( __( '画像生成のプロンプトが空です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$api_key = trim( $api_key );
		$request = array(
			'license_id' => $license_id,
			'site_url'   => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
			'provider'   => (string) ( $image['provider'] ?? '' ),
			'model'      => (string) ( $image['model'] ?? '' ),
			'account_id' => (string) ( $image['account_id'] ?? '' ),
			'api_key'    => $api_key,
			'prompt'     => $prompt,
			'opts'       => self::clean_opts( $opts ),
		);
		$response = wp_remote_post(
			$this->base . self::PATH,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $license_id,
					'User-Agent'    => 'gachi-review-ai/' . GACHIREAI_APP_VERSION,
				),
				'body'    => (string) wp_json_encode( $request ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				self::scrub(
					sprintf(
						__( '画像ゲートウェイへ接続できませんでした（%s）。', GACHIREAI_TEXT_DOMAIN ),
						$response->get_error_message()
					),
					$license_id,
					$api_key
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( 200 !== $code ) {
			$message = is_array( $data ) && isset( $data['message'] ) && is_scalar( $data['message'] )
				? trim( (string) $data['message'] )
				: '';
			$text = Api_Error::append(
				sprintf(
					__( '画像ゲートウェイがエラーを返しました（HTTP %d）。', GACHIREAI_TEXT_DOMAIN ),
					$code
				),
				'' !== $message ? $message : $raw
			);
			$quota = Validator::quota_hint( $raw );
			if ( '' !== $quota ) {
				$text .= "\n" . $quota;
			}
			throw new Provider_Exception( self::scrub( $text, $license_id, $api_key ), $code );
		}
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( __( '画像ゲートウェイの応答を解釈できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$encoded = isset( $data['data'] ) && is_scalar( $data['data'] ) ? (string) $data['data'] : '';
		$binary  = Validator::decode_base64( $encoded );
		$info    = Validator::image( $binary );
		return array(
			'data'  => $binary,
			'mime'  => $info['mime'],
			'model' => isset( $data['model'] ) && is_scalar( $data['model'] ) && '' !== trim( (string) $data['model'] )
				? (string) $data['model']
				: (string) ( $image['model'] ?? '' ),
		);
	}
	private static function clean_opts( array $opts ): array {
		$clean = array();
		if ( isset( $opts['steps'] ) && is_numeric( $opts['steps'] ) ) {
			$clean['steps'] = (int) $opts['steps'];
		}
		foreach ( array( 'aspect_ratio', 'size', 'quality', 'output_format', 'fallback_prompt' ) as $key ) {
			if ( isset( $opts[ $key ] ) && is_scalar( $opts[ $key ] ) ) {
				$clean[ $key ] = (string) $opts[ $key ];
			}
		}
		return $clean;
	}
	private static function scrub( string $text, string $license_id, string $api_key ): string {
		foreach ( array( $license_id, $api_key ) as $secret ) {
			$secret = trim( $secret );
			if ( '' !== $secret ) {
				$text = str_replace( $secret, '***', $text );
			}
		}
		return $text;
	}
}

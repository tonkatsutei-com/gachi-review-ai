<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\License;
use Tonkatsutei\Gachi_Review_AI\Api_Error;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Client {
	public const DEFAULT_API_BASE = 'https://tonkatsutei.com/tools_api/gachi-review-ai/';
	public const VERIFY_PATH = 'license/verify.php';
	public const PROMPTS_PATH = 'prompts.php';
	public const TIMEOUT = 10;
	private string $api_base;
	public function __construct( ?string $api_base = null ) {
		$base = null === $api_base ? self::api_base() : $api_base;
		$this->api_base = rtrim( trim( $base ), '/' ) . '/';
	}
	public static function api_base(): string {
		if ( defined( 'GACHIREAI_LICENSE_API' ) && is_string( GACHIREAI_LICENSE_API ) ) {
			$defined = trim( GACHIREAI_LICENSE_API );
			if ( '' !== $defined ) {
				return $defined;
			}
		}
		return self::DEFAULT_API_BASE;
	}
	public function verify( string $license_id, string $site_url ): array {
		$data = $this->post(
			self::VERIFY_PATH,
			$license_id,
			array( 'site_url' => $site_url )
		);
		$tier = '';
		if ( isset( $data['tier'] ) && is_scalar( $data['tier'] ) ) {
			$tier = (string) $data['tier'];
		} elseif ( isset( $data['status'] ) && is_scalar( $data['status'] ) ) {
			$tier = self::tier_from_status( (string) $data['status'] );
		}
		$normalized = Status::normalize_tier( $tier );
		return array(
			'tier'       => $normalized,
			'expires_at' => self::expires_at_for( $normalized, $data['expires_at'] ?? null ),
		);
	}
	private static function expires_at_for( string $tier, $expires_at ): string {
		if ( Status::TIER_PRO !== $tier || ! is_scalar( $expires_at ) ) {
			return '';
		}
		return substr( trim( (string) $expires_at ), 0, 10 );
	}
	public function fetch_prompts( string $license_id, string $site_url, array $keys ): array {
		$data = $this->post(
			self::PROMPTS_PATH,
			$license_id,
			array(
				'site_url' => $site_url,
				'keys'     => array_values( array_map( 'strval', $keys ) ),
			)
		);
		$prompts = array();
		if ( isset( $data['prompts'] ) && is_array( $data['prompts'] ) ) {
			foreach ( $data['prompts'] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$prompts[ (string) $key ] = (string) $value;
				}
			}
		}
		return array(
			'prompts' => $prompts,
			'version' => isset( $data['version'] ) && is_scalar( $data['version'] ) ? (string) $data['version'] : '',
		);
	}
	public static function tier_from_status( string $status ): string {
		switch ( trim( $status ) ) {
			case '211':
				return Status::TIER_PRO;
			case '210':
				return Status::TIER_BASIC;
		}
		return Status::TIER_FREE;
	}
	private function post( string $path, string $license_id, array $body ): array {
		$license_id = trim( $license_id );
		if ( '' === $license_id ) {
			throw new \RuntimeException( __( 'ライセンスキーが未設定です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$body['license_id'] = $license_id;
		$response = wp_remote_post(
			$this->api_base . $path,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $license_id,
					'User-Agent'    => 'gachi-review-ai/' . GACHIREAI_APP_VERSION,
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'ライセンスサーバーへ接続できませんでした（%s）。', GACHIREAI_TEXT_DOMAIN ),
					$response->get_error_message()
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code ) {
			throw new \RuntimeException(
				Api_Error::append(
					sprintf(
						__( 'ライセンスサーバーがエラーを返しました（HTTP %d）。', GACHIREAI_TEXT_DOMAIN ),
						$code
					),
					$raw
				)
			);
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( __( 'ライセンスサーバーの応答を解釈できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $data;
	}
}

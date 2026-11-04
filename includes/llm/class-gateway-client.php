<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
use Tonkatsutei\Gachi_Review_AI\License\Client as License_Client;
use Tonkatsutei\Gachi_Review_AI\License\Store as License_Store;
final class Gateway_Client {
	public const PATH = 'llm/generate.php';
	public const TIMEOUT = 120;
	private string $base;
	public function __construct( ?string $base = null ) {
		$resolved   = null === $base ? self::base_url() : $base;
		$this->base = rtrim( trim( $resolved ), '/' ) . '/';
	}
	public static function base_url(): string {
		if ( defined( 'GACHIREAI_LLM_GATEWAY' ) && is_string( GACHIREAI_LLM_GATEWAY ) ) {
			$defined = trim( GACHIREAI_LLM_GATEWAY );
			if ( '' !== $defined ) {
				return $defined;
			}
		}
		return License_Client::api_base();
	}
	public static function is_configured(): bool {
		return defined( 'GACHIREAI_LLM_GATEWAY' )
			&& is_string( GACHIREAI_LLM_GATEWAY )
			&& '' !== trim( GACHIREAI_LLM_GATEWAY );
	}
	public function generate( array $settings, string $prompt_key, string $user, array $opts, bool $json ): array {
		$license_id = License_Store::key();
		if ( '' === $license_id ) {
			throw new \RuntimeException( __( 'ライセンスキーが未設定のため、プロンプトを取得できません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$prompt_key = trim( $prompt_key );
		if ( '' === $prompt_key ) {
			throw new \RuntimeException( __( 'プロンプトのキーが未指定です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$api_key = (string) ( $settings['api_key'] ?? '' );
		$provider = (string) ( $settings['provider'] ?? '' );
		$model    = trim( (string) ( $settings['model'] ?? '' ) );
		if ( '' === $model ) {
			$model = Model_Catalog::default_model( $provider );
		}
		$request = array(
			'license_id' => $license_id,
			'site_url'   => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
			'prompt_key' => $prompt_key,
			'provider'   => $provider,
			'model'      => $model,
			'api_key'    => $api_key,
			'user'       => $user,
			'opts'       => self::clean_opts( $opts ),
			'json'       => $json,
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
						__( 'LLM ゲートウェイへ接続できませんでした（%s）。', GACHIREAI_TEXT_DOMAIN ),
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
			$text = '' !== $message
				? $message
				: sprintf(
					__( 'LLM ゲートウェイがエラーを返しました（HTTP %d）。', GACHIREAI_TEXT_DOMAIN ),
					$code
				);
			throw new Provider_Exception( self::scrub( $text, $license_id, $api_key ), $code );
		}
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( __( 'LLM ゲートウェイの応答を解釈できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return array(
			'content'       => isset( $data['content'] ) && is_scalar( $data['content'] ) ? (string) $data['content'] : '',
			'usage'         => isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array(),
			'finish_reason' => isset( $data['finish_reason'] ) && is_scalar( $data['finish_reason'] ) ? (string) $data['finish_reason'] : '',
		);
	}
	private static function clean_opts( array $opts ): array {
		$clean = array();
		foreach ( array( 'temperature', 'max_tokens' ) as $key ) {
			if ( isset( $opts[ $key ] ) && is_numeric( $opts[ $key ] ) ) {
				$clean[ $key ] = $opts[ $key ] + 0;
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

<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm\Providers;
use Tonkatsutei\Gachi_Review_AI\Llm\Model_Catalog;
use Tonkatsutei\Gachi_Review_AI\Llm\Provider;
use Tonkatsutei\Gachi_Review_AI\Llm\Provider_Error;
use Tonkatsutei\Gachi_Review_AI\Llm\Provider_Exception;
abstract class Openai_Compatible_Provider implements Provider {
	protected const SLUG = '';
	protected const ENDPOINT = '';
	protected const LABEL = 'OpenAI 互換';
	private const TIMEOUT = 120;
	private int $timeout = self::TIMEOUT;
	private string $api_key;
	private string $model;
	public function __construct( string $api_key, string $model = '' ) {
		$this->api_key = $api_key;
		$this->model   = '' !== $model ? $model : self::default_model();
	}
	public static function default_model(): string {
		return Model_Catalog::default_model( static::SLUG );
	}
	public function supports_json_mode(): bool {
		return true;
	}
	public function generate( string $system, string $user, array $opts = array() ): array {
		$this->timeout = max( 1, (int) ( $opts['timeout'] ?? self::TIMEOUT ) );
		$response = $this->request( $this->payload( $system, $user, $opts ) );
		if ( is_wp_error( $response ) ) {
			throw self::timeout_exception( $response->get_error_message() );
		}
		$body = $this->decode( $response );
		if ( ! $this->has_choices( $body ) ) {
			$retry = $this->retry_payload( $response, $system, $user, $opts );
			if ( null !== $retry ) {
				$response = $this->request( $retry );
				if ( is_wp_error( $response ) ) {
					throw self::timeout_exception( $response->get_error_message() );
				}
				$body = $this->decode( $response );
			}
		}
		if ( ! $this->has_choices( $body ) ) {
			throw $this->api_exception( $response, $body );
		}
		return array(
			'content'       => (string) ( $body['choices'][0]['message']['content'] ?? '' ),
			'usage'         => is_array( $body['usage'] ?? null ) ? $body['usage'] : array(),
			'finish_reason' => (string) ( $body['choices'][0]['finish_reason'] ?? '' ),
		);
	}
	private static function timeout_exception( string $detail ): Provider_Exception {
		return new Provider_Exception(
			sprintf(
				__( "%1\$s に接続できませんでした（タイムアウトの可能性があります）。\n時間をおいて再試行するか、モデルを変えてください。\n%2\$s", GACHIREAI_TEXT_DOMAIN ),
				static::LABEL,
				$detail
			),
			0
		);
	}
	private function payload( string $system, string $user, array $opts ): array {
		$payload = array(
			'model'       => $this->model,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			'temperature' => (float) ( $opts['temperature'] ?? 0.7 ),
			'max_tokens'  => (int) ( $opts['max_tokens'] ?? 4000 ),
		);
		if ( isset( $opts['response_format'] ) ) {
			$payload['response_format'] = $opts['response_format'];
		}
		return $payload;
	}
	private function request( array $payload ) {
		return wp_remote_post(
			static::ENDPOINT,
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
	}
	private function decode( array $response ): array {
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : array();
	}
	private function has_choices( array $body ): bool {
		return isset( $body['choices'][0] );
	}
	private function api_exception( array $response, array $body ): Provider_Exception {
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$message = Provider_Error::message( $body );
		$hint    = Provider_Error::hint( $code, (string) wp_remote_retrieve_body( $response ) );
		if ( '' !== $message ) {
			return new Provider_Exception(
				sprintf(
					'%1$s API エラー（HTTP %2$d）: %3$s%4$s',
					static::LABEL,
					$code,
					mb_substr( $message, 0, 200 ),
					'' !== $hint ? '／' . $hint : ''
				),
				$code
			);
		}
		return new Provider_Exception(
			sprintf(
				'%s 応答を解釈できません（HTTP %d）: %s',
				static::LABEL,
				$code,
				mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 200 )
			),
			$code
		);
	}
	private function retry_payload( array $response, string $system, string $user, array $opts ): ?array {
		$raw = (string) wp_remote_retrieve_body( $response );
		if ( ! str_contains( $raw, 'max_tokens' ) ) {
			return null;
		}
		$payload = $this->payload( $system, $user, $opts );
		if ( ! isset( $payload['max_tokens'] ) ) {
			return null;
		}
		$payload['max_completion_tokens'] = $payload['max_tokens'];
		unset( $payload['max_tokens'] );
		return $payload;
	}
}

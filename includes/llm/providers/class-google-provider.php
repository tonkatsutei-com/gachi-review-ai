<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm\Providers;
use Tonkatsutei\Gachi_Review_AI\Llm\Model_Catalog;
use Tonkatsutei\Gachi_Review_AI\Llm\Provider;
use Tonkatsutei\Gachi_Review_AI\Llm\Provider_Error;
use Tonkatsutei\Gachi_Review_AI\Llm\Provider_Exception;
final class Google_Provider implements Provider {
	private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
	private const FINISH_REASONS = array(
		'STOP'       => 'stop',
		'MAX_TOKENS' => 'length',
		'SAFETY'     => 'content_filter',
		'RECITATION' => 'content_filter',
	);
	private const TIMEOUT = 120;
	private string $api_key;
	private string $model;
	public function __construct( string $api_key, string $model = '' ) {
		$this->api_key = $api_key;
		$this->model   = '' !== $model ? $model : Model_Catalog::default_model( Model_Catalog::GOOGLE );
	}
	public function supports_json_mode(): bool {
		return true;
	}
	public function generate( string $system, string $user, array $opts = array() ): array {
		$payload = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => self::prompt( $system, $user ) ) ),
				),
			),
			'generationConfig' => self::generation_config( $opts ),
		);
		$response = wp_remote_post(
			self::ENDPOINT . rawurlencode( $this->model ) . ':generateContent',
			array(
				'timeout' => max( 1, (int) ( $opts['timeout'] ?? self::TIMEOUT ) ),
				'headers' => array(
					'x-goog-api-key' => $this->api_key,
					'Content-Type'   => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Provider_Exception(
				sprintf(
					__( "Google AI Studio に接続できませんでした（タイムアウトの可能性があります）。\n時間をおいて再試行するか、モデルを変えてください。\n%s", GACHIREAI_TEXT_DOMAIN ),
					$response->get_error_message()
				),
				0
			);
		}
		$body     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$candidate = is_array( $body ) && isset( $body['candidates'][0] ) ? (array) $body['candidates'][0] : array();
		if ( array() === $candidate ) {
			throw self::api_exception( $response, is_array( $body ) ? $body : array() );
		}
		return array(
			'content'       => self::text( $candidate ),
			'usage'         => self::usage( (array) $body ),
			'finish_reason' => self::finish_reason( (string) ( $candidate['finishReason'] ?? '' ) ),
		);
	}
	private static function api_exception( array $response, array $body ): Provider_Exception {
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$message = Provider_Error::message( $body );
		$hint    = Provider_Error::hint( $code, (string) wp_remote_retrieve_body( $response ) );
		if ( '' !== $message ) {
			return new Provider_Exception(
				sprintf(
					'Google AI Studio API エラー（HTTP %1$d）: %2$s%3$s',
					$code,
					mb_substr( $message, 0, 200 ),
					'' !== $hint ? '／' . $hint : ''
				),
				$code
			);
		}
		return new Provider_Exception(
			sprintf(
				'Google AI Studio 応答を解釈できません（HTTP %d）: %s',
				$code,
				mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 200 )
			),
			$code
		);
	}
	private static function prompt( string $system, string $user ): string {
		return '' !== trim( $system ) ? $system . "\n\n" . $user : $user;
	}
	private static function generation_config( array $opts ): array {
		$config = array(
			'temperature'     => (float) ( $opts['temperature'] ?? 0.7 ),
			'maxOutputTokens' => (int) ( $opts['max_tokens'] ?? 4000 ),
		);
		if ( isset( $opts['response_format'] ) ) {
			$config['responseMimeType'] = 'application/json';
		}
		return $config;
	}
	private static function text( array $candidate ): string {
		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) ) {
				$text .= (string) $part['text'];
			}
		}
		return $text;
	}
	private static function usage( array $body ): array {
		$meta = is_array( $body['usageMetadata'] ?? null ) ? (array) $body['usageMetadata'] : array();
		return array(
			'prompt_tokens'     => (int) ( $meta['promptTokenCount'] ?? 0 ),
			'completion_tokens' => (int) ( $meta['candidatesTokenCount'] ?? 0 ),
			'total_tokens'      => (int) ( $meta['totalTokenCount'] ?? 0 ),
		);
	}
	private static function finish_reason( string $reason ): string {
		return (string) ( self::FINISH_REASONS[ $reason ] ?? strtolower( $reason ) );
	}
}

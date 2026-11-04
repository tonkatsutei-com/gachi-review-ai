<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image\Providers;
use Tonkatsutei\Gachi_Review_AI\Image\Image_Catalog;
use Tonkatsutei\Gachi_Review_AI\Image\Provider;
use Tonkatsutei\Gachi_Review_AI\Image\Provider_Exception;
use Tonkatsutei\Gachi_Review_AI\Image\Validator;
final class Google_Provider implements Provider {
	private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
	private const MODELS_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';
	private string $api_key;
	private string $model;
	public function __construct( string $api_key, string $model ) {
		$this->api_key = trim( $api_key );
		$this->model   = Image_Catalog::normalize_model( Image_Catalog::GOOGLE, $model );
	}
	public function model(): string {
		return $this->model;
	}
	public function label(): string {
		return Image_Catalog::label( Image_Catalog::GOOGLE );
	}
	public function generate( string $prompt, array $opts = array() ): array {
		if ( '' === $this->api_key ) {
			throw new \RuntimeException( __( 'Google AI Studio の API キーが未設定です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$prompt = trim( $prompt );
		$aspect = isset( $opts['aspect_ratio'] ) ? (string) $opts['aspect_ratio'] : '1:1';
		$aspect = in_array( $aspect, array( '1:1', '16:9', '4:3', '3:4', '9:16' ), true ) ? $aspect : '1:1';
		$payload = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $prompt ) ),
				),
			),
			'generationConfig' => array(
				'responseModalities' => array( 'IMAGE' ),
				'imageConfig'        => array( 'aspectRatio' => $aspect ),
			),
		);
		$response = $this->request( $payload );
		if ( 400 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$minimal  = array(
				'contents' => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => $prompt ) ),
					),
				),
			);
			$response = $this->request( $minimal );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		$body = is_array( $body ) ? $body : array();
		if ( $code < 200 || $code >= 300 ) {
			throw Validator::api_exception( $this->label(), $response, $body, $this->api_key );
		}
		$parts = (array) ( $body['candidates'][0]['content']['parts'] ?? array() );
		$data  = '';
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) || ! isset( $part['inlineData']['data'] ) ) {
				continue;
			}
			$data = Validator::decode_base64( (string) $part['inlineData']['data'] );
			break;
		}
		if ( '' === $data ) {
			throw new \RuntimeException( __( 'Google AI Studio の応答から画像を取り出せませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$info = Validator::image( $data );
		return array(
			'data'  => $data,
			'mime'  => $info['mime'],
			'model' => $this->model,
		);
	}
	public function test(): array {
		$response = wp_remote_get(
			self::MODELS_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array( 'x-goog-api-key' => $this->api_key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'type'    => 'error',
				'message' => Validator::scrub( $response->get_error_message(), $this->api_key ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();
		if ( 200 !== $code ) {
			return array(
				'type'    => 'error',
				'message' => Validator::scrub(
					sprintf(
						__( 'Google AI Studio の接続テストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
						Validator::api_message( $body )
					),
					$this->api_key
				),
			);
		}
		$found = false;
		foreach ( (array) ( $body['models'] ?? array() ) as $model ) {
			$name = (string) ( $model['name'] ?? '' );
			$name = str_replace( 'models/', '', $name );
			if ( $name === $this->model ) {
				$found = true;
				break;
			}
		}
		return array(
			'type'    => $found ? 'success' : 'warning',
			'message' => $found
				? sprintf(
					__( 'Google AI Studio に接続できました（モデル: %s）。', GACHIREAI_TEXT_DOMAIN ),
					$this->model
				)
				: sprintf(
					__( "Google AI Studio に接続できましたが、モデル「%s」が一覧に見つかりません。\nモデル名を確認してください。", GACHIREAI_TEXT_DOMAIN ),
					$this->model
				),
		);
	}
	private function request( array $payload ): array {
		$response = wp_remote_post(
			self::ENDPOINT . rawurlencode( $this->model ) . ':generateContent',
			array(
				'timeout' => 120,
				'headers' => array(
					'x-goog-api-key' => $this->api_key,
					'Content-Type'   => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( Validator::scrub(
				sprintf(
					__( 'Google AI Studio へのリクエストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$response->get_error_message()
				),
				$this->api_key
			) );
		}
		return (array) $response;
	}
}

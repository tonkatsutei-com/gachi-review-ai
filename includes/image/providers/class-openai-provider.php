<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image\Providers;
use Tonkatsutei\Gachi_Review_AI\Image\Image_Catalog;
use Tonkatsutei\Gachi_Review_AI\Image\Provider;
use Tonkatsutei\Gachi_Review_AI\Image\Provider_Exception;
use Tonkatsutei\Gachi_Review_AI\Image\Validator;
final class Openai_Provider implements Provider {
	private const ENDPOINT = 'https://api.openai.com/v1/images/generations';
	private const MODELS_ENDPOINT = 'https://api.openai.com/v1/models';
	private string $api_key;
	private string $model;
	public function __construct( string $api_key, string $model ) {
		$this->api_key = trim( $api_key );
		$this->model   = Image_Catalog::normalize_model( Image_Catalog::OPENAI, $model );
	}
	public function model(): string {
		return $this->model;
	}
	public function label(): string {
		return Image_Catalog::label( Image_Catalog::OPENAI );
	}
	public function generate( string $prompt, array $opts = array() ): array {
		if ( '' === $this->api_key ) {
			throw new \RuntimeException( __( 'OpenAI の API キーが未設定です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$payload = array(
			'model'         => $this->model,
			'prompt'        => trim( $prompt ),
			'size'          => (string) ( $opts['size'] ?? '1024x1024' ),
			'quality'       => (string) ( $opts['quality'] ?? 'low' ),
			'output_format' => (string) ( $opts['output_format'] ?? 'png' ),
			'n'             => 1,
		);
		$response = $this->request( $payload );
		if ( 400 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$response = $this->request(
				array(
					'model'  => $this->model,
					'prompt' => trim( $prompt ),
					'n'      => 1,
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		$body = is_array( $body ) ? $body : array();
		if ( $code < 200 || $code >= 300 ) {
			throw Validator::api_exception( $this->label(), $response, $body, $this->api_key );
		}
		$encoded = isset( $body['data'][0]['b64_json'] ) && is_scalar( $body['data'][0]['b64_json'] )
			? (string) $body['data'][0]['b64_json']
			: '';
		if ( '' === $encoded ) {
			$url = isset( $body['data'][0]['url'] ) && is_scalar( $body['data'][0]['url'] )
				? (string) $body['data'][0]['url']
				: '';
			if ( '' === $url ) {
				throw new \RuntimeException( __( 'OpenAI の応答から画像を取り出せませんでした。', GACHIREAI_TEXT_DOMAIN ) );
			}
			$image_response = wp_remote_get( $url, array( 'timeout' => 120 ) );
			if ( is_wp_error( $image_response ) ) {
				throw new \RuntimeException( Validator::scrub( $image_response->get_error_message(), $this->api_key ) );
			}
			$data = (string) wp_remote_retrieve_body( $image_response );
		} else {
			$data = Validator::decode_base64( $encoded );
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
				'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key ),
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
						__( 'OpenAI の接続テストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
						Validator::api_message( $body )
					),
					$this->api_key
				),
			);
		}
		$found = false;
		foreach ( (array) ( $body['data'] ?? array() ) as $model ) {
			if ( (string) ( $model['id'] ?? '' ) === $this->model ) {
				$found = true;
				break;
			}
		}
		return array(
			'type'    => $found ? 'success' : 'warning',
			'message' => $found
				? sprintf(
					__( 'OpenAI に接続できました（モデル: %s）。', GACHIREAI_TEXT_DOMAIN ),
					$this->model
				)
				: sprintf(
					__( "OpenAI に接続できましたが、モデル「%s」が一覧に見つかりません。\nモデル名を確認してください。", GACHIREAI_TEXT_DOMAIN ),
					$this->model
				),
		);
	}
	private function request( array $payload ): array {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( Validator::scrub(
				sprintf(
					__( 'OpenAI へのリクエストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$response->get_error_message()
				),
				$this->api_key
			) );
		}
		return (array) $response;
	}
}

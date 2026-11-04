<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
final class Jev_Client {
	public const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';
	public const DEFAULT_MODEL = 'jev-latest';
	public const MODELS = array( 'jev-latest', 'jev-1.13.0' );
	private const TIMEOUT = 20;
	private const TEST_STATE = 'OK';
	private const TEST_QUESTION_ID = 'is_ok';
	private const TEST_QUESTIONS = array(
		'is_ok' => array(
			'type'         => 'noul',
			'instructions' => 'state は「OK」と書かれているか。',
		),
	);
	private const MESSAGE_MAX = 300;
	private string $api_key;
	private string $model;
	public function __construct( string $api_key, string $model = '' ) {
		$this->api_key = trim( $api_key );
		$this->model   = '' !== trim( $model ) ? trim( $model ) : self::DEFAULT_MODEL;
	}
	public static function model_choices(): array {
		return self::MODELS;
	}
	public static function default_model(): string {
		return self::DEFAULT_MODEL;
	}
	public function judge( string $state, array $questions ): array {
		if ( '' === $this->api_key ) {
			throw new \RuntimeException( __( 'Jev の API キーが未設定です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( '' === trim( $state ) ) {
			throw new \RuntimeException( __( '判定する本文が空です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( array() === $questions ) {
			throw new \RuntimeException( __( '判定の質問がありません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode(
					array(
						'model'     => $this->model,
						'state'     => $state,
						'questions' => $questions,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Provider_Exception(
				sprintf(
					__( 'Jev への通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->scrub( $response->get_error_message() )
				)
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		if ( $status < 200 || $status >= 300 ) {
			throw new Provider_Exception( $this->error_message( $status, is_array( $data ) ? $data : array(), $body ), $status );
		}
		if ( ! is_array( $data ) ) {
			throw new Provider_Exception(
				sprintf(
					__( 'Jev の応答を JSON として解釈できませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->scrub( $body )
				),
				$status
			);
		}
		if ( ! isset( $data['answers'] ) || ! is_array( $data['answers'] ) ) {
			throw new Provider_Exception(
				__( 'Jev の応答に判定結果（answers）がありません。', GACHIREAI_TEXT_DOMAIN ),
				$status
			);
		}
		return array(
			'answers' => (array) $data['answers'],
			'model'   => (string) ( $data['model'] ?? '' ),
			'usage'   => is_array( $data['usage'] ?? null ) ? (array) $data['usage'] : array(),
		);
	}
	public function test(): array {
		if ( '' === $this->api_key ) {
			return array(
				'type'      => 'warning',
				'message'   => __( 'API キーが未設定です。入力してから接続テストを実行してください。', GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		try {
			$result = $this->judge( self::TEST_STATE, self::TEST_QUESTIONS );
		} catch ( \Throwable $e ) {
			return array(
				'type'      => 'error',
				'message'   => sprintf(
					__( '接続テストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				),
				'connected' => false,
			);
		}
		$noul = $result['answers'][ self::TEST_QUESTION_ID ]['noul'] ?? null;
		$used = '' !== $result['model'] ? $result['model'] : $this->model;
		if ( ! is_numeric( $noul ) ) {
			return array(
				'type'      => 'warning',
				'message'   => sprintf(
					__( '接続は成功しましたが、判定の応答形式が想定と違いました（モデル: %s）。モデル名が正しいか確認してください。', GACHIREAI_TEXT_DOMAIN ),
					$used
				),
				'connected' => false,
			);
		}
		return array(
			'type'      => 'success',
			'message'   => sprintf(
				__( '接続テストに成功しました（モデル: %s）。', GACHIREAI_TEXT_DOMAIN ),
				$used
			),
			'connected' => true,
		);
	}
	private function error_message( int $status, array $body, string $raw ): string {
		$message = Provider_Error::message( $body );
		$message = $this->scrub( '' !== $message ? $message : $raw );
		$hint    = Provider_Error::hint( $status, $this->scrub( $raw ) );
		$text = sprintf(
			__( 'エラーを受け取りました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
			$status,
			$message
		);
		return '' !== $hint ? $text . ' ' . $hint : $text;
	}
	private function scrub( string $message ): string {
		if ( '' !== $this->api_key ) {
			$message = str_replace( $this->api_key, '***', $message );
		}
		return mb_substr( trim( $message ), 0, self::MESSAGE_MAX );
	}
}

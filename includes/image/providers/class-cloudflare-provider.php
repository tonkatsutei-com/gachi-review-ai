<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image\Providers;
use Tonkatsutei\Gachi_Review_AI\Image\Image_Catalog;
use Tonkatsutei\Gachi_Review_AI\Image\Provider;
use Tonkatsutei\Gachi_Review_AI\Image\Provider_Exception;
use Tonkatsutei\Gachi_Review_AI\Image\Validator;
final class Cloudflare_Provider implements Provider {
	private const ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/';
	private const MAX_PROMPT = 2048;
	private const MAX_SAFETY_RETRIES = 2;
	private static array $safety_retry_delays = array( 2, 4 );
	private const SAFE_FALLBACK = 'A calm, bright, tidy modern home interior with natural window light, soft neutral tones, a cup and a plant on a wooden table, minimal editorial lifestyle photography, no text, no letters, no logos, no watermark.';
	private string $account_id;
	private string $api_key;
	private string $model;
	private int $steps;
	public function __construct( string $account_id, string $api_key, string $model, int $steps = 4 ) {
		$this->account_id = trim( $account_id );
		$this->api_key    = trim( $api_key );
		$this->model      = Image_Catalog::normalize_model( Image_Catalog::CLOUDFLARE, $model );
		$this->steps      = max( 1, min( 8, $steps ) );
	}
	public function model(): string {
		return $this->model;
	}
	public function label(): string {
		return Image_Catalog::label( Image_Catalog::CLOUDFLARE );
	}
	public function generate( string $prompt, array $opts = array() ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/i', $this->account_id ) ) {
			throw new \RuntimeException( __( 'Cloudflare の Account ID（32桁の16進）を確認してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( '' === $this->api_key ) {
			throw new \RuntimeException( __( 'Cloudflare の API トークンが未設定です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$steps    = isset( $opts['steps'] ) ? (int) $opts['steps'] : $this->steps;
		$steps    = max( 1, min( 8, $steps ) );
		$prompt   = self::sanitize_prompt( $prompt );
		$fallback = self::sanitize_prompt( (string) ( $opts['fallback_prompt'] ?? '' ) );
		if ( mb_strlen( $prompt ) < 20 ) {
			$prompt = '' !== $fallback ? $fallback : self::SAFE_FALLBACK;
		}
		list( $response, $code, $raw, $body ) = $this->request( $prompt, $steps );
		if ( self::is_nsfw_error( $raw ) ) {
			$safe = '' !== $fallback ? $fallback : self::SAFE_FALLBACK;
			if ( $safe !== $prompt ) {
				$prompt = $safe;
				list( $response, $code, $raw, $body ) = $this->request( $prompt, $steps );
			}
		}
		$attempt = 0;
		while ( self::is_nsfw_error( $raw ) && $attempt < self::MAX_SAFETY_RETRIES ) {
			++$attempt;
			sleep( self::$safety_retry_delays[ $attempt - 1 ] ?? 4 );
			list( $response, $code, $raw, $body ) = $this->request( $prompt, $steps );
		}
		if ( self::is_nsfw_error( $raw ) ) {
			throw new Provider_Exception(
				__( "Cloudflare の安全フィルターでプロンプトが拒否されました。\n安全な汎用プロンプトでも生成できませんでした。\n時間をおいて再試行するか、別の画像生成AIを選んでください。", GACHIREAI_TEXT_DOMAIN ),
				$code
			);
		}
		if ( $code < 200 || $code >= 300 || ( isset( $body['success'] ) && ! $body['success'] ) ) {
			throw Validator::api_exception( $this->label(), $response, $body, $this->api_key );
		}
		$encoded = isset( $body['result']['image'] ) && is_scalar( $body['result']['image'] )
			? (string) $body['result']['image']
			: '';
		$data = '' !== $encoded ? Validator::decode_base64( $encoded ) : $raw;
		$info = Validator::image( $data );
		return array(
			'data'  => $data,
			'mime'  => $info['mime'],
			'model' => $this->model,
		);
	}
	private static function sanitize_prompt( string $prompt ): string {
		$prompt = wp_strip_all_tags( $prompt );
		$prompt = (string) preg_replace( '/[^\x20-\x7E]/', ' ', $prompt );
		$words = array(
			'nsfw', 'nude', 'nudity', 'naked', 'sex', 'sexual', 'porn', 'erotic',
			'adult', 'mature', 'sensual', 'seductive', 'fetish', 'lingerie',
			'underwear', 'breast', 'genital', 'child', 'children', 'minor', 'kid',
			'boy', 'girl', 'teen', 'teenager', 'loli', 'shota', 'blood', 'gore',
			'violence', 'weapon', 'drug', 'suicide', 'self-harm',
		);
		foreach ( $words as $word ) {
			$prompt = (string) preg_replace( '/\b' . preg_quote( $word, '/' ) . '\b/i', ' ', $prompt );
		}
		$prompt = trim( (string) preg_replace( '/\s+/', ' ', $prompt ) );
		return mb_substr( $prompt, 0, self::MAX_PROMPT );
	}
	private static function is_nsfw_error( string $raw ): bool {
		return false !== stripos( $raw, 'NSFW' ) || false !== stripos( $raw, 'safety' );
	}
	private function request( string $prompt, int $steps ): array {
		$response = $this->post( $prompt, $steps );
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw      = (string) wp_remote_retrieve_body( $response );
		$body     = json_decode( $raw, true );
		return array( $response, $code, $raw, is_array( $body ) ? $body : array() );
	}
	private function post( string $prompt, int $steps ): array {
		$response = wp_remote_post(
			self::ENDPOINT . rawurlencode( $this->account_id ) . '/ai/run/' . $this->model,
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'prompt' => $prompt,
						'steps'  => $steps,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( Validator::scrub(
				sprintf(
					__( 'Cloudflare へのリクエストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$response->get_error_message()
				),
				$this->api_key
			) );
		}
		return (array) $response;
	}
	public function test(): array {
		try {
			$this->generate(
				'A simple red circle centered on a white background, flat icon, no text, no letters.',
				array( 'steps' => 1 )
			);
			return array(
				'type'    => 'success',
				'message' => sprintf(
					__( 'Cloudflare Workers AI に接続できました（モデル: %s）。', GACHIREAI_TEXT_DOMAIN ),
					$this->model
				),
			);
		} catch ( \Throwable $e ) {
			return array(
				'type'    => 'error',
				'message' => Validator::scrub( $e->getMessage(), $this->api_key ),
			);
		}
	}
}

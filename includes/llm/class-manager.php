<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Llm\Providers\Anthropic_Provider;
use Tonkatsutei\Gachi_Review_AI\Llm\Providers\Cerebras_Provider;
use Tonkatsutei\Gachi_Review_AI\Llm\Providers\Deepseek_Provider;
use Tonkatsutei\Gachi_Review_AI\Llm\Providers\Google_Provider;
use Tonkatsutei\Gachi_Review_AI\Llm\Providers\Groq_Provider;
use Tonkatsutei\Gachi_Review_AI\Llm\Providers\Openai_Provider;
final class Manager {
	private const JSON_INSTRUCTION = '出力は JSON オブジェクトだけにしてください（前後に説明文やコードフェンスを付けない）。';
	private const JSON_MAX_TOKENS = 8192;
	private const MAX_RETRIES = 2;
	private static array $retry_delays = array( 2, 4 );
	private static $progress_callback = null;
	private static int $retry_count = 0;
	public static function set_progress_callback( ?callable $callback ): void {
		self::$progress_callback = $callback;
	}
	public static function reset_retry_count(): void {
		self::$retry_count = 0;
	}
	public static function retry_count(): int {
		return self::$retry_count;
	}
	public static function generate( Provider $provider, string $system, string $user, array $opts = array() ): array {
		return self::generate_with_retry( $provider, $system, $user, $opts );
	}
	public static function provider( array $settings ): Provider {
		$provider = (string) ( $settings['provider'] ?? Model_Catalog::OPENAI );
		$api_key  = (string) ( $settings['api_key'] ?? '' );
		$model    = (string) ( $settings['model'] ?? '' );
		switch ( $provider ) {
			case Model_Catalog::DEEPSEEK:
				return new Deepseek_Provider( $api_key, $model );
			case Model_Catalog::GOOGLE:
				return new Google_Provider( $api_key, $model );
			case Model_Catalog::GROQ:
				return new Groq_Provider( $api_key, $model );
			case Model_Catalog::CEREBRAS:
				return new Cerebras_Provider( $api_key, $model );
			case 'anthropic':
				return new Anthropic_Provider( $api_key, $model );
			case Model_Catalog::OPENAI:
			default:
				return new Openai_Provider( $api_key, $model );
		}
	}
	public static function gateway_enabled(): bool {
		if ( ! Gateway_Client::is_configured() ) {
			return false;
		}
		return Status::capabilities()->can_fetch_prompts();
	}
	private const SERVER_ONLY_PROMPTS = array(
		'article.system.experience',
		'article.system.dialogue',
		'source_proposer.system',
		'source_select.fivech',
		'source_select.reddit',
	);
	public static function is_server_only( string $prompt_key ): bool {
		return in_array( trim( $prompt_key ), self::SERVER_ONLY_PROMPTS, true );
	}
	public static function provider_for( array $settings, string $prompt_key, bool $json = true ): Provider {
		$prompt_key = trim( $prompt_key );
		if ( self::is_server_only( $prompt_key ) ) {
			if ( ! self::gateway_enabled() ) {
				throw new \RuntimeException(
					__( 'この機能は BASIC 以上のライセンスと LLM ゲートウェイの設定が必要です。', GACHIREAI_TEXT_DOMAIN )
				);
			}
			return new Gateway_Provider( $settings, $prompt_key, $json );
		}
		return self::provider( $settings );
	}
	public static function generate_json( Provider $provider, string $system, string $user, array $opts = array() ): array {
		$json_mode = $provider->supports_json_mode();
		if ( $json_mode ) {
			$opts['response_format'] = array( 'type' => 'json_object' );
		} else {
			$user .= "\n\n" . self::JSON_INSTRUCTION;
		}
		try {
			$result = self::generate_with_retry( $provider, $system, $user, $opts );
		} catch ( \RuntimeException $e ) {
			if ( ! $json_mode || ! self::is_response_format_rejection( $e ) ) {
				throw $e;
			}
			unset( $opts['response_format'] );
			$result = self::generate_with_retry( $provider, $system, $user . "\n\n" . self::JSON_INSTRUCTION, $opts );
		}
		try {
			return self::decode_json( (string) ( $result['content'] ?? '' ) );
		} catch ( \RuntimeException $e ) {
			unset( $opts['response_format'] );
			$retry_opts = self::widen_json_tokens( $opts, $result );
			$retry      = self::generate_with_retry(
				$provider,
				$system,
				$user . "\n\n" . self::JSON_INSTRUCTION . ' 前置き・説明・コードフェンス・思考過程を付けず、JSON オブジェクトだけを返してください。',
				$retry_opts
			);
			try {
				return self::decode_json( (string) ( $retry['content'] ?? '' ) );
			} catch ( \RuntimeException $inner ) {
				throw new \RuntimeException( self::json_failure_message( $retry ) );
			}
		}
	}
	private static function widen_json_tokens( array $opts, array $result ): array {
		$finish  = (string) ( $result['finish_reason'] ?? '' );
		$content = trim( (string) ( $result['content'] ?? '' ) );
		if ( 'length' !== $finish && '' !== $content ) {
			return $opts;
		}
		$current = isset( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : 4000;
		$opts['max_tokens'] = min( self::JSON_MAX_TOKENS, max( 4000, $current * 2 ) );
		return $opts;
	}
	private static function json_failure_message( array $result ): string {
		$finish = (string) ( $result['finish_reason'] ?? '' );
		$length = strlen( trim( (string) ( $result['content'] ?? '' ) ) );
		$hint = 'length' === $finish
			? '応答がトークン上限で途中で切れました。モデルを変えるか、max_tokens を増やしてください。'
			: 'モデルが JSON 以外の応答を返しました。モデルを変えて再試行してください。';
		return sprintf(
			'LLM の応答を JSON として解釈できませんでした（finish_reason=%1$s / 応答 %2$d 文字）。%3$s',
			'' !== $finish ? $finish : 'unknown',
			$length,
			$hint
		);
	}
	private static function decode_json( string $content ): array {
		$text = trim( $content );
		if ( preg_match( '/^```[a-zA-Z]*\s*(.*?)\s*```$/s', $text, $matches ) ) {
			$text = trim( (string) $matches[1] );
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) && preg_match( '/\{.*\}/s', $text, $matches ) ) {
			$data = json_decode( (string) $matches[0], true );
		}
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'LLM の応答を JSON として解釈できませんでした。' );
		}
		return $data;
	}
	private static function generate_with_retry( Provider $provider, string $system, string $user, array $opts ): array {
		$attempt = 0;
		while ( true ) {
			try {
				return $provider->generate( $system, $user, $opts );
			} catch ( Provider_Exception $e ) {
				Connection_Status::note_runtime_failure( 'llm', $e->status() );
				if ( ! $e->is_retryable() || $attempt >= self::MAX_RETRIES ) {
					throw $e;
				}
				++$attempt;
				++self::$retry_count;
				self::record_retry( $attempt, $e );
				self::report_progress(
					array(
						'label' => sprintf(
							__( 'リトライ待機中（%1$d/%2$d）...', GACHIREAI_TEXT_DOMAIN ),
							$attempt,
							self::MAX_RETRIES
						),
						'retry' => array(
							'state'   => 'wait',
							'attempt' => $attempt,
							'max'     => self::MAX_RETRIES,
						),
					)
				);
				sleep( self::$retry_delays[ $attempt - 1 ] ?? 4 );
				self::report_progress(
					array(
						'label' => sprintf(
							__( 'リトライ中（%1$d/%2$d）...', GACHIREAI_TEXT_DOMAIN ),
							$attempt,
							self::MAX_RETRIES
						),
						'retry' => array(
							'state'   => 'run',
							'attempt' => $attempt,
							'max'     => self::MAX_RETRIES,
						),
					)
				);
			}
		}
	}
	private static function record_retry( int $attempt, Provider_Exception $e ): void {
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_WARN,
			sprintf(
				__( 'LLM の一時エラーのため再試行します（%1$d/%2$d・HTTP %3$d）。', GACHIREAI_TEXT_DOMAIN ),
				$attempt,
				self::MAX_RETRIES,
				$e->status()
			),
			array(
				'attempt' => $attempt,
				'max'     => self::MAX_RETRIES,
				'status'  => $e->status(),
			)
		);
	}
	private static function report_progress( array $notification ): void {
		if ( null === self::$progress_callback ) {
			return;
		}
		( self::$progress_callback )( $notification );
	}
	private static function is_response_format_rejection( \RuntimeException $e ): bool {
		return str_contains( $e->getMessage(), 'response_format' );
	}
}

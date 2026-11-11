<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
use Tonkatsutei\Gachi_Review_AI\Image\Image_Catalog;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Style_Proposer {
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== (string) ( $this->llm['api_key'] ?? '' );
	}
	public static function unavailable_message(): string {
		return __( '文章生成AI（LLM）の API キーが未設定のため使えません。「AI設定」タブで LLM を先に設定してください。', GACHIREAI_TEXT_DOMAIN );
	}
	public function propose(): string {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$families  = Image_Catalog::families();
		$family    = array() !== $families ? (string) array_rand( $families ) : '';
		$direction = '' !== $family && isset( $families[ $family ] )
			? (string) $families[ $family ]
			: '描き方のタッチ';
		$system = implode(
			"\n",
			array(
				'あなたは画像生成AI向けの「画風」を決めるディレクターです。',
				'サイト全体の画像で使う画風（どんなタッチ・技法・画材で描くか）の指定文を 1 つ提案します。',
				'画風は「描き方のタッチ」です。被写体・構図・シーン（何を描くか）は書かないでください。',
				'日本語で 60 文字以内。技法・画材・タッチを具体的に（例：水彩、油彩、フラットベクター、3Dレンダリング、アニメセル画、ペン画 など）。',
				'商品名・ブランド名・型番・商標は含めないでください。',
				'指定文だけを出力し、前置き・説明・引用符・見出し・箇条書きは付けないでください。',
			)
		);
		$user = '今回は「' . $direction . '」系統の画風（タッチ）で、指定文を日本語で 1 つ提案してください。';
		$text = $this->askLlm( $system, $user, 1.0, 300 );
		if ( '' === $text ) {
			throw new \RuntimeException( __( 'LLM から空の応答が返されました。もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return mb_substr( $text, 0, 300 );
	}
	public function to_english( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$system = implode(
			"\n",
			array(
				'You translate a Japanese art-style description into ONE concise English image-style prompt.',
				'Output only the English prompt (max 40 words). No quotes, no labels, no explanation.',
				'Focus on the drawing touch, technique, and medium (e.g. watercolor, oil paint, flat vector, cel-shaded). Do not add subjects, composition, or scene details. Do not include brand or product names.',
			)
		);
		try {
			$en = $this->askLlm( $system, "Japanese art style:\n" . $text, 0.3, 200 );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( __( '画風の英語化に失敗しました: ', GACHIREAI_TEXT_DOMAIN ) . $e->getMessage() );
		}
		if ( '' === $en ) {
			throw new \RuntimeException( __( 'LLM から空の応答が返されました。もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return mb_substr( $en, 0, 400 );
	}
	private function askLlm( string $system, string $user, float $temperature, int $max_tokens ): string {
		try {
			$provider = Manager::provider( $this->llm );
		$result   = Manager::generate(
			$provider,
			$system,
			$user,
			array(
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
			)
		);
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( __( 'LLM との通信に失敗しました: ', GACHIREAI_TEXT_DOMAIN ) . $e->getMessage() );
		}
		$text = trim( (string) ( $result['content'] ?? '' ) );
		$text = (string) preg_replace( '/^```[a-zA-Z]*\s*(.*?)\s*```$/s', '$1', $text );
		$text = trim( $text, " \t\n\r\0\x0B\"'“”‘’" );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return $text;
	}
}

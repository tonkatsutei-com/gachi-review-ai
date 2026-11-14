<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Llm\Jev_Questions;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Review_Cleaner {
	private const MAX_TEXT = 600;
	private const MAX_TOKENS = 8192;
	private const PROMPT_KEY = 'review_clean.system';
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== (string) ( $this->llm['api_key'] ?? '' );
	}
	public function process( array $reviews, array $product ): array {
		$targets = array();
		foreach ( $reviews as $index => $review ) {
			if ( ! is_array( $review ) ) {
				continue;
			}
			if ( ! Jev_Questions::is_clean( Jev_Questions::verdict_of( (string) ( $review['judge'] ?? '' ) ) ) ) {
				continue;
			}
			$text = $this->thread_text( $review );
			if ( '' === $text ) {
				continue;
			}
			$targets[ $index ] = array(
				'title' => trim( (string) ( $review['title'] ?? '' ) ),
				'text'  => $text,
			);
		}
		if ( array() === $targets ) {
			return $reviews;
		}
		if ( ! $this->is_available() ) {
			$this->warn( __( 'API キーが未設定のため、レビューの不要部分を除去できませんでした（要クリーニングのレビューは元のまま使います）。「AI設定」タブで LLM の API キーを保存してください。', GACHIREAI_TEXT_DOMAIN ), count( $targets ) );
			return $reviews;
		}
		try {
			$results = $this->clean( $product, $targets );
		} catch ( \Throwable $e ) {
			$this->warn(
				sprintf(
					__( 'レビューの不要部分を除去できませんでした（元の本文のまま使います）: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				),
				count( $targets )
			);
			return $reviews;
		}
		$indexes = array_keys( $targets );
		foreach ( $results as $position => $text ) {
			if ( ! isset( $indexes[ $position ] ) || '' === $text ) {
				continue;
			}
			$reviews[ $indexes[ $position ] ]['text'] = $text;
		}
		return $reviews;
	}
	private function clean( array $product, array $targets ): array {
		$provider = Manager::provider_for( $this->llm, self::PROMPT_KEY );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt(),
			self::user_prompt( $product, $targets ),
			array(
				'temperature' => 0.2,
				'max_tokens'  => self::MAX_TOKENS,
			)
		);
		return self::normalize( $data, count( $targets ) );
	}
	private function thread_text( array $thread ): string {
		$text = trim( (string) ( $thread['text'] ?? '' ) );
		if ( '' !== $text ) {
			return $text;
		}
		$thread_id = (int) ( $thread['id'] ?? 0 );
		if ( $thread_id <= 0 ) {
			return '';
		}
		global $wpdb;
		$table = Activator::table( 'comments' );
		$bodies = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT body FROM {$table} WHERE thread_id = %d ORDER BY score DESC LIMIT %d",
				$thread_id,
				10
			)
		);
		$bodies = is_array( $bodies ) ? $bodies : array();
		$text   = implode( "\n\n", array_filter( array_map( 'trim', array_map( 'strval', $bodies ) ), 'strlen' ) );
		return $text;
	}
	private static function system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のレビュー編集者です。投稿から「商品そのものの評価」だけを残し、それ以外の不要な部分を除去します。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: reviews',
				'reviews は配列で、各要素は {"index": 元のレビューの番号, "text": "除去後の本文"} の形式です。',
				'元の評価（良い/悪い）・具体的な事実（商品の特徴・使用感・不満点など）は変えないでください。',
				'商品そのものへの評価が残らない場合は、その要素の text を空文字にしてください。',
				'HTML タグ・Markdown 記号（# や * など）は入れず、テキストだけを入れてください。',
				'存在しない事実を創作しないでください。',
			)
		);
	}
	private static function user_prompt( array $product, array $targets ): string {
		$parts = array();
		$parts[] = self::product_context( $product );
		$parts[] = '【除去するもの】' . "\n" . '配送・梱包・ショップの対応・返品手続き・価格・在庫・注文に関する話／広告・宣伝・キャンペーンの告知／別の商品や無関係な話題。これらを取り除き、**商品そのもの（品質・性能・使い勝手・満足度・作り）への評価・感想だけ**を残してください。';
		$lines = array();
		$index = 1;
		foreach ( $targets as $target ) {
			$title   = '' !== $target['title'] ? $target['title'] . "\n" : '';
			$lines[] = $index . '. ' . $title . $target['text'];
			++$index;
		}
		$parts[] = '【元のレビュー】' . "\n" . implode( "\n\n", $lines );
		$parts[] = '上のレビューから不要な部分を除去し、JSON で返してください。';
		return implode( "\n\n", $parts );
	}
	private static function product_context( array $product ): string {
		$lines = array();
		$keyed = array(
			'product_name' => '商品名',
			'brand'        => 'ブランド',
			'model_number' => '型番',
		);
		foreach ( $keyed as $key => $label ) {
			$value = $product[ $key ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . trim( (string) $value );
			}
		}
		return '【商品】' . "\n" . implode( "\n", $lines );
	}
	private static function normalize( array $data, int $count ): array {
		$raw = $data['reviews'] ?? $data['items'] ?? $data;
		if ( is_scalar( $raw ) ) {
			$raw = array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$texts    = array_fill( 0, $count, '' );
		$fallback = 0;
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				$text = self::clean_text( $item );
				if ( '' !== $text && $fallback < $count ) {
					$texts[ $fallback ] = mb_substr( $text, 0, self::MAX_TEXT );
					++$fallback;
				}
				continue;
			}
			$index = (int) ( $item['index'] ?? 0 );
			$text  = self::clean_text( $item['text'] ?? $item['body'] ?? $item['content'] ?? '' );
			if ( '' === $text ) {
				continue;
			}
			if ( $index >= 1 && $index <= $count ) {
				$texts[ $index - 1 ] = mb_substr( $text, 0, self::MAX_TEXT );
			} elseif ( $fallback < $count ) {
				$texts[ $fallback ] = mb_substr( $text, 0, self::MAX_TEXT );
				++$fallback;
			}
		}
		return $texts;
	}
	private static function clean_text( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( (string) $value );
	}
	private function warn( string $message, int $count ): void {
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_WARN,
			$message,
			array( 'clean' => $count )
		);
	}
}

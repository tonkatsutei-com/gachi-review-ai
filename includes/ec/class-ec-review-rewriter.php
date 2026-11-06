<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Ec;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Ec_Review_Rewriter {
	private const MAX_TEXT = 600;
	private const MAX_TOKENS = 3000;
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== (string) ( $this->llm['api_key'] ?? '' );
	}
	public static function unavailable_message(): string {
		return __( 'API キーが未設定のため、EC公式サイトのレビューをAIで加工できません。「AI設定」タブで LLM の API キーを保存するか、「リライト」で「リスクを承知でそのまま転載する」を選んでください。', GACHIREAI_TEXT_DOMAIN );
	}
	public function process( array $reviews, array $product ): array {
		$mode = Ec_Review_Use::mode();
		if ( Ec_Review_Use::MODE_COPY === $mode ) {
			return $reviews;
		}
		$targets = array();
		foreach ( $reviews as $index => $review ) {
			if ( ! is_array( $review ) || Ec_Review_Collector::SOURCE !== (string) ( $review['source'] ?? '' ) ) {
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
			throw new \RuntimeException( self::unavailable_message() );
		}
		$results = $this->rewrite( $mode, $product, $targets );
		if ( Ec_Review_Use::MODE_SYNTHESIZE === $mode ) {
			return $this->synthesize( $reviews, $targets, $results, $product );
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
	private function rewrite( string $mode, array $product, array $targets ): array {
		$provider = Manager::provider_for( $this->llm, 'ec_rewrite.system' );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt(),
			self::user_prompt( $mode, $product, $targets ),
			array(
				'temperature' => 0.5,
				'max_tokens'  => self::MAX_TOKENS,
			)
		);
		return self::normalize( $data, count( $targets ), Ec_Review_Use::MODE_SYNTHESIZE === $mode );
	}
	private function synthesize( array $reviews, array $targets, array $results, array $product ): array {
		$text = (string) ( $results[0] ?? '' );
		if ( '' === $text ) {
			return $reviews;
		}
		$community = '';
		foreach ( $targets as $index => $_target ) {
			$community = trim( (string) ( $reviews[ $index ]['community'] ?? '' ) );
			if ( '' !== $community ) {
				break;
			}
		}
		$first_index = array_key_first( $targets );
		$synthesized = array(
			'source'       => Ec_Review_Collector::SOURCE,
			'external_id'  => '',
			'community'    => $community,
			'title'        => '',
			'product_name' => (string) ( $product['product_name'] ?? '' ),
			'permalink'    => '',
			'url'          => '',
			'score'        => 0,
			'num_comments' => 0,
			'judge'        => null,
			'rating'       => null,
			'text'         => $text,
		);
		$merged = array();
		foreach ( $reviews as $index => $review ) {
			if ( $index === $first_index ) {
				$merged[] = $synthesized;
			}
			if ( array_key_exists( $index, $targets ) ) {
				continue;
			}
			$merged[] = $review;
		}
		return $merged;
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
		$body = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT body FROM {$table} WHERE thread_id = %d ORDER BY score DESC LIMIT 1",
				$thread_id
			)
		);
		return null === $body ? '' : trim( (string) $body );
	}
	private static function system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のレビュー編集者です。EC サイトに投稿されたレビューを、記事の素材として使える形に加工します。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: reviews',
				'reviews は配列で、各要素は {"index": 元のレビューの番号, "text": "加工後の本文"} の形式です。',
				'元のレビューの意味・評価（良い/悪い）・具体的な事実（商品の特徴・使用感・不満点など）は変えないでください。',
				'HTML タグ・Markdown 記号（# や * など）は入れず、テキストだけを入れてください。',
				'存在しない事実を創作しないでください。',
			)
		);
	}
	private static function user_prompt( string $mode, array $product, array $targets ): string {
		$parts = array();
		$parts[] = self::product_context( $product );
		$parts[] = '【加工方法】' . "\n" . self::instruction( $mode );
		$lines = array();
		$index = 1;
		foreach ( $targets as $target ) {
			$title   = '' !== $target['title'] ? $target['title'] . "\n" : '';
			$lines[] = $index . '. ' . $title . $target['text'];
			++$index;
		}
		$parts[] = '【元のレビュー】' . "\n" . implode( "\n\n", $lines );
		$parts[] = '上のレビューを加工し、JSON で返してください。';
		return implode( "\n\n", $parts );
	}
	private static function instruction( string $mode ): string {
		if ( Ec_Review_Use::MODE_SUMMARIZE === $mode ) {
			return '各レビューを 1〜2 文の簡潔な要約にしてください。元の評価（高評価・低評価）と、商品の長所・短所に関する具体的な事実は必ず残してください。';
		}
		if ( Ec_Review_Use::MODE_REWRITE === $mode ) {
			return '各レビューを、元の投稿者とは別の人が書いたように、自然な日本語で書き直してください。意味・評価・具体的な事実は変えないでください。';
		}
		return '複数のレビューを元に、1 つの新しいレビューを作成してください。複数の意見を統合し、商品の長所と短所をバランスよく含めてください。存在しない事実を創作しないでください。';
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
	private static function normalize( array $data, int $count, bool $single ): array {
		$raw = $data['reviews'] ?? $data['items'] ?? $data;
		if ( is_scalar( $raw ) ) {
			$raw = array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		if ( $single ) {
			foreach ( $raw as $item ) {
				$text = self::clean_text( is_array( $item ) ? ( $item['text'] ?? $item['body'] ?? $item['content'] ?? '' ) : $item );
				if ( '' !== $text ) {
					return array( mb_substr( $text, 0, self::MAX_TEXT ) );
				}
			}
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
}
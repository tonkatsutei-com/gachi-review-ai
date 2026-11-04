<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Ai_Review_Generator {
	public const SOURCE = 'ai';
	private const MAX_TITLE = 40;
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
		return __( 'API キーが未設定のため AI によるレビュー生成を行えません。「LLM設定」タブで LLM の API キーを保存してください。', GACHIREAI_TEXT_DOMAIN );
	}
	public function generate( Site_Profile $profile, array $product, int $count ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		if ( '' === trim( (string) ( $product['product_name'] ?? '' ) ) ) {
			throw new \RuntimeException( __( '商品名が無いため AI によるレビュー生成を行えません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$count    = max( 1, min( Ai_Review::MAX_COUNT, $count ) );
		$provider = Manager::provider_for( $this->llm, 'ai_review.system' );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt( $count ),
			self::user_prompt( $profile, $product, $count ),
			array(
				'temperature' => 1.0,
				'max_tokens'  => self::MAX_TOKENS,
			)
		);
		$reviews = self::normalize( $data, $count );
		if ( array() === $reviews ) {
			throw new \RuntimeException( __( 'AI の応答からレビューを取り出せませんでした。もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $reviews;
	}
	private static function system_prompt( int $count ): string {
		return '';
	}
	private static function user_prompt( Site_Profile $profile, array $product, int $count ): string {
		$parts = array();
		$site  = Site_Generator::scope_context( $profile->get_scope() );
		if ( '' !== $site ) {
			$parts[] = $site;
		}
		$parts[] = self::product_context( $product );
		$parts[] = 'この商品について、実際に購入・使用した人の口コミレビューを ' . $count . ' 件、JSON で作成してください。';
		$parts[] = $count . ' 件はそれぞれ別の人物が書いたように、書き出しの型・口調・文の長さ・観点を重複させず、できるだけ大きくばらつかせてください。';
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
			$raw = preg_split( '/\n{2,}/u', (string) $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$reviews = array();
		foreach ( $raw as $item ) {
			if ( count( $reviews ) >= $count ) {
				break;
			}
			$review = self::normalize_one( $item );
			if ( array() !== $review ) {
				$reviews[] = $review;
			}
		}
		return $reviews;
	}
	private static function normalize_one( $item ): array {
		if ( is_scalar( $item ) ) {
			$text = self::clean_text( $item );
			return '' === $text ? array() : self::review( '', $text );
		}
		if ( ! is_array( $item ) ) {
			return array();
		}
		$title = self::clean_text( $item['title'] ?? '' );
		$text = self::clean_text( $item['text'] ?? $item['body'] ?? $item['content'] ?? '' );
		return '' === $text ? array() : self::review( $title, $text );
	}
	private static function review( string $title, string $text ): array {
		return array(
			'source'      => self::SOURCE,
			'external_id' => '',
			'community'   => '',
			'title'       => mb_substr( $title, 0, self::MAX_TITLE ),
			'permalink'   => '',
			'url'         => '',
			'text'        => mb_substr( $text, 0, self::MAX_TEXT ),
		);
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

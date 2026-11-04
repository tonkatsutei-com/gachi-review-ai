<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Product_Identifier {
	public const KEYS = array( 'is_product_review', 'brand', 'product_name', 'model_number', 'confidence' );
	public const EXTRACT_KEYS = array( 'brand', 'product_name', 'model_number', 'jan_code', 'is_generic' );
	private const MAX_SELFTEXT = 3000;
	private const MAX_EXTRACT_TEXT = 2000;
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== (string) ( $this->llm['api_key'] ?? '' );
	}
	public static function unavailable_message(): string {
		return __( 'API キーが未設定のため商品の特定は使えません。「LLM設定」タブで LLM の API キーを保存すると有効になります。', GACHIREAI_TEXT_DOMAIN );
	}
	public function identify( string $title, string $selftext = '' ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider( $this->llm );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt(),
			self::user_prompt( $title, $selftext ),
			array( 'temperature' => 0.2 )
		);
		return self::normalize( $data );
	}
	public function extract_product( string $item_name, string $item_caption = '', string $item_detail = '' ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider_for( $this->llm, 'product.extract' );
		$data     = Manager::generate_json(
			$provider,
			self::extract_system_prompt(),
			self::extract_user_prompt( $item_name, $item_caption, $item_detail ),
			array( 'temperature' => 0.2 )
		);
		return self::normalize_extract( $data );
	}
	private static function extract_system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは EC（ネット通販）の商品名から「本当の商品名」を抽出するアシスタントです。',
				'楽天市場などの商品名は宣伝文（キャッチフレーズ）で、品名・品番が埋もれていることがあります。',
				'商品名・商品説明・商品詳細から、その商品を一意に特定できる情報を抽出してください。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: ' . implode( ', ', self::EXTRACT_KEYS ),
				'brand: ブランド・メーカー名（例: 東芝, TOSHIBA, アイリスオーヤマ）。不明なら空文字。',
				'product_name: ブランドを含む具体的な商品名（例: 東芝 REGZA 43C350X）。わからなければ空文字。',
				'model_number: 型番・品番（例: 43C350X, CDP-302）。不明なら空文字。',
				'jan_code: JANコード（13桁または8桁の数字。例: 4902530030468）。数字だけを返す。無ければ空文字。',
				'is_generic: 商品名が「テレビ」「イヤホン」「テレビ台」のような一般名称だけで、ブランドも型番も無く特定の商品を指さない場合は true。特定の商品を指せるなら false。',
				'送料無料・ポイント◯倍・ランキング1位 などの宣伝文は無視してください。',
				'商品名や説明に無い情報を推測で作らないでください（推測した商品名は返さない）。',
			)
		);
	}
	private static function extract_user_prompt( string $item_name, string $item_caption, string $item_detail ): string {
		return implode(
			"\n",
			array(
				'次の商品の「本当の商品名」を JSON で抽出してください。',
				'',
				'【商品名（キャッチフレーズ）】',
				trim( $item_name ),
				'',
				'【商品説明】',
				'' === trim( $item_caption ) ? '(なし)' : self::clip( $item_caption ),
				'',
				'【商品詳細】',
				'' === trim( $item_detail ) ? '(なし)' : self::clip( $item_detail ),
			)
		);
	}
	private static function clip( string $text ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= self::MAX_EXTRACT_TEXT ) {
			return $text;
		}
		return mb_substr( $text, 0, self::MAX_EXTRACT_TEXT ) . '…';
	}
	private static function normalize_extract( array $data ): array {
		return array(
			'brand'        => self::clean_text( $data['brand'] ?? '' ),
			'product_name' => self::clean_text( $data['product_name'] ?? '' ),
			'model_number' => self::clean_text( $data['model_number'] ?? '' ),
			'jan_code'     => self::clean_jan( $data['jan_code'] ?? '' ),
			'is_generic'   => self::clean_bool( $data['is_generic'] ?? false ),
		);
	}
	private static function clean_jan( $value ): string {
		$digits = (string) preg_replace( '/\D+/', '', self::clean_text( $value ) );
		return in_array( strlen( $digits ), array( 8, 13 ), true ) ? $digits : '';
	}
	private static function clean_bool( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( 'true', '1', 'yes', 'y', 'はい' ), true );
		}
		return (bool) $value;
	}
	private static function system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは Reddit の投稿から「レビュー対象の商品」を特定するアシスタントです。',
				'投稿が単一の商品のレビューかどうかを判定し、レビュー対象の商品を特定してください。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: ' . implode( ', ', self::KEYS ),
				'is_product_review: 単一の商品のレビュー（使用感・評価）なら true。質問・雑談・比較（A vs B）・複数商品の紹介・商品と無関係なら false。',
				'brand: ブランド名（例: Apple, Canon, Sony, Keychron）。不明なら空文字。',
				'product_name: 一般的な商品名（ブランド名を含める。例: Apple Watch SE 2 / Canon PowerShot G5 X / Keychron V1）。不明なら空文字。',
				'model_number: 型番・型名（例: SE2 / G5X / CDP-302 / V1）。不明なら空文字。',
				'confidence: 特定の確信度（0.0〜1.0 の数値）。',
				'投稿から読み取れる情報だけで特定し、推測で別の商品名を作らないでください。',
				'商品名は原語のまま（例: Canon PowerShot G5 X）返してください。日本語に訳さないでください。',
			)
		);
	}
	private static function user_prompt( string $title, string $selftext ): string {
		$body = trim( $selftext );
		if ( mb_strlen( $body ) > self::MAX_SELFTEXT ) {
			$body = mb_substr( $body, 0, self::MAX_SELFTEXT ) . '…';
		}
		return implode(
			"\n",
			array(
				'次の Reddit 投稿を分析してください。',
				'',
				'タイトル: ' . trim( $title ),
				'',
				'本文:',
				'' === $body ? '(本文なし)' : $body,
			)
		);
	}
	private static function normalize( array $data ): array {
		$review = $data['is_product_review'] ?? false;
		if ( is_string( $review ) ) {
			$review = in_array( strtolower( trim( $review ) ), array( 'true', '1', 'yes', 'y', 'はい' ), true );
		}
		return array(
			'is_product_review' => (bool) $review,
			'brand'             => self::clean_text( $data['brand'] ?? '' ),
			'product_name'      => self::clean_text( $data['product_name'] ?? '' ),
			'model_number'      => self::clean_text( $data['model_number'] ?? '' ),
			'confidence'        => self::clean_confidence( $data['confidence'] ?? 0 ),
		);
	}
	private static function clean_text( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = trim( (string) $value );
		if ( in_array( strtolower( $text ), array( 'null', 'none', 'n/a', 'unknown', '-', '不明', 'なし' ), true ) ) {
			return '';
		}
		return $text;
	}
	private static function clean_confidence( $value ): float {
		if ( is_string( $value ) ) {
			$value = (float) str_replace( '%', '', trim( $value ) );
			if ( $value > 1.0 ) {
				$value /= 100.0;
			}
		}
		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}
		return max( 0.0, min( 1.0, (float) $value ) );
	}
}

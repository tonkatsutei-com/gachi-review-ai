<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
final class Jev_Questions {
	public const SET_V2 = 'v2';
	public const SET_V3 = 'v3';
	public const SET_V4 = 'v4';
	public const THRESHOLD_EXCLUDE = 0.35;
	public const THRESHOLD_EXCLUDE_EC = 0.20;
	public const THRESHOLD_ADOPT = 0.65;
	public const THRESHOLD_CRITICISM = 0.65;
	public const VERDICT_EXCLUDE = 'exclude';
	public const VERDICT_HOLD = 'hold';
	public const VERDICT_ADOPT = 'adopt';
	public const VERDICT_NONE = '';
	public const ID_PRODUCT_REVIEW = 'is_product_review';
	public const ID_AD_OR_PROMO = 'is_ad_or_promo';
	public const ID_SENTIMENT = 'product_sentiment';
	public const ID_CRITICISM = 'has_criticism';
	public const ID_DELIVERY_ISSUE = 'has_delivery_issue';
	public const SENTIMENT_LEVELS = array( '強く否定', 'やや否定', '中立（商品の評価に触れない）', 'やや肯定', '強く肯定' );
	public const REVIEW_RATIO_LEVELS = array(
		'0%（商品そのものの話なし）',
		'25%（商品の話が少し）',
		'50%（商品の話が半分）',
		'75%（商品の話が大半）',
		'100%（すべて商品そのものの話）',
	);
	private const VERDICT_LABELS = array(
		self::VERDICT_EXCLUDE => '除外',
		self::VERDICT_HOLD    => '保留',
		self::VERDICT_ADOPT   => '採用',
		self::VERDICT_NONE    => '判定なし',
	);
	public static function set_v3(): array {
		return array(
			self::ID_PRODUCT_REVIEW => array(
				'type'         => 'noul',
				'instructions' => 'state は、商品そのもの（品質・性能・使い勝手・満足度・作り）を実際に使って評価した「レビュー」を含むか。開封・アンボクシング（箱を開けて中身や外観を見せるだけ）・商品の紹介・購入報告だけで、商品そのものへの評価（良い・悪い、勧める・勧めないなどの判断）が無い場合は no。配送・梱包・ショップの対応・返品手続き・価格・他商品の話しか含まない場合も no。',
			),
			self::ID_AD_OR_PROMO    => array(
				'type'         => 'noul',
				'instructions' => 'state は商品の広告・宣伝・新商品のお知らせ・キャンペーンの告知か（第三者が自分の体験として商品の感想を述べたものではないか）。',
			),
			self::ID_SENTIMENT      => array(
				'type'         => 'score',
				'instructions' => 'state は商品そのものをどの程度肯定しているか。',
				'criteria'     => self::SENTIMENT_LEVELS,
			),
			self::ID_CRITICISM      => array(
				'type'         => 'noul',
				'instructions' => 'state は商品の欠点・不満・改善点の指摘を含むか。',
			),
			self::ID_DELIVERY_ISSUE => array(
				'type'         => 'noul',
				'instructions' => 'state は配送・梱包・ショップの対応・返品手続きへの不満や問題（遅延・破損・対応の悪さなど）を含むか。配送や梱包に軽く触れているだけ、褒めているだけの場合は no。',
			),
		);
	}
	public static function set_v4(): array {
		return array(
			self::ID_PRODUCT_REVIEW => array(
				'type'         => 'score',
				'instructions' => 'state のうち、商品そのもの（品質・性能・使い勝手・満足度・作り）への言及・評価が占める割合はどの程度か。配送・梱包・ショップの対応・返品手続き・価格・他商品の話は「商品そのもの」に含めない。',
				'criteria'     => self::REVIEW_RATIO_LEVELS,
			),
			self::ID_AD_OR_PROMO    => array(
				'type'         => 'noul',
				'instructions' => 'state は商品の広告・宣伝・新商品のお知らせ・キャンペーンの告知か（第三者が自分の体験として商品の感想を述べたものではないか）。',
			),
			self::ID_SENTIMENT      => array(
				'type'         => 'score',
				'instructions' => 'state は商品そのものをどの程度肯定しているか。',
				'criteria'     => self::SENTIMENT_LEVELS,
			),
			self::ID_CRITICISM      => array(
				'type'         => 'noul',
				'instructions' => 'state は商品の欠点・不満・改善点の指摘を含むか。',
			),
			self::ID_DELIVERY_ISSUE => array(
				'type'         => 'noul',
				'instructions' => 'state は配送・梱包・ショップの対応・返品手続きへの不満や問題（遅延・破損・対応の悪さなど）を含むか。配送や梱包に軽く触れているだけ、褒めているだけの場合は no。',
			),
		);
	}
	public static function verdict( array $answers ): string {
		return self::verdict_with( $answers, self::THRESHOLD_EXCLUDE, self::THRESHOLD_ADOPT );
	}
	public static function verdict_with( array $answers, float $exclude, float $adopt ): string {
		$product = self::ratio( $answers );
		$promo   = self::probability( $answers, self::ID_AD_OR_PROMO );
		if ( null === $product || null === $promo ) {
			return self::VERDICT_NONE;
		}
		if ( $product < $exclude || $promo > $adopt ) {
			return self::VERDICT_EXCLUDE;
		}
		if ( $product > $adopt && $promo < $exclude ) {
			return self::VERDICT_ADOPT;
		}
		return self::VERDICT_HOLD;
	}
	public static function verdict_for( array $answers, string $source ): string {
		$thresholds = self::thresholds_for( $source );
		return self::verdict_with( $answers, $thresholds['exclude'], $thresholds['adopt'] );
	}
	public static function thresholds_for( string $source ): array {
		return array(
			'exclude' => self::is_ec( $source ) ? self::THRESHOLD_EXCLUDE_EC : self::THRESHOLD_EXCLUDE,
			'adopt'   => self::THRESHOLD_ADOPT,
		);
	}
	private static function is_ec( string $source ): bool {
		return 'ec' === $source;
	}
	public static function verdict_label( string $verdict ): string {
		return self::VERDICT_LABELS[ $verdict ] ?? self::VERDICT_LABELS[ self::VERDICT_NONE ];
	}
	public static function verdict_of( ?string $json ): string {
		if ( null === $json || '' === trim( $json ) ) {
			return self::VERDICT_NONE;
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return self::VERDICT_NONE;
		}
		$verdict = $decoded['verdict'] ?? '';
		return is_string( $verdict ) ? $verdict : self::VERDICT_NONE;
	}
	public static function answers_of( ?string $json ): array {
		if ( null === $json || '' === trim( $json ) ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$answers = $decoded['answers'] ?? array();
		return is_array( $answers ) ? $answers : array();
	}
	public static function has_criticism( array $answers ): bool {
		$probability = self::probability( $answers, self::ID_CRITICISM );
		return null !== $probability && self::THRESHOLD_CRITICISM < $probability;
	}
	public static function is_excluded( string $verdict ): bool {
		return self::VERDICT_EXCLUDE === $verdict;
	}
	private static function probability( array $answers, string $id ): ?float {
		if ( ! isset( $answers[ $id ] ) || ! is_array( $answers[ $id ] ) ) {
			return null;
		}
		$value = $answers[ $id ]['noul'] ?? null;
		return is_numeric( $value ) ? (float) $value : null;
	}
	private static function ratio( array $answers ): ?float {
		if ( ! isset( $answers[ self::ID_PRODUCT_REVIEW ] ) || ! is_array( $answers[ self::ID_PRODUCT_REVIEW ] ) ) {
			return null;
		}
		$value = $answers[ self::ID_PRODUCT_REVIEW ]['score'] ?? null;
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$max = count( self::REVIEW_RATIO_LEVELS ) - 1;
		if ( $max <= 0 ) {
			return null;
		}
		return max( 0.0, min( 1.0, (float) $value / $max ) );
	}
}

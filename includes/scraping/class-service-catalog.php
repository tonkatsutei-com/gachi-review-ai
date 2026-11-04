<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Scraping;
final class Service_Catalog {
	public const ZENROWS          = 'zenrows';
	public const BRIGHT_DATA      = 'bright_data';
	public const APIFY            = 'apify';
	public const WEB_SCRAPING_API = 'webscrapingapi';
	public const SCRAPER_API      = 'scraperapi';
	public const CONTEXT_DEV      = 'contextdev';
	public const ENSEMBLE_DATA    = 'ensembledata';
	public const SCRAPE_DO        = 'scrape_do';
	public const AMZN = 'amzn';
	public const EC   = 'ec';
	public const SNS  = 'sns';
	private const USED_BY_LABELS = array(
		self::AMZN => 'AMZN',
		self::EC   => 'EC',
		self::SNS  => 'SNS',
	);
	private const USED_BY_DESCRIPTIONS = array(
		self::AMZN => 'Amazon公式API（Creators API）がまだ使えない場合に使う、当プラグインの機能（Free AMZN Creators API）で使用します。',
		self::EC   => '楽天市場, Amazon, Yahoo!ショッピング の公式サイトからレビューを取得する際に使用します。',
		self::SNS  => 'X, Instagram, Facebook からレビューを取得する際に使用します。',
	);
	public static function all(): array {
		return array(
			self::ZENROWS          => array(
				'label'    => 'ZenRows',
				'used_by'  => array( self::AMZN, self::EC, self::SNS ),
				'features' => '毎月 5,000 クレジット（クレジットカード登録不要）。汎用の HTML 取得。',
				'url'      => 'https://www.zenrows.com/',
			),
			self::BRIGHT_DATA      => array(
				'label'    => 'Bright Data',
				'used_by'  => array( self::AMZN, self::EC, self::SNS ),
				'features' => '毎月 5,000 レコード（クレジットカード登録不要）。Amazon は専用スクレイパー、楽天・Yahoo は Web Unlocker。',
				'url'      => 'https://brightdata.com/',
			),
			self::APIFY            => array(
				'label'    => 'Apify',
				'used_by'  => array( self::AMZN, self::EC, self::SNS ),
				'features' => '毎月 5 ドル分のプラットフォームクレジット（登録日ベースの請求サイクル）。Amazon・SNS の専用 Actor。',
				'url'      => 'https://apify.com/',
			),
			self::WEB_SCRAPING_API => array(
				'label'    => 'WebScrapingAPI',
				'used_by'  => array( self::AMZN, self::EC, self::SNS ),
				'features' => '毎月 5,000 リクエスト。汎用の HTML 取得。',
				'url'      => 'https://www.webscrapingapi.com/',
			),
			self::SCRAPER_API      => array(
				'label'    => 'ScraperAPI',
				'used_by'  => array( self::AMZN, self::EC ),
				'features' => '毎月 1,000 クレジット（初回 7 日間は 5,000）。Amazon は 1 リクエスト 5 クレジット。',
				'url'      => 'https://www.scraperapi.com/',
			),
			self::CONTEXT_DEV      => array(
				'label'    => 'Context.dev',
				'used_by'  => array( self::AMZN, self::EC ),
				'features' => '毎月 1,000 クレジット（クレジットカード登録不要）。汎用スクレイピング API。',
				'url'      => 'https://context.dev/',
			),
			self::ENSEMBLE_DATA    => array(
				'label'    => 'EnsembleData',
				'used_by'  => array( self::SNS ),
				'features' => '無料トライアルは毎日 50 units（毎日 00:00 UTC リセット・クレジットカード登録不要）。Instagram のハッシュタグ投稿に特化。',
				'url'      => 'https://ensembledata.com/',
			),
			self::SCRAPE_DO        => array(
				'label'    => 'Scrape.do',
				'used_by'  => array( self::SNS ),
				'features' => '毎月 1,000 successful credits（クレジットカード登録不要・失敗リクエストは非課金）。JS レンダリングは 1 リクエスト 5 クレジット。',
				'url'      => 'https://scrape.do/',
			),
		);
	}
	public static function ids(): array {
		return array_keys( self::all() );
	}
	public static function exists( string $id ): bool {
		return isset( self::all()[ $id ] );
	}
	public static function label( string $id ): string {
		$all = self::all();
		return $all[ $id ]['label'] ?? $id;
	}
	public static function used_by_labels( string $id ): array {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return array();
		}
		$labels = array();
		foreach ( $all[ $id ]['used_by'] as $target ) {
			$labels[] = self::USED_BY_LABELS[ $target ] ?? $target;
		}
		return $labels;
	}
	public static function used_by_tags( string $id ): array {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return array();
		}
		$tags = array();
		foreach ( $all[ $id ]['used_by'] as $target ) {
			$tags[] = array(
				'key'   => $target,
				'label' => self::USED_BY_LABELS[ $target ] ?? $target,
			);
		}
		return $tags;
	}
	public static function used_by_descriptions(): array {
		$tags = array();
		foreach ( self::USED_BY_LABELS as $key => $label ) {
			$tags[] = array(
				'key'         => $key,
				'label'       => $label,
				'description' => self::USED_BY_DESCRIPTIONS[ $key ] ?? '',
			);
		}
		return $tags;
	}
}

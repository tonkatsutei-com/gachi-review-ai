<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Ec;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Amazon_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Rakuten_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Yahoo_Client;
use Tonkatsutei\Gachi_Review_AI\Article\Reuse_Interval;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Llm\Product_Identifier;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Product_Selector {
	public const EC_AMAZON = 'amazon';
	public const EC_RAKUTEN = 'rakuten';
	public const EC_YAHOO = 'yahoo';
	private const SELECTION_DEFAULTS = array(
		self::EC_RAKUTEN => Rakuten_Client::SORT_DEFAULT,
		self::EC_YAHOO   => Yahoo_Client::SORT_DEFAULT,
		self::EC_AMAZON  => Amazon_Client::SORT_DEFAULT,
	);
	private const SELECTIONS = array(
		self::EC_RAKUTEN => Rakuten_Client::SORTS,
		self::EC_YAHOO   => Yahoo_Client::SORTS,
		self::EC_AMAZON  => Amazon_Client::SORTS,
	);
	private const STATUS_MATCHED = 'matched';
	private const MATCHED_STATUSES = array(
		Rakuten_Client::STATUS_MATCHED,
		Yahoo_Client::STATUS_MATCHED,
		Amazon_Client::STATUS_MATCHED,
	);
	private const ERROR_STATUSES = array(
		Rakuten_Client::STATUS_MISSING_CONFIG,
		Rakuten_Client::STATUS_API_ERROR,
		Yahoo_Client::STATUS_MISSING_CONFIG,
		Yahoo_Client::STATUS_API_ERROR,
		Amazon_Client::STATUS_MISSING_CONFIG,
		Amazon_Client::STATUS_API_ERROR,
	);
	public const STATUS_SELECTED = 'selected';
	public const STATUS_NO_HIT = 'no_hit';
	public const STATUS_API_ERROR = 'api_error';
	public const STATUS_UNSUPPORTED = 'unsupported_ec';
	private const GROUP = 'ec';
	private const HITS = 10;
	private const MAX_CANDIDATES = 100;
	private const PAGE_SIZE = array(
		self::EC_RAKUTEN => 30,
		self::EC_YAHOO   => 20,
		self::EC_AMAZON  => 10,
	);
	private const GENRE_GROW_BUDGET = 15;
	private const RAW_NAME_MAX = 60;
	private Settings $settings;
	private Rakuten_Client $rakuten;
	private Yahoo_Client $yahoo;
	private Amazon_Client $amazon;
	private ?Product_Ledger $ledger = null;
	private ?Site_Profile $profile = null;
	private ?Genre_Catalog $catalog = null;
	public function __construct( ?Settings $settings = null, ?Rakuten_Client $rakuten = null, ?Yahoo_Client $yahoo = null, ?Product_Ledger $ledger = null, ?Site_Profile $profile = null, ?Amazon_Client $amazon = null ) {
		$this->settings = $settings ?? new Settings();
		$this->rakuten  = $rakuten ?? new Rakuten_Client();
		$this->yahoo    = $yahoo ?? new Yahoo_Client();
		$this->amazon   = $amazon ?? new Amazon_Client();
		$this->ledger   = $ledger;
		$this->profile  = $profile;
	}
	private function ledger(): Product_Ledger {
		if ( null === $this->ledger ) {
			$this->ledger = new Product_Ledger();
		}
		return $this->ledger;
	}
	private function profile(): Site_Profile {
		if ( null === $this->profile ) {
			$this->profile = new Site_Profile();
		}
		return $this->profile;
	}
	public function ec(): string {
		$ec = self::normalize_ec( (string) ( $this->group()['ec'] ?? self::EC_RAKUTEN ) );
		return Status::capabilities()->can_use_ec( $ec ) ? $ec : self::EC_RAKUTEN;
	}
	public static function normalize_ec( string $ec ): string {
		$known = array( self::EC_AMAZON, self::EC_RAKUTEN, self::EC_YAHOO );
		return in_array( $ec, $known, true ) ? $ec : self::EC_RAKUTEN;
	}
	public static function selection_options( string $ec, ?Settings $settings = null ): array {
		$ec      = self::normalize_ec( $ec );
		$labels  = self::selection_labels( $ec, $settings );
		$slugs   = self::selection_slugs( $ec, $settings );
		$options = array();
		foreach ( $slugs as $slug ) {
			$options[ $slug ] = (string) ( $labels[ $slug ] ?? $slug );
		}
		return $options;
	}
	private static function selection_slugs( string $ec, ?Settings $settings = null ): array {
		$ec = self::normalize_ec( $ec );
		if ( self::EC_AMAZON === $ec && ! self::amazon_uses_sorts( $settings ) ) {
			return array( 'auto' );
		}
		return self::SELECTIONS[ $ec ] ?? array();
	}
	private static function amazon_uses_sorts( ?Settings $settings ): bool {
		$settings = $settings ?? new Settings();
		$amazon   = (array) $settings->get( self::EC_AMAZON, array() );
		return Amazon_Client::BACKEND_CREATORS === Amazon_Client::backend( $amazon );
	}
	public static function selection_default( string $ec, ?Settings $settings = null ): string {
		$ec = self::normalize_ec( $ec );
		if ( self::EC_AMAZON === $ec && ! self::amazon_uses_sorts( $settings ) ) {
			return 'auto';
		}
		return (string) ( self::SELECTION_DEFAULTS[ $ec ] ?? Rakuten_Client::SORT_DEFAULT );
	}
	public static function normalize_selection( string $ec, string $selection, ?Settings $settings = null ): string {
		$options = self::selection_options( $ec, $settings );
		return array_key_exists( $selection, $options ) ? $selection : self::selection_default( $ec, $settings );
	}
	public static function sort_slug( string $ec, string $selection, ?Settings $settings = null ): string {
		$ec = self::normalize_ec( $ec );
		if ( self::EC_AMAZON === $ec ) {
			return self::amazon_uses_sorts( $settings ) ? Amazon_Client::sort_slug( $selection ) : '';
		}
		return self::EC_YAHOO === $ec
			? Yahoo_Client::sort_slug( $selection )
			: Rakuten_Client::sort_slug( $selection );
	}
	public static function selection_description( string $ec, ?Settings $settings = null ): string {
		if ( self::EC_AMAZON === self::normalize_ec( $ec ) ) {
			if ( ! self::amazon_uses_sorts( $settings ) ) {
				return __( '「お任せ」のみです（キーワード検索の先頭を採用し、カテゴリーは使いません）。', GACHIREAI_TEXT_DOMAIN );
			}
			return __( 'Amazon の商品検索の並び（優先順）を選びます。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( self::EC_YAHOO === self::normalize_ec( $ec ) ) {
			return __( 'Yahoo!ショッピング の商品検索の並び（優先順）を選びます。', GACHIREAI_TEXT_DOMAIN );
		}
		return __( '楽天市場の商品検索の並び（優先順）を選びます。', GACHIREAI_TEXT_DOMAIN );
	}
	public static function ec_label( string $ec ): string {
		$labels = array(
			self::EC_RAKUTEN => __( '楽天市場', GACHIREAI_TEXT_DOMAIN ),
			self::EC_YAHOO   => __( 'Yahoo!ショッピング', GACHIREAI_TEXT_DOMAIN ),
			self::EC_AMAZON  => __( 'Amazon', GACHIREAI_TEXT_DOMAIN ),
		);
		return (string) ( $labels[ self::normalize_ec( $ec ) ] ?? '' );
	}
	public static function ec_short_label( string $ec ): string {
		$labels = array(
			self::EC_RAKUTEN => __( '楽天', GACHIREAI_TEXT_DOMAIN ),
			self::EC_YAHOO   => __( 'Yahoo!ショッピング', GACHIREAI_TEXT_DOMAIN ),
			self::EC_AMAZON  => __( 'Amazon', GACHIREAI_TEXT_DOMAIN ),
		);
		return (string) ( $labels[ self::normalize_ec( $ec ) ] ?? '' );
	}
	public const AVAILABILITY_READY = 'ready';
	public const AVAILABILITY_UNVERIFIED = 'unverified';
	public const AVAILABILITY_MISSING_CONFIG = 'missing_config';
	public const AVAILABILITY_MISSING_AFFILIATE = 'missing_affiliate';
	public const AVAILABILITY_UNSUPPORTED = 'unsupported';
	public const STATUS_UNVERIFIED = 'unverified_ec';
	public const REASON_NO_SCOPE = 'no_scope';
	public const REASON_NO_HIT = 'no_hit';
	public const REASON_NO_UNUSED = 'no_unused';
	public const REASON_NO_SETUP = 'no_setup';
	public static function availability_of( string $ec, ?Settings $settings = null ): string {
		$ec = self::normalize_ec( $ec );
		$settings = $settings ?? new Settings();
		if ( self::EC_AMAZON === $ec ) {
			$amazon = (array) $settings->get( self::EC_AMAZON, array() );
			if ( Amazon_Client::BACKEND_FREE_AMZN === Amazon_Client::backend( $amazon ) ) {
				return self::has_affiliate_credential( $ec, $settings )
					? self::AVAILABILITY_READY
					: self::AVAILABILITY_MISSING_AFFILIATE;
			}
			if (
				'' === trim( (string) ( $amazon[ Amazon_Client::CLIENT_ID ] ?? '' ) )
				|| '' === trim( (string) ( $amazon[ Amazon_Client::CLIENT_SECRET ] ?? '' ) )
			) {
				return self::AVAILABILITY_MISSING_CONFIG;
			}
			if ( ! self::has_affiliate_credential( $ec, $settings ) ) {
				return self::AVAILABILITY_MISSING_AFFILIATE;
			}
			return Connection_Status::is_verified( $ec, $settings )
				? self::AVAILABILITY_READY
				: self::AVAILABILITY_UNVERIFIED;
		}
		if ( ! self::has_credentials( $ec, $settings ) ) {
			return self::AVAILABILITY_MISSING_CONFIG;
		}
		if ( ! self::has_affiliate_credential( $ec, $settings ) ) {
			return self::AVAILABILITY_MISSING_AFFILIATE;
		}
		return Connection_Status::is_verified( $ec, $settings )
			? self::AVAILABILITY_READY
			: self::AVAILABILITY_UNVERIFIED;
	}
	private static function has_credentials( string $ec, Settings $settings ): bool {
		if ( self::EC_YAHOO === $ec ) {
			$yahoo = (array) $settings->get( 'yahoo', array() );
			return '' !== trim( (string) ( $yahoo['client_id'] ?? '' ) );
		}
		$rakuten = (array) $settings->get( 'rakuten', array() );
		return '' !== trim( (string) ( $rakuten['application_id'] ?? '' ) )
			&& '' !== trim( (string) ( $rakuten['access_key'] ?? '' ) );
	}
	private static function has_affiliate_credential( string $ec, Settings $settings ): bool {
		if ( self::EC_YAHOO === $ec ) {
			$yahoo = (array) $settings->get( 'yahoo', array() );
			return '' !== trim( (string) ( $yahoo['affiliate_code'] ?? '' ) );
		}
		if ( self::EC_AMAZON === $ec ) {
			$amazon = (array) $settings->get( self::EC_AMAZON, array() );
			return '' !== trim( (string) ( $amazon['partner_tag'] ?? '' ) );
		}
		$rakuten = (array) $settings->get( 'rakuten', array() );
		return '' !== trim( (string) ( $rakuten['affiliate_id'] ?? '' ) );
	}
	public function availability(): string {
		return self::availability_of( $this->ec(), $this->settings );
	}
	public function is_available(): bool {
		return self::AVAILABILITY_READY === $this->availability();
	}
	public function unavailable_message(): string {
		switch ( $this->availability() ) {
			case self::AVAILABILITY_UNSUPPORTED:
				return __( 'この EC をメインECにした商品選定には対応していません。「ASP設定」タブの設定を見直してください。', GACHIREAI_TEXT_DOMAIN );
			case self::AVAILABILITY_UNVERIFIED:
				return sprintf(
					__( '%s の接続テストに合格していません。「ASP設定」タブで「保存」（＝接続テスト）を実行してください。', GACHIREAI_TEXT_DOMAIN ),
					self::ec_label( $this->ec() )
				);
			case self::AVAILABILITY_MISSING_CONFIG:
				if ( self::EC_AMAZON === $this->ec() ) {
					return __( 'Amazon の Credential ID / Credential Secret が未設定です。「ASP設定」タブの Amazon で入力して「保存」を押してください。', GACHIREAI_TEXT_DOMAIN );
				}
				if ( self::EC_YAHOO === $this->ec() ) {
					return __( 'Yahoo!ショッピング の Client ID（アプリケーションID）が未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN );
				}
				return __( '楽天のアプリケーションID または アクセスキーが未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN );
			case self::AVAILABILITY_MISSING_AFFILIATE:
				if ( self::EC_AMAZON === $this->ec() ) {
					return __( 'Amazon のパートナータグが未設定です。記事の商品ボックスを作れないため、メインEC に選べません。「ASP設定」タブの Amazon で保存してください。', GACHIREAI_TEXT_DOMAIN );
				}
				if ( self::EC_YAHOO === $this->ec() ) {
					return __( 'Yahoo!ショッピング のアフィリエイトコードが未設定です。記事の商品ボックスを作れないため、メインEC に選べません。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN );
				}
				return __( '楽天のアフィリエイトIDが未設定です。記事の商品ボックスを作れないため、メインEC に選べません。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN );
		}
		return '';
	}
	private static function selection_labels( string $ec, ?Settings $settings = null ): array {
		if ( self::EC_AMAZON === self::normalize_ec( $ec ) ) {
			if ( ! self::amazon_uses_sorts( $settings ) ) {
				return array(
					'auto' => __( 'お任せ', GACHIREAI_TEXT_DOMAIN ),
				);
			}
			return array(
				Amazon_Client::SORT_RELEVANCE  => __( 'おすすめ順', GACHIREAI_TEXT_DOMAIN ),
				Amazon_Client::SORT_PRICE_ASC  => __( '価格の安い順', GACHIREAI_TEXT_DOMAIN ),
				Amazon_Client::SORT_PRICE_DESC => __( '価格の高い順', GACHIREAI_TEXT_DOMAIN ),
				Amazon_Client::SORT_RATING     => __( 'レビュー評価の高い順', GACHIREAI_TEXT_DOMAIN ),
				Amazon_Client::SORT_NEWEST     => __( '新着順', GACHIREAI_TEXT_DOMAIN ),
				Amazon_Client::SORT_FEATURED   => __( '注目順', GACHIREAI_TEXT_DOMAIN ),
			);
		}
		if ( self::EC_YAHOO === self::normalize_ec( $ec ) ) {
			return array(
				'-score'        => __( 'おすすめ順', GACHIREAI_TEXT_DOMAIN ),
				'+price'        => __( '価格の安い順', GACHIREAI_TEXT_DOMAIN ),
				'-price'        => __( '価格の高い順', GACHIREAI_TEXT_DOMAIN ),
				'-review_count' => __( '商品レビュー数の多い順', GACHIREAI_TEXT_DOMAIN ),
			);
		}
		return array(
			'standard'         => __( '標準', GACHIREAI_TEXT_DOMAIN ),
			'+itemPrice'       => __( '価格の安い順', GACHIREAI_TEXT_DOMAIN ),
			'-itemPrice'       => __( '価格の高い順', GACHIREAI_TEXT_DOMAIN ),
			'-reviewCount'     => __( 'レビュー件数の多い順', GACHIREAI_TEXT_DOMAIN ),
			'-reviewAverage'   => __( 'レビュー評価の高い順', GACHIREAI_TEXT_DOMAIN ),
			'-updateTimestamp' => __( '更新が新しい順', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	private function site_type(): string {
		$scope = $this->profile()->get_scope();
		return (string) ( $scope['site_type'] ?? Site_Profile::DEFAULT_SITE_TYPE );
	}
	public function select( bool $force = false ): array {
		$ec = $this->ec();
		if ( ! $this->is_available() ) {
			$status = self::AVAILABILITY_UNVERIFIED === $this->availability()
				? self::STATUS_UNVERIFIED
				: ( self::AVAILABILITY_UNSUPPORTED === $this->availability() ? self::STATUS_UNSUPPORTED : self::STATUS_MISSING_CONFIG );
			return $this->result( $status, $this->unavailable_message() );
		}
		$cached = $this->cached_candidate( $ec );
		if ( array() !== $cached ) {
			return $this->selected( $cached['product'], (string) $cached['query'], (string) $cached['genre_id'] );
		}
		$type = $this->site_type();
		if (
			self::EC_AMAZON === $ec
			&& ! self::amazon_uses_sorts( $this->settings )
			&& in_array( $type, array( 'general', 'category' ), true )
		) {
			return $this->result(
				self::STATUS_UNSUPPORTED,
				__( 'Amazon（Free AMZN Creators API）では「総合」「ジャンル指定」のサイトタイプで商品を選定できません（キーワード検索のみのため）。サイトのタイプを「ニッチ」にするか、「メインEC」を楽天市場／Yahoo!ショッピング／Amazon（Creators API）にしてください。', GACHIREAI_TEXT_DOMAIN ),
				self::REASON_NO_SETUP
			);
		}
		$selection = (string) ( $this->group()['selection'] ?? '' );
		$sort      = self::sort_slug( $ec, $selection, $this->settings );
		if ( 'niche' === $type ) {
			return $this->select_niche( $ec, $sort, $force );
		}
		return $this->select_by_genres( $ec, $type, $sort, $force );
	}
	private function select_by_genres( string $ec, string $type, string $sort, bool $force = false ): array {
		$genres = 'general' === $type ? $this->general_genres( $ec ) : $this->user_genres( $ec );
		if ( array() === $genres ) {
			if ( 'general' === $type ) {
				return $this->result(
					self::STATUS_NO_HIT,
					__( '総合タイプで検索に使えるジャンル（第二階層）がありません。ジャンル台帳を取得しています。時間をおいて、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ),
					self::REASON_NO_SCOPE
				);
			}
			return $this->result(
				self::STATUS_NO_HIT,
				__( 'サイトのタイプが「ジャンル指定」ですが、ジャンルが設定されていません。③サイト設定の「扱うジャンル」を設定してください。', GACHIREAI_TEXT_DOMAIN ),
				self::REASON_NO_SETUP
			);
		}
		$tried   = array();
		$last    = '';
		$repost  = array();
		$matched = false; 
		foreach ( $genres as $genre ) {
			$genre_id = (string) ( $genre['genre_id'] ?? '' );
			$name     = (string) ( $genre['name'] ?? $genre_id );
			$query   = ( self::EC_AMAZON === $ec || '' === $genre_id ) ? $name : '';
			$search  = $this->search( $ec, $query, $sort, $genre_id );
			$tried[] = $name;
			$status  = (string) ( $search['status'] ?? self::STATUS_API_ERROR );
			$last    = (string) ( $search['message'] ?? '' );
			if ( in_array( $status, self::ERROR_STATUSES, true ) ) {
				return $this->result( $status, (string) ( $search['message'] ?? '' ) );
			}
			if ( ! in_array( $status, self::MATCHED_STATUSES, true ) ) {
				if ( 'general' === $type ) {
					$this->catalog()->exclude( $ec, $genre_id );
				}
				continue;
			}
			$matched = true;
			$this->cache_items( $ec, (array) ( $search['items'] ?? array() ), '', $genre_id );
			$outcome = $this->adoptable( $ec, (array) ( $search['items'] ?? array() ), '', $genre_id, $repost, $force );
			if ( array() !== $outcome['product'] ) {
				return $this->selected( $outcome['product'], '', $genre_id );
			}
		}
		if ( array() !== $repost ) {
			return $this->selected( (array) $repost['product'], '', (string) ( $repost['genre_id'] ?? '' ) );
		}
		return $this->result(
			self::STATUS_NO_HIT,
			$matched
				? $this->no_unused_message()
				: $this->no_product_message( $ec, $tried, $last, __( 'ジャンル', GACHIREAI_TEXT_DOMAIN ) ),
			$matched ? self::REASON_NO_UNUSED : self::REASON_NO_HIT
		);
	}
	private function select_niche( string $ec, string $sort, bool $force = false ): array {
		$genres   = $this->user_genres( $ec );
		$keywords = $this->niche_keywords();
		if ( array() === $genres || array() === $keywords ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( 'サイトのタイプが「ニッチ」ですが、③サイト設定の「扱うジャンル」または「キーワード」が設定されていません。', GACHIREAI_TEXT_DOMAIN ),
				self::REASON_NO_SETUP
			);
		}
		$pairs = array();
		foreach ( $keywords as $keyword ) {
			foreach ( $genres as $genre ) {
				$pairs[] = array(
					'keyword'  => $keyword,
					'genre_id' => (string) ( $genre['genre_id'] ?? '' ),
				);
			}
		}
		shuffle( $pairs );
		$tried   = array();
		$last    = '';
		$repost  = array();
		$matched = false; 
		foreach ( $pairs as $pair ) {
			$keyword  = (string) $pair['keyword'];
			$genre_id = (string) $pair['genre_id'];
			if ( self::EC_AMAZON === $ec && ! self::amazon_uses_sorts( $this->settings ) ) {
				$genre_id = '';
			}
			$search  = $this->search( $ec, $keyword, $sort, $genre_id );
			$tried[] = $keyword;
			$status  = (string) ( $search['status'] ?? self::STATUS_API_ERROR );
			$last    = (string) ( $search['message'] ?? '' );
			if ( in_array( $status, self::ERROR_STATUSES, true ) ) {
				return $this->result( $status, (string) ( $search['message'] ?? '' ) );
			}
			if ( ! in_array( $status, self::MATCHED_STATUSES, true ) ) {
				continue;
			}
			$matched = true;
			$this->cache_items( $ec, (array) ( $search['items'] ?? array() ), $keyword, $genre_id );
			$outcome = $this->adoptable( $ec, (array) ( $search['items'] ?? array() ), $keyword, $genre_id, $repost, $force );
			if ( array() !== $outcome['product'] ) {
				return $this->selected( $outcome['product'], $keyword, $genre_id );
			}
		}
		if ( array() !== $repost ) {
			return $this->selected( (array) $repost['product'], (string) ( $repost['query'] ?? '' ), (string) ( $repost['genre_id'] ?? '' ) );
		}
		return $this->result(
			self::STATUS_NO_HIT,
			$matched
				? $this->no_unused_message()
				: $this->no_product_message( $ec, array_values( array_unique( $tried ) ), $last, __( 'キーワード', GACHIREAI_TEXT_DOMAIN ) ),
			$matched ? self::REASON_NO_UNUSED : self::REASON_NO_HIT
		);
	}
	private function search( string $ec, string $query, string $sort, string $genre_id = '' ): array {
		$page_size = self::page_size( $ec );
		$pages     = self::EC_AMAZON === $ec ? 1 : max( 1, (int) ceil( self::MAX_CANDIDATES / $page_size ) );
		$items   = array();
		$status  = self::STATUS_NO_HIT;
		$message = '';
		for ( $page = 1; $page <= $pages; $page++ ) {
			$result  = $this->search_page( $ec, $query, $sort, $genre_id, $page_size, $page );
			$status  = (string) ( $result['status'] ?? self::STATUS_API_ERROR );
			$message = (string) ( $result['message'] ?? '' );
			if ( in_array( $status, self::ERROR_STATUSES, true ) ) {
				return $result;
			}
			$page_items = (array) ( $result['items'] ?? array() );
			$items      = array_merge( $items, $page_items );
			if ( count( $page_items ) < $page_size ) {
				break;
			}
		}
		if ( array() === $items ) {
			return array(
				'status'  => $status,
				'items'   => array(),
				'message' => $message,
			);
		}
		return array(
			'status'  => self::STATUS_MATCHED,
			'items'   => $items,
			'message' => '',
		);
	}
	private function search_page( string $ec, string $query, string $sort, string $genre_id, int $hits, int $page ): array {
		try {
			if ( self::EC_AMAZON === $ec ) {
				return $this->amazon->search_products( (array) $this->settings->get( self::EC_AMAZON, array() ), $query, $sort, $hits, $genre_id );
			}
			if ( self::EC_YAHOO === $ec ) {
				return $this->yahoo->search_products( (array) $this->settings->get( 'yahoo', array() ), $query, $sort, $hits, $genre_id, $page );
			}
			return $this->rakuten->search_products( (array) $this->settings->get( 'rakuten', array() ), $query, $sort, $hits, $genre_id, $page );
		} catch ( \RuntimeException $e ) {
			Connection_Status::note_runtime_failure_from_message( $ec, $e->getMessage() );
			throw $e;
		}
	}
	private static function page_size( string $ec ): int {
		return (int) ( self::PAGE_SIZE[ self::normalize_ec( $ec ) ] ?? self::HITS );
	}
	private function cached_candidate( string $ec ): array {
		$row = $this->ledger()->next_postable( $ec );
		if ( array() === $row ) {
			return array();
		}
		$item = array(
			'item_name'     => (string) ( $row['product_name'] ?? '' ),
			'item_code'     => (string) ( $row['item_code'] ?? '' ),
			'item_price'    => (int) ( $row['item_price'] ?? 0 ),
			'item_url'      => (string) ( $row['item_url'] ?? '' ),
			'image_url'     => (string) ( $row['image_url'] ?? '' ),
			'item_jan_code' => (string) ( $row['jan_code'] ?? '' ),
		);
		$named = $this->clean_name( $item );
		if ( '' === (string) $named['product_name'] ) {
			return array();
		}
		return array(
			'product'  => $this->product( $named, $item ),
			'query'    => (string) ( $row['keyword'] ?? '' ),
			'genre_id' => (string) ( $row['genre_id'] ?? '' ),
		);
	}
	private function cache_items( string $ec, array $items, string $query, string $genre_id ): void {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$raw = trim( (string) ( $item['item_name'] ?? '' ) );
			if ( '' === $raw ) {
				continue;
			}
			$this->ledger()->register(
				$ec,
				array(
					'product_name' => mb_substr( $raw, 0, self::RAW_NAME_MAX ),
					'brand'        => '',
					'model_number' => '',
					'jan_code'     => trim( (string) ( $item['item_jan_code'] ?? '' ) ),
					'is_generic'   => false,
					'item'         => $item,
				),
				$query,
				$genre_id
			);
		}
	}
	private function adoptable( string $ec, array $items, string $query, string $genre_id, array &$repost, bool $force = false ): array {
		$usable = false;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( $force ) {
				if ( $this->ledger()->is_excluded( $this->existing( $ec, $item ) ) ) {
					continue;
				}
				$named = $this->clean_name( $item );
				if ( '' === (string) $named['product_name'] ) {
					continue;
				}
				return array(
					'product' => $this->product( $named, $item ),
					'usable'  => true,
				);
			}
			$row = $this->existing( $ec, $item );
			if ( $this->ledger()->is_excluded( $row ) ) {
				continue;
			}
			if ( array() !== $row && ! $this->ledger()->can_post( $row ) ) {
				continue;
			}
			if ( array() !== $row && '' !== (string) ( $row['last_posted_at'] ?? '' ) ) {
				$usable = true;
				if ( array() === $repost ) {
					$named = $this->clean_name( $item );
					if ( '' !== (string) $named['product_name'] ) {
						$repost = array(
							'product'  => $this->product( $named, $item ),
							'query'    => $query,
							'genre_id' => $genre_id,
						);
					}
				}
				continue;
			}
			$named = $this->clean_name( $item );
			if ( '' === (string) $named['product_name'] ) {
				continue;
			}
			$matched = $this->ledger()->find(
				(string) $named['jan_code'],
				(string) $named['model_number'],
				Product_Ledger::name_key( (string) $named['product_name'] )
			);
			if ( $this->ledger()->is_excluded( (array) ( $matched['row'] ?? array() ) ) ) {
				continue;
			}
			if ( array() !== (array) ( $matched['row'] ?? array() ) && ! $this->ledger()->can_post( (array) $matched['row'] ) ) {
				continue;
			}
			return array(
				'product' => $this->product( $named, $item ),
				'usable'  => true,
			);
		}
		return array(
			'product' => array(),
			'usable'  => $usable,
		);
	}
	private function existing( string $ec, array $item ): array {
		$code = trim( (string) ( $item['item_code'] ?? '' ) );
		if ( '' !== $code ) {
			$row = $this->ledger()->find_by_item_code( $ec, $code );
			if ( array() !== $row ) {
				return $row;
			}
		}
		$jan = trim( (string) ( $item['item_jan_code'] ?? '' ) );
		if ( '' === $jan ) {
			return array();
		}
		$matched = $this->ledger()->find( $jan );
		return (array) ( $matched['row'] ?? array() );
	}
	private function product( array $named, array $item ): array {
		return array(
			'product_name' => (string) ( $named['product_name'] ?? '' ),
			'brand'        => (string) ( $named['brand'] ?? '' ),
			'model_number' => (string) ( $named['model_number'] ?? '' ),
			'jan_code'     => (string) ( $named['jan_code'] ?? '' ),
			'is_generic'   => (bool) ( $named['is_generic'] ?? false ),
			'item'         => $item,
		);
	}
	private function selected( array $product, string $query, string $genre_id = '' ): array {
		return array(
			'status'   => self::STATUS_SELECTED,
			'product'  => $product,
			'query'    => $query,
			'genre_id' => $genre_id,
			'message'  => '',
			'reason'   => '',
		);
	}
	private function no_unused_message(): string {
		if ( Reuse_Interval::is_permanent() ) {
			return __( '記事を生成した商品のみとなったため選択できません（「再投稿しない（永久）」に設定されています）。', GACHIREAI_TEXT_DOMAIN );
		}
		return sprintf(
			__( '%d日以内に記事を生成した商品のみとなったため選択できません。', GACHIREAI_TEXT_DOMAIN ),
			Reuse_Interval::days()
		);
	}
	private function no_product_message( string $ec, array $tried, string $last, string $unit ): string {
		if ( Reuse_Interval::is_permanent() ) {
			return sprintf(
				__( '%1$s で記事にできる商品が見つかりませんでした（試した%2$s: %3$s／最後の応答: %4$s）。未投稿の商品が無く、同じ商品の再投稿も「再投稿しない（永久）」に設定されているためできません（新しい商品が出るまで待つか、③サイト設定の内容を見直してください）。', GACHIREAI_TEXT_DOMAIN ),
				self::ec_label( $ec ),
				$unit,
				array() === $tried ? '(なし)' : implode( ' / ', $tried ),
				'' === $last ? '(なし)' : $last
			);
		}
		return sprintf(
			__( '%1$s で記事にできる商品が見つかりませんでした（試した%2$s: %3$s／最後の応答: %4$s）。未投稿の商品が無く、%5$d 日以上前に投稿した商品も無いためです（新しい商品が出るまで待つか、③サイト設定の内容を見直してください）。', GACHIREAI_TEXT_DOMAIN ),
			self::ec_label( $ec ),
			$unit,
			array() === $tried ? '(なし)' : implode( ' / ', $tried ),
			'' === $last ? '(なし)' : $last,
			Reuse_Interval::days()
		);
	}
	private function general_genres( string $ec ): array {
		$genres = self::EC_AMAZON === $ec
			? $this->amazon_level_genres( $ec, 2 )
			: $this->catalog()->at_level( $ec, 2, self::GENRE_GROW_BUDGET );
		if ( array() === $genres ) {
			return array();
		}
		$recent = $this->ledger()->recent_genre_ids();
		$usable = array();
		foreach ( $genres as $genre ) {
			$genre_id = (string) ( $genre['genre_id'] ?? '' );
			if ( '' !== $genre_id && in_array( $genre_id, $recent, true ) ) {
				continue;
			}
			$usable[] = $genre;
		}
		shuffle( $usable );
		return $usable;
	}
	private function amazon_level_genres( string $ec, int $level ): array {
		$nodes    = array();
		$seen     = array();
		$frontier = array( '' ); 
		for ( $depth = 1; $depth <= $level && array() !== $frontier; $depth++ ) {
			$next = array();
			foreach ( $frontier as $parent_id ) {
				foreach ( $this->catalog()->children( $ec, $parent_id ) as $child ) {
					$child_id = (string) ( $child['genre_id'] ?? '' );
					if ( '' === $child_id ) {
						continue;
					}
					if ( $depth === $level ) {
						if ( isset( $seen[ $child_id ] ) ) {
							continue;
						}
						$seen[ $child_id ] = true;
						$nodes[]           = array(
							'genre_id' => $child_id,
							'name'     => (string) ( $child['name'] ?? '' ),
							'level'    => $depth,
						);
						continue;
					}
					$next[] = $child_id;
				}
			}
			$frontier = $next;
		}
		return $nodes;
	}
	private function user_genres( string $ec ): array {
		$scope   = $this->profile()->get_scope();
		$catalog = $this->catalog();
		$genres  = array();
		foreach ( Genre_Catalog::entries( (string) ( $scope['category'] ?? '' ) ) as $entry ) {
			$genre_id = (string) ( $entry['genre_id'] ?? '' );
			if ( '' !== $genre_id && array() === $catalog->find( $ec, $genre_id ) ) {
				$genre_id = '';
			}
			$genres[] = array(
				'genre_id' => $genre_id,
				'name'     => (string) ( $entry['name'] ?? '' ),
			);
		}
		return $genres;
	}
	private function niche_keywords(): array {
		$scope = $this->profile()->get_scope();
		return self::words( (string) ( $scope['products'] ?? '' ) );
	}
	private function catalog(): Genre_Catalog {
		if ( null === $this->catalog ) {
			$this->catalog = new Genre_Catalog();
		}
		return $this->catalog;
	}
	public static function words( string $value ): array {
		$words  = array();
		$buffer = '';
		$length = mb_strlen( $value );
		$quoted = false;
		for ( $i = 0; $i < $length; $i++ ) {
			$char = mb_substr( $value, $i, 1 );
			if ( '"' === $char ) {
				$quoted = ! $quoted;
				continue;
			}
			if ( ! $quoted && in_array( $char, array( ',', '，', '、', "\n", "\r" ), true ) ) {
				$text = trim( $buffer );
				if ( '' !== $text ) {
					$words[] = $text;
				}
				$buffer = '';
				continue;
			}
			$buffer .= $char;
		}
		$text = trim( $buffer );
		if ( '' !== $text ) {
			$words[] = $text;
		}
		return array_values( array_unique( $words ) );
	}
	public static function keyword_line( string $value ): string {
		$parts = array();
		foreach ( self::words( $value ) as $word ) {
			$parts[] = self::quote_word( $word );
		}
		return implode( ', ', $parts );
	}
	public static function keyword_block( string $value ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		if ( ! is_array( $lines ) ) {
			return '';
		}
		$normalized = array();
		foreach ( $lines as $line ) {
			$text = self::keyword_line( (string) $line );
			if ( '' !== $text ) {
				$normalized[] = $text;
			}
		}
		return implode( "\n", $normalized );
	}
	public static function quote_word( string $word ): string {
		$word = trim( $word );
		return 1 === preg_match( '/[,，、\s]/u', $word ) ? '"' . $word . '"' : $word;
	}
	private function group(): array {
		$value = $this->settings->get( self::GROUP, array() );
		return is_array( $value ) ? $value : array();
	}
	private function clean_name( array $item ): array {
		$raw = trim( (string) ( $item['item_name'] ?? '' ) );
		$jan = trim( (string) ( $item['item_jan_code'] ?? '' ) );
		$fallback = array(
			'product_name' => '' === $raw ? '' : mb_substr( $raw, 0, self::RAW_NAME_MAX ),
			'brand'        => '',
			'model_number' => '',
			'jan_code'     => $jan,
			'is_generic'   => false,
		);
		if ( '' === $raw ) {
			return $fallback;
		}
		try {
			$identifier = new Product_Identifier( $this->settings );
			if ( $identifier->is_available() ) {
				$extracted = $identifier->extract_product(
					$raw,
					(string) ( $item['item_caption'] ?? '' ),
					(string) ( $item['item_detail'] ?? '' )
				);
				$name = trim( (string) ( $extracted['product_name'] ?? '' ) );
				if ( '' !== $name ) {
					$jan_code = trim( (string) ( $extracted['jan_code'] ?? '' ) );
					return array(
						'product_name' => $name,
						'brand'        => trim( (string) ( $extracted['brand'] ?? '' ) ),
						'model_number' => trim( (string) ( $extracted['model_number'] ?? '' ) ),
						'jan_code'     => '' !== $jan_code ? $jan_code : $jan,
						'is_generic'   => (bool) ( $extracted['is_generic'] ?? false ),
					);
				}
			}
		} catch ( \Throwable $e ) {
		}
		return $fallback;
	}
	private function result( string $status, string $message, string $reason = '' ): array {
		return array(
			'status'   => $status,
			'product'  => array(),
			'query'    => '',
			'genre_id' => '',
			'message'  => trim( $message ),
			'reason'   => $reason,
		);
	}
}

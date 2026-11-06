<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Amazon_Client {
	public const BACKEND_FREE_AMZN = 'free_amzn_creators';
	public const BACKEND_CREATORS = 'creators_api';
	public const USE_FREE_AMZN_CREATORS = 'use_free_amzn_creators';
	public const USE_CREATORS = 'use_creators_api';
	public const CLIENT_ID = 'client_id';
	public const CLIENT_SECRET = 'client_secret';
	public const VERSION = 'version';
	public const STATUS_MATCHED = 'matched';
	public const STATUS_NO_HIT = 'no_hit';
	public const STATUS_API_ERROR = 'api_error';
	public const STATUS_MISSING_CONFIG = 'missing_config';
	public const STATUS_MISSING_AFFILIATE = 'missing_affiliate';
	public const STATUS_UNSUPPORTED = 'unsupported_ec';
	private const FREE_AMZN_CLASS = 'Free_Amzn_Creators_API';
	private const SEARCH_LIMIT = 10;
	public const SORT_RELEVANCE = 'Relevance';
	public const SORT_PRICE_ASC = 'Price:LowToHigh';
	public const SORT_PRICE_DESC = 'Price:HighToLow';
	public const SORT_RATING = 'AvgCustomerReviews';
	public const SORT_NEWEST = 'NewestArrivals';
	public const SORT_FEATURED = 'Featured';
	public const SORTS = array(
		self::SORT_RELEVANCE,
		self::SORT_PRICE_ASC,
		self::SORT_PRICE_DESC,
		self::SORT_RATING,
		self::SORT_NEWEST,
		self::SORT_FEATURED,
	);
	public const SORT_DEFAULT = self::SORT_RELEVANCE;
	private const MARKETPLACE = 'jp';
	private const DOMAIN = 'www.amazon.co.jp';
	private const TOKEN_ENDPOINT = 'https://api.amazon.co.jp/auth/o2/token';
	private const CREDENTIAL_VERSION = '3.3';
	private const SCOPE = 'creatorsapi::default';
	private const CREATORS_ENDPOINT = 'https://creatorsapi.amazon/catalog/v1/searchItems';
	public const TEST_KEYWORD = 'test';
	private const TIMEOUT = 30;
	private const MESSAGE_MAX = 300;
	private const TOKEN_TRANSIENT_PREFIX = 'gachireai_amazon_token_';
	private const TOKEN_TTL = 3000;
	private static float $last_request_at = 0.0;
	public static function free_amzn_available(): bool {
		return class_exists( self::FREE_AMZN_CLASS ) && method_exists( self::FREE_AMZN_CLASS, 'search' );
	}
	private static function free_amzn_has_usable_service(): bool {
		if ( ! self::free_amzn_available() ) {
			return false;
		}
		if ( method_exists( self::FREE_AMZN_CLASS, 'has_usable_service' ) ) {
			return (bool) call_user_func( array( self::FREE_AMZN_CLASS, 'has_usable_service' ) );
		}
		if (
			! method_exists( self::FREE_AMZN_CLASS, 'service_ids' )
			|| ! method_exists( self::FREE_AMZN_CLASS, 'is_usable' )
		) {
			return false;
		}
		foreach ( (array) call_user_func( array( self::FREE_AMZN_CLASS, 'service_ids' ) ) as $service ) {
			if ( (bool) call_user_func( array( self::FREE_AMZN_CLASS, 'is_usable' ), (string) $service ) ) {
				return true;
			}
		}
		return false;
	}
	public static function backend( array $settings ): string {
		if ( ! empty( $settings[ self::USE_FREE_AMZN_CREATORS ] ) && empty( $settings[ self::USE_CREATORS ] ) ) {
			return self::BACKEND_FREE_AMZN;
		}
		return self::BACKEND_CREATORS;
	}
	public static function is_usable( array $settings, ?Settings $settings_repo = null ): bool {
		if ( self::BACKEND_CREATORS === self::backend( $settings ) ) {
			$has_credentials = '' !== trim( (string) ( $settings[ self::CLIENT_ID ] ?? '' ) )
				&& '' !== trim( (string) ( $settings[ self::CLIENT_SECRET ] ?? '' ) );
			return $has_credentials
				&& '' !== trim( (string) ( $settings['partner_tag'] ?? '' ) )
				&& Connection_Status::is_verified( Product_Selector::EC_AMAZON, $settings_repo );
		}
		return self::free_amzn_available()
			&& '' !== trim( (string) ( $settings['partner_tag'] ?? '' ) );
	}
	public static function marketplace(): string {
		return self::MARKETPLACE;
	}
	public static function domain(): string {
		return self::DOMAIN;
	}
	public static function version_options(): array {
		return array(
			self::CREDENTIAL_VERSION => __( '3.3（極東＝日本・シンガポール・オーストラリア）', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function normalize_version(): string {
		return self::CREDENTIAL_VERSION;
	}
	public static function token_endpoint(): string {
		return self::TOKEN_ENDPOINT;
	}
	public static function sort_slug( string $selection ): string {
		return in_array( $selection, self::SORTS, true ) ? $selection : self::SORT_DEFAULT;
	}
	public function match( string $keyword, array $settings ): array {
		if ( self::BACKEND_CREATORS === self::backend( $settings ) ) {
			$result = $this->search_creators( $settings, $keyword, 1 );
			if ( self::STATUS_MATCHED !== $result['status'] ) {
				return $this->result( $result['status'], $result['message'] );
			}
			$item = (array) reset( $result['items'] );
			if ( '' === (string) ( $item['affiliate_url'] ?? '' ) ) {
				return $this->result(
					self::STATUS_NO_HIT,
					__( 'Amazon の商品を特定できましたが、アフィリエイトリンクを生成できませんでした。', GACHIREAI_TEXT_DOMAIN )
				);
			}
			return array(
				'status'  => self::STATUS_MATCHED,
				'item'    => $item,
				'message' => '',
			);
		}
		$partner_tag = trim( (string) ( $settings['partner_tag'] ?? '' ) );
		if ( '' === $partner_tag ) {
			return $this->result(
				self::STATUS_MISSING_AFFILIATE,
				__( 'Amazon のパートナータグ（アソシエイトタグ）が未設定です。「ASP設定」タブの Amazon で保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '検索キーワード（商品名）が空のため照合できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$raw = $this->fetch( $keyword, 1 );
		if ( array() === $raw ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( 'Amazon で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$item = $this->normalize_item( (array) reset( $raw ), $partner_tag );
		if ( '' === (string) ( $item['affiliate_url'] ?? '' ) ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( 'Amazon の商品を特定できましたが、アフィリエイトリンクを生成できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return array(
			'status'  => self::STATUS_MATCHED,
			'item'    => $item,
			'message' => '',
		);
	}
	public function search_products( array $settings, string $keyword, string $sort, int $hits = self::SEARCH_LIMIT, string $genre_id = '' ): array {
		if ( self::BACKEND_CREATORS === self::backend( $settings ) ) {
			return $this->search_creators( $settings, $keyword, $hits, $sort, $genre_id );
		}
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				__( '検索キーワードが空のため検索できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( ! self::free_amzn_has_usable_service() ) {
			return $this->search_result(
				self::STATUS_MISSING_CONFIG,
				__( 'Amazon の取得サービスが利用できません（API キーの保存と通信テストの成功が必要です）。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$raw = $this->fetch( $keyword, $hits );
		if ( array() === $raw ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				__( 'Amazon で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$partner_tag = trim( (string) ( $settings['partner_tag'] ?? '' ) );
		$items       = array();
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) ) {
				$items[] = $this->normalize_item( $entry, $partner_tag );
			}
		}
		if ( array() === $items ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				__( 'Amazon で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return array(
			'status'  => self::STATUS_MATCHED,
			'items'   => $items,
			'message' => '',
		);
	}
	public function search_url( string $keyword, array $settings ): string {
		$keyword = trim( $keyword );
		$tag     = trim( (string) ( $settings['partner_tag'] ?? '' ) );
		if ( '' === $keyword || '' === $tag ) {
			return '';
		}
		return 'https://' . self::domain() . '/s?k=' . rawurlencode( $keyword ) . '&tag=' . rawurlencode( $tag );
	}
	public function plain_search_url( string $keyword ): string {
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return '';
		}
		return 'https://' . self::domain() . '/s?k=' . rawurlencode( $keyword );
	}
	public function page_affiliate_url( string $page_url, array $settings ): string {
		$tag = trim( (string) ( $settings['partner_tag'] ?? '' ) );
		return self::affiliate_url( $page_url, $tag );
	}
	public function test( array $settings ): array {
		if ( self::BACKEND_CREATORS === self::backend( $settings ) ) {
			return $this->test_creators( $settings );
		}
		if ( ! self::free_amzn_available() ) {
			return array(
				'type'      => 'warning',
				'message'   => __( "Free Amzn Creators API が有効ではありません。\n姉妹プラグインを有効にするか、Amazon の使用APIを Creators API に切り替えてください。", GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		if ( ! self::free_amzn_has_usable_service() ) {
			return array(
				'type'      => 'warning',
				'message'   => __( "Amazon の取得サービスが利用できません（API キーの保存と通信テストの成功が必要です）。\nAmazon の取得サービスを設定してから、もう一度保存してください。", GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		if ( '' === trim( (string) ( $settings['partner_tag'] ?? '' ) ) ) {
			return array(
				'type'      => 'warning',
				'message'   => __( "パートナータグ（アソシエイトタグ）を入力してください。\nFree Amzn Creators API でも商品ボックスの作成に必要です。", GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		return array(
			'type'      => 'success',
			'message'   => __( 'Free AMZN Creators API は利用できます。', GACHIREAI_TEXT_DOMAIN ),
			'connected' => true,
		);
	}
	private function test_creators( array $settings ): array {
		$client_id     = trim( (string) ( $settings[ self::CLIENT_ID ] ?? '' ) );
		$client_secret = trim( (string) ( $settings[ self::CLIENT_SECRET ] ?? '' ) );
		$partner_tag   = trim( (string) ( $settings['partner_tag'] ?? '' ) );
		if ( '' === $client_id || '' === $client_secret ) {
			return array(
				'type'      => 'warning',
				'message'   => __( "Credential ID と Credential Secret を入力してください。\nCreators API の接続テストには両方が必要です。", GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		if ( '' === $partner_tag ) {
			return array(
				'type'      => 'warning',
				'message'   => __( "パートナータグ（アソシエイトタグ）を入力してください。\nCreators API の検索には必須です。", GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		$secrets = array( $client_id, $client_secret );
		$token = $this->fetch_token( $client_id, $client_secret, $secrets );
		if ( '' === $token['value'] ) {
			return array(
				'type'      => 'error',
				'message'   => sprintf(
					__( 'Creators API のアクセストークンを取得できませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
					$token['message']
				),
				'connected' => false,
			);
		}
		$search = $this->request_search_items( $settings, $token['value'], $secrets );
		if ( ! $search['ok'] ) {
			return array(
				'type'      => 'error',
				'message'   => $search['message'],
				'connected' => false,
			);
		}
		return array(
			'type'      => 'success',
			'message'   => __( 'Creators API に接続できました（アクセストークンの取得と商品検索を確認しました）。', GACHIREAI_TEXT_DOMAIN ),
			'connected' => true,
		);
	}
	private function fetch_token( string $client_id, string $client_secret, array $secrets ): array {
		$endpoint = self::token_endpoint();
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'scope'         => self::SCOPE,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'value'   => '',
				'message' => $this->mask( $response->get_error_message(), $secrets ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		$token = is_array( $data ) ? trim( (string) ( $data['access_token'] ?? '' ) ) : '';
		if ( $code < 200 || $code >= 300 || '' === $token ) {
			return array(
				'value'   => '',
				'message' => sprintf(
					__( 'トークン取得に失敗しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $secrets )
				),
			);
		}
		return array( 'value' => $token, 'message' => '' );
	}
	private function access_token( array $settings, array $secrets ): array {
		$client_id     = trim( (string) ( $settings[ self::CLIENT_ID ] ?? '' ) );
		$client_secret = trim( (string) ( $settings[ self::CLIENT_SECRET ] ?? '' ) );
		if ( '' === $client_id || '' === $client_secret ) {
			return array(
				'value'   => '',
				'message' => __( 'Credential ID と Credential Secret を入力してください（Creators API の検索には両方が必要です）。', GACHIREAI_TEXT_DOMAIN ),
			);
		}
		$cache_key = self::TOKEN_TRANSIENT_PREFIX . md5( $client_id );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return array( 'value' => $cached, 'message' => '' );
		}
		$token = $this->fetch_token( $client_id, $client_secret, $secrets );
		if ( '' !== $token['value'] ) {
			set_transient( $cache_key, $token['value'], self::TOKEN_TTL );
		}
		return $token;
	}
	private function search_creators( array $settings, string $keyword, int $limit, string $sort = '', string $browse_node_id = '' ): array {
		$client_id     = trim( (string) ( $settings[ self::CLIENT_ID ] ?? '' ) );
		$client_secret = trim( (string) ( $settings[ self::CLIENT_SECRET ] ?? '' ) );
		$partner_tag   = trim( (string) ( $settings['partner_tag'] ?? '' ) );
		if ( '' === $client_id || '' === $client_secret ) {
			return $this->search_result(
				self::STATUS_MISSING_CONFIG,
				__( 'Amazon の Credential ID / Credential Secret が未設定です。「ASP設定」タブの Amazon で保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( '' === $partner_tag ) {
			return $this->search_result(
				self::STATUS_MISSING_AFFILIATE,
				__( 'Amazon のパートナータグ（アソシエイトタグ）が未設定です。「ASP設定」タブの Amazon で保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				__( '検索キーワードが空のため検索できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$secrets = array( $client_id, $client_secret );
		$token   = $this->access_token( $settings, $secrets );
		if ( '' === $token['value'] ) {
			return $this->search_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Creators API のアクセストークンを取得できませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
					$token['message']
				)
			);
		}
		$marketplace = self::domain();
		$body        = array(
			'partnerTag'  => $partner_tag,
			'keywords'    => $keyword,
			'marketplace' => $marketplace,
			'itemCount'   => max( 1, min( self::SEARCH_LIMIT, $limit ) ),
			'resources'   => array(
				'itemInfo.title',
				'images.primary.large',
				'offersV2.listings.price',
			),
		);
		$sort_by = self::sort_slug( $sort );
		if ( self::SORT_DEFAULT !== $sort_by ) {
			$body['sortBy'] = $sort_by;
		}
		$browse_node_id = trim( $browse_node_id );
		if ( '' !== $browse_node_id ) {
			$body['browseNodeId'] = $browse_node_id;
		}
		$response = wp_remote_post(
			self::CREATORS_ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token['value'],
					'Content-Type'  => 'application/json',
					'x-marketplace' => $marketplace,
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->search_result(
				self::STATUS_API_ERROR,
				$this->mask( $response->get_error_message(), $secrets )
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return $this->search_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Creators API の商品検索に失敗しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $secrets )
				)
			);
		}
		if ( ! is_array( $data['searchResult'] ?? null ) ) {
			if ( self::is_no_results( $data, $body ) ) {
				return $this->search_result(
					self::STATUS_NO_HIT,
					__( 'Amazon で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
				);
			}
			return $this->search_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Creators API の商品検索に失敗しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $secrets )
				)
			);
		}
		$raw_items = $data['searchResult']['items'] ?? array();
		if ( ! is_array( $raw_items ) || array() === $raw_items ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				__( 'Amazon で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$items = array();
		foreach ( $raw_items as $entry ) {
			if ( is_array( $entry ) ) {
				$items[] = $this->normalize_creators_item( $entry, $partner_tag );
			}
		}
		if ( array() === $items ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				__( 'Amazon で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return array(
			'status'  => self::STATUS_MATCHED,
			'items'   => $items,
			'message' => '',
		);
	}
	private function normalize_creators_item( array $raw, string $partner_tag ): array {
		$asin = trim( (string) ( $raw['asin'] ?? '' ) );
		$url  = trim( (string) ( $raw['detailPageURL'] ?? '' ) );
		$title = isset( $raw['itemInfo']['title']['displayValue'] )
			? trim( (string) $raw['itemInfo']['title']['displayValue'] )
			: '';
		$price = 0;
		if ( isset( $raw['offersV2']['listings'][0]['price']['money']['amount'] ) ) {
			$price = (int) round( (float) $raw['offersV2']['listings'][0]['price']['money']['amount'] );
		} elseif ( isset( $raw['offersV2']['listings'][0]['price']['amount'] ) ) {
			$price = (int) round( (float) $raw['offersV2']['listings'][0]['price']['amount'] );
		} elseif ( isset( $raw['offers']['listings'][0]['price']['amount'] ) ) {
			$price = (int) round( (float) $raw['offers']['listings'][0]['price']['amount'] );
		}
		$price = max( 0, $price );
		$image = '';
		foreach ( array( 'large', 'medium', 'small' ) as $size ) {
			if ( isset( $raw['images']['primary'][ $size ]['url'] ) ) {
				$image = trim( (string) $raw['images']['primary'][ $size ]['url'] );
				break;
			}
		}
		if ( '' === $url && '' !== $asin ) {
			$url = 'https://' . self::domain() . '/dp/' . rawurlencode( $asin );
		}
		return array(
			'item_name'     => $title,
			'item_code'     => $asin,
			'item_price'    => $price,
			'item_url'      => self::plain_url( $url ),
			'affiliate_url' => self::affiliate_url( $url, $partner_tag ),
			'image_url'     => $image,
			'image_urls'    => '' !== $image ? array( $image ) : array(),
			'item_caption'  => '',
			'item_detail'   => '',
		);
	}
	private function request_search_items( array $settings, string $token, array $secrets ): array {
		$marketplace = self::domain();
		$response = wp_remote_post(
			self::CREATORS_ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'x-marketplace' => $marketplace,
				),
				'body'    => wp_json_encode(
					array(
						'partnerTag'  => trim( (string) ( $settings['partner_tag'] ?? '' ) ),
						'keywords'    => self::TEST_KEYWORD,
						'marketplace' => $marketplace,
						'resources'   => array( 'itemInfo.title' ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $this->mask( $response->get_error_message(), $secrets ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					__( 'Creators API の商品検索に失敗しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $secrets )
				),
			);
		}
		if ( ! is_array( $data['searchResult'] ?? null ) ) {
			if ( self::is_no_results( $data, $body ) ) {
				return array( 'ok' => true, 'message' => '' );
			}
			return array(
				'ok'      => false,
				'message' => sprintf(
					__( 'Creators API の商品検索に失敗しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $secrets )
				),
			);
		}
		return array( 'ok' => true, 'message' => '' );
	}
	private function detail( $data, string $body ): string {
		if ( is_array( $data ) ) {
			$error = $data['error'] ?? '';
			$desc  = $data['error_description'] ?? '';
			if ( is_scalar( $error ) && '' !== trim( (string) $error ) ) {
				$text = trim( (string) $error );
				return '' !== trim( (string) $desc ) ? $text . ': ' . trim( (string) $desc ) : $text;
			}
			$message = $data['message'] ?? '';
			if ( is_scalar( $message ) && '' !== trim( (string) $message ) ) {
				$text   = trim( (string) $message );
				$reason = $data['reason'] ?? '';
				return '' !== trim( (string) $reason ) ? $text . '（' . trim( (string) $reason ) . '）' : $text;
			}
			$errors = $data['errors'] ?? null;
			if ( is_array( $errors ) && array() !== $errors ) {
				$first = reset( $errors );
				if ( is_array( $first ) ) {
					$message = trim( (string) ( $first['message'] ?? '' ) );
					$code    = (string) ( $first['code'] ?? ( $first['Code'] ?? '' ) );
					if ( '' !== $message || '' !== $code ) {
						return '' !== $message ? $message : $code;
					}
				}
				if ( is_scalar( $first ) && '' !== trim( (string) $first ) ) {
					return trim( (string) $first );
				}
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $body ) ) );
		return '' !== $text ? $text : __( '応答本文が空でした。', GACHIREAI_TEXT_DOMAIN );
	}
	private static function is_no_results( $data, string $body ): bool {
		$message = is_array( $data )
			? (string) ( $data['message'] ?? '' ) . ' ' . (string) ( $data['reason'] ?? '' )
			: '';
		return 1 === preg_match( '/no results found|no results/i', $message . ' ' . mb_substr( $body, 0, 500 ) );
	}
	private function mask( string $message, array $secrets ): string {
		foreach ( $secrets as $secret ) {
			if ( '' !== $secret ) {
				$message = str_replace( $secret, '***', $message );
			}
		}
		return mb_substr( trim( $message ), 0, self::MESSAGE_MAX );
	}
	private function fetch( string $keyword, int $limit ): array {
		if ( ! self::free_amzn_available() ) {
			return array();
		}
		try {
			$items = \Free_Amzn_Creators_API::search( $keyword );
		} catch ( \Throwable $e ) {
			return array();
		}
		if ( ! is_array( $items ) ) {
			return array();
		}
		return array_slice( array_values( $items ), 0, max( 1, $limit ) );
	}
	private function normalize_item( array $raw, string $partner_tag ): array {
		$asin  = trim( (string) ( $raw['asin'] ?? '' ) );
		$title = trim( (string) ( $raw['title'] ?? '' ) );
		$url   = trim( (string) ( $raw['url'] ?? '' ) );
		$image = trim( (string) ( $raw['image'] ?? '' ) );
		$price = max( 0, (int) round( (float) ( $raw['price'] ?? 0 ) ) );
		if ( '' === $url && '' !== $asin ) {
			$url = 'https://' . self::domain() . '/dp/' . rawurlencode( $asin );
		}
		return array(
			'item_name'     => $title,
			'item_code'     => $asin,
			'item_price'    => $price,
			'item_url'      => self::plain_url( $url ),
			'affiliate_url' => self::affiliate_url( $url, $partner_tag ),
			'image_url'     => $image,
			'image_urls'    => '' !== $image ? array( $image ) : array(),
			'item_caption'  => '',
			'item_detail'   => '',
		);
	}
	public static function plain_url( string $url ): string {
		$url = trim( $url );
		$url = (string) preg_replace( '/(?<=[?&])tag=[^&]*(&|$)/', '', $url );
		return rtrim( $url, '?&' );
	}
	private static function affiliate_url( string $url, string $tag ): string {
		$url = trim( $url );
		$tag = trim( $tag );
		if ( '' === $url || '' === $tag ) {
			return '';
		}
		$url = self::plain_url( $url );
		if ( '' === $url ) {
			return '';
		}
		$separator = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $separator . 'tag=' . rawurlencode( $tag );
	}
	private function result( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'item'    => array(),
			'message' => trim( $message ),
		);
	}
	public function browse_nodes( array $settings, array $browse_node_ids, string $resource = 'children' ): array {
		$client_id     = trim( (string) ( $settings[ self::CLIENT_ID ] ?? '' ) );
		$client_secret = trim( (string) ( $settings[ self::CLIENT_SECRET ] ?? '' ) );
		$partner_tag   = trim( (string) ( $settings['partner_tag'] ?? '' ) );
		if ( '' === $client_id || '' === $client_secret || '' === $partner_tag ) {
			self::log_warning( __( 'Amazon GetBrowseNodes: 資格情報またはパートナータグが未設定です。', GACHIREAI_TEXT_DOMAIN ) );
			return array();
		}
		$ids = array();
		foreach ( $browse_node_ids as $id ) {
			$id = trim( (string) $id );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		if ( array() === $ids ) {
			return array();
		}
		$secrets = array( $client_id, $client_secret );
		$token   = $this->access_token( $settings, $secrets );
		if ( '' === $token['value'] ) {
			self::log_warning( __( 'Amazon GetBrowseNodes: アクセストークンを取得できませんでした。', GACHIREAI_TEXT_DOMAIN ) . ' ' . $token['message'] );
			return array();
		}
		$data = $this->creators_post(
			'https://creatorsapi.amazon/catalog/v1/getBrowseNodes',
			$token['value'],
			array(
				'partnerTag'    => $partner_tag,
				'browseNodeIds' => $ids,
				'marketplace'   => self::domain(),
				'resources'     => array( 'ancestor' === $resource ? 'browseNodes.ancestor' : 'browseNodes.children' ),
			)
		);
		if ( ! is_array( $data ) || ! is_array( $data['browseNodesResult']['browseNodes'] ?? null ) ) {
			return array();
		}
		return $data['browseNodesResult']['browseNodes'];
	}
	private function creators_post( string $url, string $token, array $payload ): ?array {
		$now  = microtime( true );
		$wait = 1.05 - ( $now - self::$last_request_at );
		if ( self::$last_request_at > 0.0 && $wait > 0.0 ) {
			usleep( (int) min( 999999, $wait * 1000000 ) );
		}
		self::$last_request_at = microtime( true );
		$retryable = array( 429, 500, 502, 503, 504 );
		$max       = 4;
		for ( $attempt = 1; $attempt <= $max; $attempt++ ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
						'x-marketplace' => self::domain(),
					),
					'body'    => (string) wp_json_encode( $payload ),
				)
			);
			if ( is_wp_error( $response ) ) {
				self::log_warning( __( 'Amazon Creators API: 通信に失敗しました。', GACHIREAI_TEXT_DOMAIN ) . ' ' . $response->get_error_message() );
				if ( $attempt < $max ) {
					sleep( $attempt );
					self::$last_request_at = microtime( true );
					continue;
				}
				return null;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, $retryable, true ) ) {
				if ( $attempt < $max ) {
					$retry_after = (string) wp_remote_retrieve_header( $response, 'retry-after' );
					$delay       = is_numeric( $retry_after ) ? max( 1, (int) $retry_after ) : $attempt;
					self::log_warning( sprintf( __( 'Amazon Creators API: 一時的な失敗（HTTP %d）。再試行します。', GACHIREAI_TEXT_DOMAIN ), $code ) );
					sleep( $delay );
					self::$last_request_at = microtime( true );
					continue;
				}
				self::log_warning( sprintf( __( 'Amazon Creators API: 失敗しました（HTTP %d）。', GACHIREAI_TEXT_DOMAIN ), $code ) );
				return null;
			}
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 !== $code || ! is_array( $decoded ) ) {
				self::log_warning( sprintf( __( 'Amazon Creators API: 失敗しました（HTTP %d）。', GACHIREAI_TEXT_DOMAIN ), $code ) );
				return null;
			}
			return $decoded;
		}
		return null;
	}
	private static function log_warning( string $message ): void {
		if ( class_exists( Event_Logger::class ) ) {
			Event_Logger::record( Event_Logger::TYPE_SYSTEM, Event_Logger::LEVEL_WARN, $message );
		}
	}
	private function search_result( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'items'   => array(),
			'message' => trim( $message ),
		);
	}
}

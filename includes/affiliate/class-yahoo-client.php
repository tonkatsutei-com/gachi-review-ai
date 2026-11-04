<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
final class Yahoo_Client {
	public const ENDPOINT = 'https://shopping.yahooapis.jp/ShoppingWebService/V3/itemSearch';
	public const CATEGORY_ENDPOINT = 'https://shopping.yahooapis.jp/ShoppingWebService/V1/json/categorySearch';
	public const LOOKUP_ENDPOINT = 'https://shopping.yahooapis.jp/ShoppingWebService/V1/json/itemLookup';
	public const CATEGORY_ROOT = '1';
	public const TEST_QUERY = 'test';
	public const TEST_RESULTS = 1;
	public const SORTS = array( '-score', '+price', '-price', '-review_count' );
	public const SORT_DEFAULT = '-score';
	private const DEFAULT_HITS = 10;
	public const STATUS_MATCHED = 'matched';
	public const STATUS_NO_HIT = 'no_hit';
	public const STATUS_API_ERROR = 'api_error';
	public const STATUS_MISSING_CONFIG = 'missing_config';
	public const STATUS_OK = 'ok';
	private const AFFILIATE_HOST = 'ck.jp.ap.valuecommerce.com';
	private const AFFILIATE_DESTINATION = '&vc_url=';
	private const SEARCH_PAGE = 'https://shopping.yahoo.co.jp/search?p=';
	private const TIMEOUT = 30;
	private const LOOKUP_TIMEOUT = 5;
	private const MIN_INTERVAL = 1.1;
	private const MESSAGE_MAX = 300;
	private static float $last_request_at = 0.0;
	public function test( array $settings, array $posted = array() ): array {
		$client_id = trim( (string) ( $settings['client_id'] ?? '' ) );
		if ( '' === $client_id ) {
			return array(
				'type'      => 'warning',
				'message'   => __( 'Client ID（アプリケーションID）を入力してください。', GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		$response = $this->request( $client_id, self::TEST_QUERY, self::TEST_RESULTS );
		if ( is_wp_error( $response ) ) {
			return array(
				'type'      => 'error',
				'message'   => sprintf(
					__( '接続テストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $client_id )
				),
				'connected' => false,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 200 !== $code || ! is_array( $data ) || ! is_array( $data['hits'] ?? null ) ) {
			return array(
				'type'      => 'error',
				'message'   => sprintf(
					__( '接続テストに失敗しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $client_id )
				),
				'connected' => false,
			);
		}
		$message     = __( '接続テストに成功しました（Yahoo!ショッピング 商品検索API への接続を確認しました）。', GACHIREAI_TEXT_DOMAIN );
		$affiliate   = $this->affiliate_id( $settings );
		$posted_code = trim( (string) ( $posted['affiliate_code'] ?? '' ) );
		if ( '' === $affiliate ) {
			if ( '' !== $posted_code ) {
				$reason = self::affiliate_reason( $posted_code );
				return array(
					'type'      => 'warning',
					'message'   => $message . ' ' . ( '' !== $reason
						? $reason
						: __( 'アフィリエイトコードから referral URL を抽出できませんでした。バリューコマースの「自由テキストリンク」のコードをそのまま貼り付けてください。', GACHIREAI_TEXT_DOMAIN ) ),
					'connected' => true,
				);
			}
			return array(
				'type'      => 'success',
				'message'   => $message,
				'connected' => true,
			);
		}
		return $this->test_affiliate( $client_id, $affiliate, $message );
	}
	public function affiliate_id( array $settings ): string {
		$value = trim( (string) ( $settings['affiliate_code'] ?? '' ) );
		if ( '' === $value ) {
			return '';
		}
		if ( str_contains( $value, '%3A%2F%2F' ) && str_contains( $value, 'vc_url%3D' ) ) {
			return $value;
		}
		$url = self::extract_affiliate_url( $value );
		if ( '' === $url ) {
			return '';
		}
		return rawurlencode( $url . self::AFFILIATE_DESTINATION );
	}
	public static function extract_affiliate_url( string $input ): string {
		$text = html_entity_decode( trim( $input ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === $text ) {
			return '';
		}
		foreach ( self::affiliate_candidates( $text ) as $candidate ) {
			$url = self::affiliate_in( $candidate );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return '';
	}
	public static function affiliate_reason( string $input ): string {
		$text = html_entity_decode( trim( $input ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === $text || '' !== self::extract_affiliate_url( $text ) ) {
			return '';
		}
		$flat    = (string) preg_replace( '/\s+/u', '', $text );
		$has_sid = (bool) preg_match( '/[?&]sid=/i', $flat );
		$has_pid = (bool) preg_match( '/[?&]pid=/i', $flat );
		$has_url = (bool) preg_match( '#https?://|//[a-z0-9.-]+\.[a-z]{2,}/#i', $flat );
		if ( ! $has_url ) {
			return __( '入力から URL が見つかりませんでした。バリューコマースの「自由テキストリンク」のコードをそのまま貼り付けてください。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( str_contains( $flat, 'gifbanner' ) && ! str_contains( $flat, '/servlet/referral' ) ) {
			return __( '入力の URL は計測用の画像タグ（gifbanner）のようです。広告作成画面で「自由テキストリンク」を選んで生成したコード（a タグ）をそのまま貼り付けてください。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( ! $has_sid || ! $has_pid ) {
			return sprintf(
				__( '入力の URL に %s が見つかりませんでした（広告作成画面で「自由テキストリンク」を選んで生成したコードか確認してください）。', GACHIREAI_TEXT_DOMAIN ),
				( ! $has_sid && ! $has_pid )
					? __( 'sid と pid', GACHIREAI_TEXT_DOMAIN )
					: ( $has_sid ? 'pid' : 'sid' )
			);
		}
		return __( '入力に URL と sid / pid はありましたが、referral URL として解釈できませんでした。', GACHIREAI_TEXT_DOMAIN );
	}
	private static function affiliate_candidates( string $text ): array {
		$candidates = array();
		if ( preg_match_all( '/["\']([^"\']{8,})["\']/u', $text, $matches ) ) {
			foreach ( $matches[1] as $value ) {
				$candidates[] = $value;
			}
		}
		$candidates[] = $text;
		return $candidates;
	}
	private static function affiliate_in( string $candidate ): string {
		$flat = (string) preg_replace( '/\s+/u', '', $candidate );
		if (
			preg_match( '#https?%3A%2F%2F[^\s"\'<>]+#i', $flat, $matches )
			&& str_contains( $matches[0], 'sid%3D' )
			&& str_contains( $matches[0], 'pid%3D' )
		) {
			return $matches[0];
		}
		$patterns = array(
			'#https?://[^\s"\'<>]*?/servlet/referral\?[^\s"\'<>]+#i',
			'#//ck\.jp\.ap\.valuecommerce\.com/servlet/referral\?[^\s"\'<>]+#i',
			'#https?://(?![^\s"\'<>]*gifbanner)[^\s"\'<>]+[?&](?:sid|pid)=[^&\s"\'<>]+&[^\s"\'<>]*(?:sid|pid)=[^\s"\'<>]+#i',
		);
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $flat, $matches ) ) {
				continue;
			}
			$url = $matches[0];
			if ( str_starts_with( $url, '//' ) ) {
				$url = 'http:' . $url;
			}
			$url = self::strip_destination( $url );
			if ( self::has_affiliate_keys( $url ) ) {
				return $url;
			}
		}
		return '';
	}
	public static function sort_slug( string $selection ): string {
		return in_array( $selection, self::SORTS, true ) ? $selection : self::SORT_DEFAULT;
	}
	public function search_products( array $settings, string $keyword, string $sort, int $hits = self::DEFAULT_HITS, string $genre_id = '', int $page = 1 ): array {
		$client_id = trim( (string) ( $settings['client_id'] ?? '' ) );
		if ( '' === $client_id ) {
			return $this->search_result(
				self::STATUS_MISSING_CONFIG,
				__( 'Yahoo!ショッピング の Client ID（アプリケーションID）が未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$keyword  = trim( $keyword );
		$genre_id = trim( $genre_id );
		if ( '' === $keyword && '' === $genre_id ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				__( '検索条件（ジャンル・キーワード）が空のため商品を選定できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$affiliate = $this->affiliate_id( $settings );
		$start     = max( 0, ( max( 1, $page ) - 1 ) * $hits );
		$response  = $this->request( $client_id, $keyword, $hits, $affiliate, $sort, $genre_id, $start );
		if ( is_wp_error( $response ) ) {
			return $this->search_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング 商品検索API への通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $client_id, $affiliate )
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 200 !== $code || ! is_array( $data ) || ! is_array( $data['hits'] ?? null ) ) {
			$hint = in_array( $code, array( 429, 500 ), true )
				? ' ' . __( '（利用が一時的に制限されています。少し待ってから、もう一度お試しください）', GACHIREAI_TEXT_DOMAIN )
				: '';
			return $this->search_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング 商品検索API がエラーを返しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $client_id, $affiliate )
				) . $hint
			);
		}
		$items = $this->items( $data );
		if ( array() === $items ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				sprintf(
					__( 'Yahoo!ショッピング で該当する商品が見つかりませんでした（HTTP 200 / 0 件。応答: %s）。', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( mb_substr( trim( $body ), 0, 120 ), $client_id, $affiliate )
				)
			);
		}
		return array(
			'status'  => self::STATUS_MATCHED,
			'items'   => $items,
			'message' => '',
		);
	}
	public function match( string $keyword, array $settings ): array {
		$client_id = trim( (string) ( $settings['client_id'] ?? '' ) );
		if ( '' === $client_id ) {
			return $this->result(
				self::STATUS_MISSING_CONFIG,
				__( 'Yahoo!ショッピング の Client ID（アプリケーションID）が未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '検索キーワード（商品名）が空のため照合できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$affiliate = $this->affiliate_id( $settings );
		$response  = $this->request( $client_id, $keyword, 1, $affiliate, self::SORT_DEFAULT );
		if ( is_wp_error( $response ) ) {
			return $this->result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング 商品検索API への通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $client_id, $affiliate )
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 200 !== $code || ! is_array( $data ) || ! is_array( $data['hits'] ?? null ) ) {
			$hint = in_array( $code, array( 429, 500 ), true )
				? ' ' . __( '（利用が一時的に制限されています。少し待ってから、もう一度お試しください）', GACHIREAI_TEXT_DOMAIN )
				: '';
			return $this->result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング 商品検索API がエラーを返しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $client_id, $affiliate )
				) . $hint
			);
		}
		$items = $this->items( $data );
		if ( array() === $items ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( 'Yahoo!ショッピング で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return $this->result( self::STATUS_MATCHED, '', (array) $items[0] );
	}
	public function lookup( string $item_code, array $settings ): array {
		$client_id = trim( (string) ( $settings['client_id'] ?? '' ) );
		$item_code = trim( $item_code );
		if ( '' === $client_id ) {
			return $this->result(
				self::STATUS_MISSING_CONFIG,
				__( 'Yahoo!ショッピング の Client ID（アプリケーションID）が未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( '' === $item_code ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '商品コードが空のため、価格を取得できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$this->throttle();
		$response = wp_remote_get(
			add_query_arg(
				array(
					'appid'         => $client_id,
					'itemcode'      => rawurlencode( $item_code ),
					'responsegroup' => 'small',
				),
				self::LOOKUP_ENDPOINT
			),
			array( 'timeout' => self::LOOKUP_TIMEOUT )
		);
		if ( is_wp_error( $response ) ) {
			return $this->result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング 商品コード検索API への通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $client_id )
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			$hint = in_array( $code, array( 429, 500 ), true )
				? ' ' . __( '（利用が一時的に制限されています。少し待ってから、もう一度お試しください）', GACHIREAI_TEXT_DOMAIN )
				: '';
			return $this->result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング 商品コード検索API がエラーを返しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $client_id )
				) . $hint
			);
		}
		$hit = $this->lookup_hit( $data );
		if ( array() === $hit ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( 'Yahoo!ショッピング で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return $this->result(
			self::STATUS_MATCHED,
			'',
			array(
				'item_name'     => trim( wp_strip_all_tags( (string) ( $hit['Name'] ?? '' ) ) ),
				'item_code'     => $item_code,
				'item_price'    => $this->lookup_price( $hit['Price'] ?? 0 ),
				'item_url'      => (string) ( $hit['Url'] ?? '' ),
				'affiliate_url' => (string) ( $hit['Url'] ?? '' ),
			)
		);
	}
	private function lookup_hit( array $data ): array {
		$result = $data['ResultSet']['Result'] ?? ( $data['ResultSet']['0']['Result'] ?? null );
		if ( ! is_array( $result ) ) {
			return array();
		}
		$hit = $result['Hit'] ?? ( $result['0']['Hit'] ?? null );
		if ( is_array( $hit ) && isset( $hit[0] ) && is_array( $hit[0] ) ) {
			$hit = $hit[0];
		}
		return is_array( $hit ) ? $hit : array();
	}
	private function lookup_price( $price ): int {
		if ( is_array( $price ) ) {
			$price = $price['#text'] ?? ( $price[0] ?? 0 );
		}
		if ( ! is_scalar( $price ) ) {
			return 0;
		}
		return max( 0, (int) $price );
	}
	public function search_url( string $keyword, array $settings ): string {
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return '';
		}
		$affiliate = $this->affiliate_id( $settings );
		if ( '' === $affiliate ) {
			return '';
		}
		$referral = (string) preg_replace(
			'/' . preg_quote( self::AFFILIATE_DESTINATION, '/' ) . '$/',
			'',
			urldecode( $affiliate )
		);
		if ( '' === $referral ) {
			return '';
		}
		$destination = self::SEARCH_PAGE . rawurlencode( $keyword );
		return $referral . self::AFFILIATE_DESTINATION . rawurlencode( $destination );
	}
	public function plain_search_url( string $keyword ): string {
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return '';
		}
		return self::SEARCH_PAGE . rawurlencode( $keyword );
	}
	public function page_affiliate_url( string $page_url, array $settings ): string {
		$page_url = trim( $page_url );
		if ( '' === $page_url ) {
			return '';
		}
		$affiliate = $this->affiliate_id( $settings );
		if ( '' === $affiliate ) {
			return '';
		}
		$referral = (string) preg_replace(
			'/' . preg_quote( self::AFFILIATE_DESTINATION, '/' ) . '$/',
			'',
			urldecode( $affiliate )
		);
		if ( '' === $referral ) {
			return '';
		}
		return $referral . self::AFFILIATE_DESTINATION . rawurlencode( $page_url );
	}
	public function categories( array $settings, string $category_id = self::CATEGORY_ROOT ): array {
		$client_id = trim( (string) ( $settings['client_id'] ?? '' ) );
		if ( '' === $client_id ) {
			return $this->category_result(
				self::STATUS_MISSING_CONFIG,
				__( 'Yahoo!ショッピング の Client ID（アプリケーションID）が未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$category_id = trim( $category_id );
		if ( '' === $category_id ) {
			$category_id = self::CATEGORY_ROOT;
		}
		$response = $this->category_request( $client_id, $category_id );
		if ( is_wp_error( $response ) ) {
			return $this->category_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング カテゴリID取得API への通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $client_id )
				)
			);
		}
		$code       = (int) wp_remote_retrieve_response_code( $response );
		$body       = (string) wp_remote_retrieve_body( $response );
		$data       = json_decode( $body, true );
		$categories = is_array( $data ) ? $this->category_tree( $data ) : array();
		if ( 200 !== $code ) {
			$hint = in_array( $code, array( 429, 500 ), true )
				? ' ' . __( '（利用が一時的に制限されています。少し待ってから、もう一度お試しください）', GACHIREAI_TEXT_DOMAIN )
				: '';
			return $this->category_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング カテゴリID取得API がエラーを返しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $client_id )
				) . $hint
			);
		}
		$current = $this->category_node( (array) ( $categories['Current'] ?? array() ) );
		if ( '' === (string) ( $current['genre_id'] ?? '' ) ) {
			return $this->category_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( 'Yahoo!ショッピング カテゴリID取得API の応答からカテゴリを読み取れませんでした（応答: %s）。', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( mb_substr( trim( $body ), 0, 120 ), $client_id )
				)
			);
		}
		return array(
			'status'   => self::STATUS_OK,
			'current'  => $current,
			'children' => $this->category_nodes( $categories['Children'] ?? array(), (int) $current['level'] + 1, (string) $current['genre_id'] ),
			'message'  => '',
		);
	}
	private function category_tree( array $data ): array {
		$result_set = $data['ResultSet'] ?? null;
		if ( ! is_array( $result_set ) ) {
			return array();
		}
		foreach ( $result_set as $entry ) {
			if ( is_array( $entry ) && is_array( $entry['Result']['Categories'] ?? null ) ) {
				return $entry['Result']['Categories'];
			}
		}
		if ( is_array( $result_set['Result']['Categories'] ?? null ) ) {
			return $result_set['Result']['Categories'];
		}
		return array();
	}
	private function items( array $data ): array {
		$items = array();
		foreach ( (array) ( $data['hits'] ?? array() ) as $hit ) {
			if ( is_array( $hit ) ) {
				$items[] = $this->normalize_item( $hit );
			}
		}
		return $items;
	}
	private function normalize_item( array $hit ): array {
		$url      = (string) ( $hit['url'] ?? '' );
		$images   = $this->image_urls( $hit );
		$jan_code = trim( (string) ( $hit['janCode'] ?? '' ) );
		return array(
			'item_name'     => trim( wp_strip_all_tags( (string) ( $hit['name'] ?? '' ) ) ),
			'item_code'     => (string) ( $hit['code'] ?? '' ),
			'item_price'    => (int) ( $hit['price'] ?? 0 ),
			'item_url'      => self::plain_url( $url ),
			'affiliate_url' => $url,
			'image_url'     => array() !== $images ? $images[0] : '',
			'image_urls'    => $images,
			'item_caption'  => trim( wp_strip_all_tags( (string) ( $hit['description'] ?? '' ) ) ),
			'item_detail'   => '' !== $jan_code
				? sprintf(
					__( 'JANコード: %s', GACHIREAI_TEXT_DOMAIN ),
					$jan_code
				)
				: '',
			'item_jan_code' => $jan_code,
		);
	}
	private function image_urls( array $hit ): array {
		$image = $hit['image'] ?? null;
		if ( ! is_array( $image ) ) {
			return array();
		}
		if ( isset( $image['medium'] ) || isset( $image['small'] ) ) {
			$url = trim( (string) ( $image['medium'] ?? ( $image['small'] ?? '' ) ) );
			return '' !== $url ? array( self::upscale_image( $url ) ) : array();
		}
		$urls = array();
		foreach ( $image as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url = trim( (string) ( $entry['medium'] ?? ( $entry['small'] ?? '' ) ) );
			if ( '' !== $url ) {
				$urls[] = self::upscale_image( $url );
			}
		}
		return array_values( array_unique( $urls ) );
	}
	private static function upscale_image( string $url ): string {
		return str_replace( array( '/i/g/', '/i/s/' ), '/i/l/', $url );
	}
	private function search_result( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'items'   => array(),
			'message' => $message,
		);
	}
	private function result( string $status, string $message, array $item = array() ): array {
		return array(
			'status'  => $status,
			'item'    => $item,
			'message' => $message,
		);
	}
	private function category_nodes( $list, int $fallback_level, string $parent_id ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		if ( isset( $list['Id'] ) ) {
			$list = array( $list );
		}
		$nodes = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$node = $this->category_node( $entry );
			if ( '' === (string) ( $node['genre_id'] ?? '' ) ) {
				continue;
			}
			$node['level']     = max( 1, $fallback_level );
			$node['parent_id'] = $parent_id;
			$nodes[]           = $node;
		}
		return $nodes;
	}
	private function category_node( array $raw ): array {
		$title = $raw['Title'] ?? array();
		$name  = is_array( $title )
			? ( $title['Short'] ?? ( $title['Medium'] ?? ( $title['Long'] ?? '' ) ) )
			: $title;
		$depth = $this->path_depth( $raw['Path'] ?? array() );
		$parent = (string) ( $raw['ParentId'] ?? '' );
		if ( '0' === $parent ) {
			$parent = '';
		}
		return array(
			'genre_id'  => (string) ( $raw['Id'] ?? '' ),
			'name'      => trim( wp_strip_all_tags( (string) $name ) ),
			'level'     => null !== $depth ? max( 0, $depth - 1 ) : 0,
			'parent_id' => $parent,
		);
	}
	private function path_depth( $path ): ?int {
		if ( ! is_array( $path ) ) {
			return null;
		}
		$depth = null;
		foreach ( $path as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$attrs = $entry['_attributes'] ?? array();
			$value = is_array( $attrs ) ? ( $attrs['depth'] ?? null ) : null;
			if ( ! is_numeric( $value ) ) {
				$value = $entry['depth'] ?? ( $entry['@depth'] ?? null );
			}
			if ( is_numeric( $value ) && ( null === $depth || (int) $value > $depth ) ) {
				$depth = (int) $value;
			}
		}
		return $depth;
	}
	private function category_result( string $status, string $message ): array {
		return array(
			'status'   => $status,
			'current'  => array(),
			'children' => array(),
			'message'  => $message,
		);
	}
	private function request( string $client_id, string $query, int $results, string $affiliate_id = '', string $sort = '', string $genre_id = '', int $start = 0 ) {
		$this->throttle();
		$genre_id = mb_substr( (string) preg_replace( '/[^0-9]/', '', $genre_id ), 0, 32 );
		$params = array(
			'appid'   => $client_id,
			'results' => (string) max( 1, min( 50, $results ) ),
		);
		if ( $start > 0 ) {
			$params['start'] = (string) $start;
		}
		if ( '' !== $query ) {
			$params['query'] = rawurlencode( $query );
		}
		if ( '' !== $genre_id ) {
			$params['genre_category_id'] = $genre_id;
		}
		if ( '' !== $sort ) {
			$params['sort'] = rawurlencode( $sort );
		}
		if ( '' !== $affiliate_id ) {
			$params['affiliate_type'] = 'vc';
			$params['affiliate_id']   = $affiliate_id;
		}
		return wp_remote_get(
			add_query_arg( $params, self::ENDPOINT ),
			array(
				'timeout' => self::TIMEOUT,
			)
		);
	}
	private function category_request( string $client_id, string $category_id ) {
		$this->throttle();
		return wp_remote_get(
			add_query_arg(
				array(
					'appid'       => $client_id,
					'category_id' => $category_id,
				),
				self::CATEGORY_ENDPOINT
			),
			array(
				'timeout' => self::TIMEOUT,
			)
		);
	}
	private function test_affiliate( string $client_id, string $affiliate_id, string $message ): array {
		$warning = __( '接続テストには成功しましたが、商品URLがアフィリエイトURLに置き換わりませんでした。バリューコマースで「Yahoo!ショッピング」との提携が承認されているか、アフィリエイトコードが最新かを確認してください。', GACHIREAI_TEXT_DOMAIN );
		$response = $this->request( $client_id, self::TEST_QUERY, self::TEST_RESULTS, $affiliate_id );
		if ( is_wp_error( $response ) ) {
			return array(
				'type'      => 'warning',
				'message'   => sprintf(
					__( '接続テストには成功しましたが、アフィリエイトの確認で通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $client_id )
				),
				'connected' => true,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 200 !== $code || ! is_array( $data ) || ! is_array( $data['hits'] ?? null ) ) {
			return array(
				'type'      => 'warning',
				'message'   => sprintf(
					__( '接続テストには成功しましたが、アフィリエイトの確認に失敗しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $this->detail( $data, $body ), $client_id )
				),
				'connected' => true,
			);
		}
		$url = $this->item_url( $data );
		if ( '' !== $url && str_contains( $url, self::AFFILIATE_HOST ) ) {
			return array(
				'type'      => 'success',
				'message'   => $message . ' ' . __( 'アフィリエイトコードを認識し、商品URLがアフィリエイトURLに置き換わることを確認しました。', GACHIREAI_TEXT_DOMAIN ),
				'connected' => true,
			);
		}
		return array(
			'type'      => 'warning',
			'message'   => $warning,
			'connected' => true,
		);
	}
	private function item_url( array $data ): string {
		$hits = $data['hits'] ?? array();
		if ( ! is_array( $hits ) || array() === $hits ) {
			return '';
		}
		$first = reset( $hits );
		return is_array( $first ) ? (string) ( $first['url'] ?? '' ) : '';
	}
	public static function plain_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || ! str_contains( $url, self::AFFILIATE_HOST ) ) {
			return $url;
		}
		if ( preg_match( '/[?&]vc_url=([^&]+)/i', $url, $m ) ) {
			$plain = rawurldecode( $m[1] );
			if ( '' !== $plain ) {
				return $plain;
			}
		}
		return $url;
	}
	private static function strip_destination( string $url ): string {
		return rtrim( (string) preg_replace( '/[?&]vc_url=[^&]*/', '', $url ), '?&' );
	}
	private static function has_affiliate_keys( string $url ): bool {
		return (bool) preg_match( '/[?&]sid=[^&\s]+/', $url ) && (bool) preg_match( '/[?&]pid=[^&\s]+/', $url );
	}
	private function throttle(): void {
		$now  = microtime( true );
		$wait = self::MIN_INTERVAL - ( $now - self::$last_request_at );
		if ( self::$last_request_at > 0.0 && $wait > 0 ) {
			usleep( (int) ceil( $wait * 1000000 ) );
		}
		self::$last_request_at = microtime( true );
	}
	private function detail( $data, string $body ): string {
		if ( is_array( $data ) && isset( $data['Error'] ) ) {
			$error = $data['Error'];
			if ( is_array( $error ) && is_scalar( $error['Message'] ?? null ) ) {
				return (string) $error['Message'];
			}
			if ( is_scalar( $error ) ) {
				return (string) $error;
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $body ) ) );
		return '' !== $text ? $text : __( '応答本文が空でした。', GACHIREAI_TEXT_DOMAIN );
	}
	private function mask( string $message, string ...$secrets ): string {
		foreach ( $secrets as $secret ) {
			if ( '' !== $secret ) {
				$message = str_replace( $secret, '***', $message );
			}
		}
		return mb_substr( trim( $message ), 0, self::MESSAGE_MAX );
	}
}

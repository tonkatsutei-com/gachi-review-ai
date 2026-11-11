<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Api_Error;
final class Rakuten_Client {
	public const ENDPOINT = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260701';
	public const GENRE_ENDPOINT = 'https://openapi.rakuten.co.jp/ichibagt/api/IchibaGenre/Search/20260701';
	public const GENRE_ROOT = '0';
	public const TEST_KEYWORD = 'test';
	public const TEST_ELEMENTS = 'itemName';
	public const ELEMENTS = 'itemName,itemCode,itemPrice,itemUrl,affiliateUrl,mediumImageUrls';
	public const SELECT_ELEMENTS = 'itemName,itemCode,itemPrice,itemUrl,affiliateUrl,mediumImageUrls,itemCaption,itemDetailTable';
	public const SORTS = array( 'standard', '+itemPrice', '-itemPrice', '-reviewCount', '-reviewAverage', '-updateTimestamp' );
	public const SORT_DEFAULT = 'standard';
	private const TIMEOUT = 30;
	private const LOOKUP_TIMEOUT = 5;
	private const MIN_INTERVAL = 1.1;
	private const MAX_RETRY_AFTER = 5;
	private static float $last_request_at = 0.0;
	private const MESSAGE_MAX = 300;
	private const IMAGE_SIZE = '640x640';
	private const SEARCH_PAGE = 'https://search.rakuten.co.jp/search/mall/';
	private const AFFILIATE_GATEWAY = 'https://hb.afl.rakuten.co.jp/hgc/';
	public const STATUS_MATCHED = 'matched';
	public const STATUS_NO_HIT = 'no_hit';
	public const STATUS_API_ERROR = 'api_error';
	public const STATUS_MISSING_AFFILIATE = 'missing_affiliate_id';
	public const STATUS_MISSING_CONFIG = 'missing_config';
	public const STATUS_OK = 'ok';
	public function match( string $keyword, array $settings ): array {
		$app_id     = trim( (string) ( $settings['application_id'] ?? '' ) );
		$access_key = trim( (string) ( $settings['access_key'] ?? '' ) );
		$affiliate  = trim( (string) ( $settings['affiliate_id'] ?? '' ) );
		if ( '' === $app_id || '' === $access_key ) {
			return $this->result(
				self::STATUS_MISSING_CONFIG,
				__( '楽天のアプリケーションID または アクセスキーが未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( '' === $affiliate ) {
			return $this->result(
				self::STATUS_MISSING_AFFILIATE,
				__( '楽天のアフィリエイトIDが未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '検索キーワード（商品名）が空のため照合できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$last = $this->result(
			self::STATUS_NO_HIT,
			__( '楽天市場で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
		);
		foreach ( self::keyword_variants( $keyword ) as $candidate ) {
			$attempt = $this->match_once( $candidate, $settings, $access_key );
			$status  = (string) ( $attempt['status'] ?? '' );
			if ( self::STATUS_MATCHED === $status ) {
				return $attempt;
			}
			if ( self::STATUS_NO_HIT === $status ) {
				$last = $attempt;
				continue;
			}
			return $attempt;
		}
		return $last;
	}
	public function lookup( string $item_code, array $settings ): array {
		$app_id     = trim( (string) ( $settings['application_id'] ?? '' ) );
		$access_key = trim( (string) ( $settings['access_key'] ?? '' ) );
		$item_code  = trim( $item_code );
		if ( '' === $app_id || '' === $access_key ) {
			return $this->result(
				self::STATUS_MISSING_CONFIG,
				__( '楽天のアプリケーションID または アクセスキーが未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( '' === $item_code ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '商品コードが空のため、価格を取得できませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$response = $this->lookup_request( $item_code, $settings );
		if ( is_wp_error( $response ) ) {
			return $this->result(
				self::STATUS_API_ERROR,
				sprintf(
					__( '楽天APIへの通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $access_key )
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 404 === $code || ( is_array( $data ) && 'not_found' === (string) ( $data['error'] ?? '' ) ) ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '楽天市場で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( 200 !== $code || ( is_array( $data ) && isset( $data['error'] ) ) ) {
			$detail = is_array( $data ) && isset( $data['error_description'] )
				? (string) $data['error_description']
				: $body;
			$hint = 429 === $code
				? ' ' . __( '（リクエストが集中しています。少し待ってから、もう一度お試しください）', GACHIREAI_TEXT_DOMAIN )
				: '';
			return $this->result(
				self::STATUS_API_ERROR,
				sprintf(
					__( '楽天APIがエラーを返しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $detail, $access_key )
				) . $hint
			);
		}
		$item = $this->first_item( is_array( $data ) ? $data : array() );
		if ( array() === $item ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '楽天市場で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return $this->result( self::STATUS_MATCHED, '', $item );
	}
	private function lookup_request( string $item_code, array $settings ) {
		$this->throttle();
		$query = array(
			'applicationId' => (string) ( $settings['application_id'] ?? '' ),
			'format'        => 'json',
			'itemCode'      => rawurlencode( $item_code ),
			'elements'      => self::ELEMENTS,
		);
		$source = $this->source( (string) ( $settings['site_url'] ?? '' ) );
		return wp_remote_get(
			add_query_arg( $query, self::ENDPOINT ),
			array(
				'timeout' => self::LOOKUP_TIMEOUT,
				'headers' => array(
					'accessKey' => (string) ( $settings['access_key'] ?? '' ),
					'Origin'    => $source,
					'Referer'   => $source . '/',
				),
			)
		);
	}
	private function match_once( string $keyword, array $settings, string $access_key ): array {
		$response = $this->request( $keyword, $settings );
		if ( is_wp_error( $response ) ) {
			return $this->result(
				self::STATUS_API_ERROR,
				sprintf(
					__( '楽天APIへの通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $access_key )
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( self::is_invalid_keyword( is_array( $data ) ? $data : array(), $code ) ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '楽天市場で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( 404 === $code || ( is_array( $data ) && 'not_found' === (string) ( $data['error'] ?? '' ) ) ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '楽天市場で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		if ( 200 !== $code || ( is_array( $data ) && isset( $data['error'] ) ) ) {
			$detail = is_array( $data ) && isset( $data['error_description'] )
				? (string) $data['error_description']
				: $body;
			return $this->result(
				self::STATUS_API_ERROR,
				sprintf(
					__( '楽天APIがエラーを返しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $detail, $access_key )
				)
			);
		}
		$item = $this->first_item( is_array( $data ) ? $data : array() );
		if ( array() === $item ) {
			return $this->result(
				self::STATUS_NO_HIT,
				__( '楽天市場で該当する商品が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		return $this->result( self::STATUS_MATCHED, '', $item );
	}
	private static function keyword_variants( string $keyword ): array {
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return array();
		}
		$words = preg_split( '/[\s\x{00A0}\x{3000}]+/u', $keyword );
		$words = is_array( $words )
			? array_values( array_filter( $words, static fn( $w ): bool => '' !== trim( (string) $w ) ) )
			: array();
		$count = count( $words );
		if ( $count <= 1 ) {
			return array( $keyword );
		}
		$variants = array();
		for ( $n = $count; $n >= 1; --$n ) {
			$variants[] = implode( ' ', array_slice( $words, 0, $n ) );
		}
		return array_values( array_unique( $variants ) );
	}
	private static function is_invalid_keyword( array $data, int $code ): bool {
		if ( 400 !== $code || 'wrong_parameter' !== (string) ( $data['error'] ?? '' ) ) {
			return false;
		}
		return str_contains( strtolower( (string) ( $data['error_description'] ?? '' ) ), 'keyword' );
	}
	public function test( array $settings ): array {
		$app_id     = (string) ( $settings['application_id'] ?? '' );
		$access_key = (string) ( $settings['access_key'] ?? '' );
		if ( '' === $app_id || '' === $access_key ) {
			return array(
				'type'      => 'warning',
				'message'   => __( 'アプリケーションID と アクセスキー を入力してください。', GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		$response = $this->request( self::TEST_KEYWORD, $settings, self::TEST_ELEMENTS );
		if ( is_wp_error( $response ) ) {
			return array(
				'type'      => 'error',
				'message'   => sprintf(
					__( '接続テストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $access_key )
				),
				'connected' => false,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 200 !== $code || ( is_array( $data ) && isset( $data['error'] ) ) ) {
			$detail = is_array( $data ) && isset( $data['error_description'] )
				? (string) $data['error_description']
				: $body;
			return array(
				'type'      => 'error',
				'message'   => sprintf(
					__( '接続テストに失敗しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $detail, $access_key )
				),
				'connected' => false,
			);
		}
		return array(
			'type'      => 'success',
			'message'   => __( '接続テストに成功しました（楽天ウェブサービスへの接続を確認しました）。', GACHIREAI_TEXT_DOMAIN ),
			'connected' => true,
		);
	}
	private const DEFAULT_HITS = 10;
	public static function sort_slug( string $selection ): string {
		return in_array( $selection, self::SORTS, true ) ? $selection : self::SORT_DEFAULT;
	}
	public function search_products( array $settings, string $keyword, string $sort, int $hits = self::DEFAULT_HITS, string $genre_id = '', int $page = 1 ): array {
		$app_id     = trim( (string) ( $settings['application_id'] ?? '' ) );
		$access_key = trim( (string) ( $settings['access_key'] ?? '' ) );
		if ( '' === $app_id || '' === $access_key ) {
			return $this->search_result(
				self::STATUS_MISSING_CONFIG,
				__( '楽天のアプリケーションID または アクセスキーが未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
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
		$response = $this->search_request( $settings, $keyword, $sort, $hits, $genre_id, $page );
		if ( is_wp_error( $response ) ) {
			return $this->search_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( '楽天APIへの通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $access_key )
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 429 === $code ) {
			usleep( $this->retry_after( $response, $data ) * 1000000 );
			$response = $this->search_request( $settings, $keyword, $sort, $hits, $genre_id, $page );
			if ( is_wp_error( $response ) ) {
				return $this->search_result(
					self::STATUS_API_ERROR,
					sprintf(
						__( '楽天APIへの通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
						$this->mask( $response->get_error_message(), $access_key )
					)
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
		}
		if ( 404 === $code || ( is_array( $data ) && 'not_found' === (string) ( $data['error'] ?? '' ) ) ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				Api_Error::append(
					sprintf(
						__( '楽天市場で該当する商品が見つかりませんでした（HTTP %d / not_found）。', GACHIREAI_TEXT_DOMAIN ),
						$code
					),
					$body
				)
			);
		}
		if ( self::is_invalid_keyword( is_array( $data ) ? $data : array(), $code ) ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				sprintf(
					__( '検索語「%s」は楽天の検索条件を満たさないため、この語を飛ばしました。', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $keyword, $access_key )
				)
			);
		}
		if ( 200 !== $code || ( is_array( $data ) && isset( $data['error'] ) ) ) {
			$detail = is_array( $data ) && isset( $data['error_description'] )
				? (string) $data['error_description']
				: $body;
			$hint = 429 === $code
				? ' ' . __( '（リクエストが集中しています。少し待ってから、もう一度お試しください）', GACHIREAI_TEXT_DOMAIN )
				: '';
			return $this->search_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( '楽天APIがエラーを返しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $detail, $access_key )
				) . $hint
			);
		}
		$items = $this->items( is_array( $data ) ? $data : array() );
		if ( array() === $items ) {
			return $this->search_result(
				self::STATUS_NO_HIT,
				sprintf(
					__( '楽天市場で該当する商品が見つかりませんでした（HTTP 200 / 0 件。応答: %s）。', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( mb_substr( trim( $body ), 0, 120 ), $access_key )
				)
			);
		}
		return array(
			'status'  => self::STATUS_MATCHED,
			'items'   => $items,
			'message' => '',
		);
	}
	public function genres( array $settings, string $genre_id = self::GENRE_ROOT ): array {
		$app_id     = trim( (string) ( $settings['application_id'] ?? '' ) );
		$access_key = trim( (string) ( $settings['access_key'] ?? '' ) );
		if ( '' === $app_id || '' === $access_key ) {
			return $this->genre_result(
				self::STATUS_MISSING_CONFIG,
				__( '楽天のアプリケーションID または アクセスキーが未設定です。「ASP設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$genre_id = trim( $genre_id );
		if ( '' === $genre_id ) {
			$genre_id = self::GENRE_ROOT;
		}
		$response = $this->genre_request( $settings, $genre_id );
		if ( is_wp_error( $response ) ) {
			return $this->genre_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( '楽天APIへの通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$this->mask( $response->get_error_message(), $access_key )
				)
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( 429 === $code ) {
			usleep( $this->retry_after( $response, $data ) * 1000000 );
			$response = $this->genre_request( $settings, $genre_id );
			if ( is_wp_error( $response ) ) {
				return $this->genre_result(
					self::STATUS_API_ERROR,
					sprintf(
						__( '楽天APIへの通信に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
						$this->mask( $response->get_error_message(), $access_key )
					)
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
		}
		if ( 404 === $code || ( is_array( $data ) && 'not_found' === (string) ( $data['error'] ?? '' ) ) ) {
			return $this->genre_result(
				self::STATUS_NO_HIT,
				Api_Error::append(
					sprintf(
						__( '楽天市場でジャンルが見つかりませんでした（HTTP %d / not_found）。', GACHIREAI_TEXT_DOMAIN ),
						$code
					),
					$body
				)
			);
		}
		if ( 200 !== $code || ! is_array( $data ) || isset( $data['error'] ) ) {
			$detail = is_array( $data ) && isset( $data['error_description'] )
				? (string) $data['error_description']
				: $body;
			$hint = 429 === $code
				? ' ' . __( '（リクエストが集中しています。少し待ってから、もう一度お試しください）', GACHIREAI_TEXT_DOMAIN )
				: '';
			return $this->genre_result(
				self::STATUS_API_ERROR,
				sprintf(
					__( '楽天API（ジャンル検索）がエラーを返しました（HTTP %1$d）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					$code,
					$this->mask( $detail, $access_key )
				) . $hint
			);
		}
		$current = $this->genre_node( (array) $this->genre_value( $data, 'genre' ), 0 );
		$level   = (int) ( $current['level'] ?? 0 );
		return array(
			'status'   => self::STATUS_OK,
			'current'  => $current,
			'children' => $this->genre_nodes( $this->genre_value( $data, 'children' ), $level + 1 ),
			'message'  => '',
		);
	}
	public function search_url( string $keyword, array $settings ): string {
		$keyword      = trim( $keyword );
		$affiliate_id = trim( (string) ( $settings['affiliate_id'] ?? '' ) );
		if ( '' === $keyword || '' === $affiliate_id ) {
			return '';
		}
		$destination = self::SEARCH_PAGE . rawurlencode( $keyword ) . '/';
		return self::AFFILIATE_GATEWAY . rawurlencode( $affiliate_id ) . '/?pc=' . rawurlencode( $destination );
	}
	public function plain_search_url( string $keyword ): string {
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return '';
		}
		return self::SEARCH_PAGE . rawurlencode( $keyword ) . '/';
	}
	public function page_affiliate_url( string $page_url, array $settings ): string {
		$page_url     = trim( $page_url );
		$affiliate_id = trim( (string) ( $settings['affiliate_id'] ?? '' ) );
		if ( '' === $page_url || '' === $affiliate_id ) {
			return '';
		}
		return self::AFFILIATE_GATEWAY . rawurlencode( $affiliate_id ) . '/?pc=' . rawurlencode( $page_url );
	}
	public static function plain_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || ! str_contains( $url, self::AFFILIATE_GATEWAY ) ) {
			return $url;
		}
		if ( preg_match( '/[?&]pc=([^&]+)/i', $url, $m ) ) {
			$plain = rawurldecode( $m[1] );
			if ( '' !== $plain ) {
				return $plain;
			}
		}
		return $url;
	}
	public function source( string $configured ): string {
		$base = trim( $configured );
		if ( '' === $base ) {
			$base = home_url();
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $base ) ) {
			$base = 'https://' . $base;
		}
		$parts  = wp_parse_url( $base );
		$scheme = (string) ( $parts['scheme'] ?? 'https' );
		$host   = (string) ( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return rtrim( $base, '/' );
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port;
	}
	private function request( string $keyword, array $settings, string $elements = self::ELEMENTS ) {
		$this->throttle();
		$query = array(
			'applicationId' => (string) ( $settings['application_id'] ?? '' ),
			'format'        => 'json',
			'keyword'       => rawurlencode( $keyword ),
			'elements'      => $elements,
		);
		$affiliate = (string) ( $settings['affiliate_id'] ?? '' );
		if ( '' !== $affiliate ) {
			$query['affiliateId'] = $affiliate;
		}
		$source = $this->source( (string) ( $settings['site_url'] ?? '' ) );
		return wp_remote_get(
			add_query_arg( $query, self::ENDPOINT ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'accessKey' => (string) ( $settings['access_key'] ?? '' ),
					'Origin'    => $source,
					'Referer'   => $source . '/',
				),
			)
		);
	}
	private function search_request( array $settings, string $keyword, string $sort, int $hits, string $genre_id = '', int $page = 1 ) {
		$this->throttle();
		$genre_id = mb_substr( (string) preg_replace( '/[^0-9]/', '', $genre_id ), 0, 32 );
		$query = array(
			'applicationId' => (string) ( $settings['application_id'] ?? '' ),
			'format'        => 'json',
			'sort'          => rawurlencode( '' !== $sort ? $sort : self::SORT_DEFAULT ),
			'hits'          => (string) max( 1, min( 30, $hits ) ),
			'elements'      => self::SELECT_ELEMENTS,
		);
		if ( $page > 1 ) {
			$query['page'] = (string) $page;
		}
		if ( '' !== $keyword ) {
			$query['keyword'] = rawurlencode( $keyword );
		}
		if ( '' !== $genre_id ) {
			$query['genreId'] = $genre_id;
		}
		$affiliate = (string) ( $settings['affiliate_id'] ?? '' );
		if ( '' !== $affiliate ) {
			$query['affiliateId'] = $affiliate;
		}
		$source = $this->source( (string) ( $settings['site_url'] ?? '' ) );
		return wp_remote_get(
			add_query_arg( $query, self::ENDPOINT ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'accessKey' => (string) ( $settings['access_key'] ?? '' ),
					'Origin'    => $source,
					'Referer'   => $source . '/',
				),
			)
		);
	}
	private function genre_request( array $settings, string $genre_id ) {
		$this->throttle();
		$query = array(
			'applicationId' => (string) ( $settings['application_id'] ?? '' ),
			'format'        => 'json',
			'genreId'       => $genre_id,
		);
		$source = $this->source( (string) ( $settings['site_url'] ?? '' ) );
		return wp_remote_get(
			add_query_arg( $query, self::GENRE_ENDPOINT ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'accessKey' => (string) ( $settings['access_key'] ?? '' ),
					'Origin'    => $source,
					'Referer'   => $source . '/',
				),
			)
		);
	}
	private function throttle(): void {
		$now  = microtime( true );
		$wait = self::MIN_INTERVAL - ( $now - self::$last_request_at );
		if ( self::$last_request_at > 0.0 && $wait > 0 ) {
			usleep( (int) ceil( $wait * 1000000 ) );
		}
		self::$last_request_at = microtime( true );
	}
	private function retry_after( array $response, $data ): int {
		$header = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		if ( $header > 0 ) {
			return min( self::MAX_RETRY_AFTER, $header );
		}
		$message = is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '';
		if ( preg_match( '/in\s+(\d+)\s*seconds?/i', $message, $m ) ) {
			return min( self::MAX_RETRY_AFTER, max( 1, (int) $m[1] ) );
		}
		return 1;
	}
	private function items( array $data ): array {
		$items = array();
		foreach ( $this->raw_items( $data ) as $entry ) {
			$items[] = $this->normalize_item( $this->unwrap_item( $entry ) );
		}
		return $items;
	}
	private function search_result( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'items'   => array(),
			'message' => $message,
		);
	}
	private function genre_value( array $data, string $key ) {
		foreach ( array( $key, ucfirst( $key ) ) as $candidate ) {
			if ( isset( $data[ $candidate ] ) ) {
				return $data[ $candidate ];
			}
		}
		return null;
	}
	private function genre_nodes( $list, int $fallback_level ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		if ( isset( $list['genreId'] ) || isset( $list['child'] ) ) {
			$list = array( $list );
		}
		$nodes = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$node = $this->genre_node( $entry, $fallback_level );
			if ( '' !== (string) $node['genre_id'] ) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}
	private function genre_node( array $raw, int $fallback_level ): array {
		foreach ( array( 'child', 'Child' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				$raw = $raw[ $key ];
				break;
			}
		}
		$level = isset( $raw['level'] ) && is_numeric( $raw['level'] ) ? (int) $raw['level'] : $fallback_level;
		return array(
			'genre_id' => (string) ( $raw['genreId'] ?? '' ),
			'name'     => $this->text_value( $raw['nameJa'] ?? ( $raw['name'] ?? '' ) ),
			'level'    => max( 0, $level ),
		);
	}
	private function genre_result( string $status, string $message ): array {
		return array(
			'status'   => $status,
			'current'  => array(),
			'children' => array(),
			'message'  => $message,
		);
	}
	private function raw_items( array $data ): array {
		foreach ( array( 'items', 'Items' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				return array_values( array_filter( $data[ $key ], 'is_array' ) );
			}
		}
		return array();
	}
	private function unwrap_item( array $entry ): array {
		foreach ( array( 'item', 'Item' ) as $key ) {
			if ( isset( $entry[ $key ] ) && is_array( $entry[ $key ] ) ) {
				return $entry[ $key ];
			}
		}
		return $entry;
	}
	private function first_item( array $data ): array {
		$items = $this->raw_items( $data );
		if ( array() === $items ) {
			return array();
		}
		return $this->normalize_item( $this->unwrap_item( $items[0] ) );
	}
	private function normalize_item( array $raw ): array {
		$images = $raw['mediumImageUrls'] ?? ( $raw['smallImageUrls'] ?? array() );
		return array(
			'item_name'     => (string) ( $raw['itemName'] ?? '' ),
			'item_code'     => (string) ( $raw['itemCode'] ?? '' ),
			'item_price'    => (int) ( $raw['itemPrice'] ?? 0 ),
			'item_url'      => self::plain_url( (string) ( $raw['itemUrl'] ?? '' ) ),
			'affiliate_url' => (string) ( $raw['affiliateUrl'] ?? '' ),
			'image_url'     => $this->image_url( $images ),
			'image_urls'    => $this->image_urls( $images ),
			'item_caption'  => $this->text_value( $raw['itemCaption'] ?? '' ),
			'item_detail'   => $this->text_value( $raw['itemDetailTable'] ?? '' ),
		);
	}
	private function text_value( $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$parts ): void {
					if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
						$parts[] = trim( (string) $item );
					}
				}
			);
			$value = implode( ' ', $parts );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( (string) $value ) );
	}
	private function image_url( $images ): string {
		if ( ! is_array( $images ) ) {
			return '';
		}
		$first = reset( $images );
		if ( is_array( $first ) ) {
			return self::upscale_image( (string) ( $first['imageUrl'] ?? '' ) );
		}
		return is_string( $first ) ? self::upscale_image( $first ) : '';
	}
	private static function upscale_image( string $url ): string {
		if ( '' === $url || false === strpos( $url, '_ex=' ) ) {
			return $url;
		}
		return (string) preg_replace( '/_ex=\d+x\d+/', '_ex=' . self::IMAGE_SIZE, $url );
	}
	private function image_urls( $images ): array {
		if ( ! is_array( $images ) ) {
			return array();
		}
		$urls = array();
		foreach ( $images as $entry ) {
			if ( is_array( $entry ) ) {
				$url = trim( (string) ( $entry['imageUrl'] ?? '' ) );
			} elseif ( is_string( $entry ) ) {
				$url = trim( $entry );
			} else {
				$url = '';
			}
			if ( '' !== $url ) {
				$urls[] = self::upscale_image( $url );
			}
		}
		return array_values( array_unique( $urls ) );
	}
	private function result( string $status, string $message, array $item = array() ): array {
		return array(
			'status'  => $status,
			'item'    => $item,
			'message' => $message,
		);
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

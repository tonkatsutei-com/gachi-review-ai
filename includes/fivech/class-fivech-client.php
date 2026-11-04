<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Fivech;
use Tonkatsutei\Gachi_Review_AI\Rate_Limit_Exception;
final class Fivech_Client {
	private const BBSMENU_CACHE_KEY = 'gachireai_5ch_bbsmenu_v2';
	private const BBSMENU_TTL = 86400;
	private const BBSMENU_URL = 'https://menu.5ch.net/bbsmenu.json';
	private const USER_AGENT = 'gachi-review-ai/' . GACHIREAI_APP_VERSION . ' (WordPress plugin; private non-commercial use)';
	private const TIMEOUT = 30;
	private const MIN_INTERVAL = 2.0;
	private static float $last_request_at = 0.0;
	private ?array $board_list_cache = null;
	public function board_url( string $board ): string {
		$boards = $this->boards();
		if ( ! isset( $boards[ $board ] ) ) {
			throw new \RuntimeException( sprintf( '5ch の板「%s」が見つかりません（板一覧にありません）。', $board ) );
		}
		return $boards[ $board ];
	}
	public function threads( string $board ): array {
		$body    = $this->fetch( $this->board_url( $board ) . 'subject.txt' );
		$threads = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $body ) ?: array() as $line ) {
			$parsed = self::parse_subject_line( $line );
			if ( null !== $parsed ) {
				$threads[] = $parsed;
			}
		}
		usort(
			$threads,
			static fn( array $a, array $b ): int => $b['count'] <=> $a['count']
		);
		return $threads;
	}
	public function dat( string $board, string $thread_id ): array {
		if ( ! preg_match( '/^\d+$/', $thread_id ) ) {
			throw new \RuntimeException( '5ch のスレッド ID が不正です。' );
		}
		$body = $this->fetch( $this->board_url( $board ) . 'dat/' . $thread_id . '.dat' );
		$res  = array();
		$no   = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $body ) ?: array() as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$columns = explode( '<>', $line );
			if ( count( $columns ) < 4 ) {
				continue;
			}
			++$no;
			$res[] = array(
				'no'        => $no,
				'name'      => self::decode( (string) $columns[0] ),
				'mail'      => self::decode( (string) $columns[1] ),
				'date'      => self::decode( (string) $columns[2] ),
				'posted_at' => self::parse_date( self::decode( (string) $columns[2] ) ),
				'body'      => self::clean_body( (string) $columns[3] ),
			);
		}
		if ( array() === $res ) {
			throw new \RuntimeException(
				sprintf( '5ch のスレッド（%s/%s）を解析できませんでした（dat が空か、形式が変わった可能性があります）。', $board, $thread_id )
			);
		}
		return $res;
	}
	public function board_list(): array {
		if ( is_array( $this->board_list_cache ) ) {
			return $this->board_list_cache;
		}
		$cached = get_transient( self::BBSMENU_CACHE_KEY );
		if ( is_array( $cached ) && array() !== $cached ) {
			$this->board_list_cache = $cached;
			return $this->board_list_cache;
		}
		$raw  = $this->fetch( self::BBSMENU_URL );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['menu_list'] ) || ! is_array( $data['menu_list'] ) ) {
			throw new \RuntimeException( '5ch の板一覧（bbsmenu.json）の応答を解析できませんでした。' );
		}
		$boards = self::parse_bbsmenu( $data );
		if ( array() === $boards ) {
			throw new \RuntimeException( '5ch の板一覧から板を 1 件も取得できませんでした。' );
		}
		set_transient( self::BBSMENU_CACHE_KEY, $boards, self::BBSMENU_TTL );
		$this->board_list_cache = $boards;
		return $this->board_list_cache;
	}
	public function boards(): array {
		$boards = array();
		foreach ( $this->board_list() as $board ) {
			$boards[ $board['directory_name'] ] = $board['url'];
		}
		return $boards;
	}
	public function categories(): array {
		$categories = array();
		foreach ( $this->board_list() as $board ) {
			$category = $board['category_name'];
			if ( '' === $category ) {
				continue;
			}
			$categories[ $category ][] = array(
				'board_name'     => $board['board_name'],
				'directory_name' => $board['directory_name'],
			);
		}
		return $categories;
	}
	public function resolve_board( string $name ): ?string {
		$needle = self::normalize_name( $name );
		if ( '' === $needle ) {
			return null;
		}
		$partial  = null;
		$category = false;
		foreach ( $this->board_list() as $board ) {
			if ( self::normalize_name( $board['directory_name'] ) === $needle ) {
				return $board['directory_name'];
			}
			if ( self::normalize_name( $board['board_name'] ) === $needle ) {
				return $board['directory_name'];
			}
			if ( '' !== $board['category_name'] && self::normalize_name( $board['category_name'] ) === $needle ) {
				$category = true;
			}
			if ( '' !== $board['board_name'] && str_contains( self::normalize_name( $board['board_name'] ), $needle ) ) {
				if ( null === $partial || mb_strlen( $board['board_name'] ) < mb_strlen( $partial['board_name'] ) ) {
					$partial = $board;
				}
			}
		}
		if ( $category ) {
			return null;
		}
		return null !== $partial ? $partial['directory_name'] : null;
	}
	private static function normalize_name( string $name ): string {
		$name = (string) preg_replace( '/[\s\x{3000}]+/u', '', trim( $name ) );
		return mb_strtolower( $name );
	}
	private static function parse_bbsmenu( array $data ): array {
		$boards = array();
		foreach ( (array) $data['menu_list'] as $category ) {
			if ( ! is_array( $category ) || ! isset( $category['category_content'] ) ) {
				continue;
			}
			$category_name = trim( (string) ( $category['category_name'] ?? '' ) );
			foreach ( (array) $category['category_content'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$key = trim( (string) ( $entry['directory_name'] ?? '' ) );
				$url = trim( (string) ( $entry['url'] ?? '' ) );
				if ( '' === $key || '' === $url || ! str_starts_with( $url, 'http' ) ) {
					continue;
				}
				$own_category = trim( (string) ( $entry['category_name'] ?? '' ) );
				$boards[] = array(
					'directory_name' => $key,
					'board_name'     => trim( (string) ( $entry['board_name'] ?? '' ) ),
					'category_name'  => '' !== $own_category ? $own_category : $category_name,
					'url'            => rtrim( $url, '/' ) . '/',
				);
			}
		}
		return $boards;
	}
	private static function parse_subject_line( string $line ): ?array {
		if ( ! preg_match( '/^(\d+)\.dat<>(.+?)\s*\((\d+)\)\s*$/u', self::decode( $line ), $m ) ) {
			return null;
		}
		return array(
			'id'    => $m[1],
			'title' => trim( $m[2] ),
			'count' => (int) $m[3],
		);
	}
	private static function decode( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		$converted = mb_convert_encoding( $text, 'UTF-8', 'SJIS-win' );
		return is_string( $converted ) ? $converted : $text;
	}
	private static function clean_body( string $body ): string {
		$text = self::decode( $body );
		$text = (string) preg_replace( '/<hr\s*\/?>/iu', "\n---\n", $text );
		$text = (string) preg_replace( '/<br\s*\/?>/iu', "\n", $text );
		$text = strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( $text );
	}
	private static function parse_date( string $raw ): int {
		if ( ! preg_match( '/(\d{4})\/(\d{1,2})\/(\d{1,2})[^\d]*(\d{1,2}):(\d{2}):(\d{2})/u', $raw, $m ) ) {
			return 0;
		}
		$timestamp = gmmktime( (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1] );
		if ( false === $timestamp ) {
			return 0;
		}
		return $timestamp - 9 * 3600;
	}
	private function fetch( string $url ): string {
		$this->throttle();
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'User-Agent'      => self::USER_AGENT,
					'Accept-Language' => 'ja',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( '5ch へのリクエストに失敗しました: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code || 503 === $code ) {
			throw new Rate_Limit_Exception( 60 );
		}
		if ( 200 !== $code ) {
			throw new \RuntimeException(
				'5ch の応答がエラーです（HTTP ' . $code . '）: ' . mb_substr( trim( (string) wp_remote_retrieve_body( $response ) ), 0, 200 )
			);
		}
		return (string) wp_remote_retrieve_body( $response );
	}
	private function throttle(): void {
		$now  = microtime( true );
		$wait = self::MIN_INTERVAL - ( $now - self::$last_request_at );
		if ( self::$last_request_at > 0.0 && $wait > 0 ) {
			usleep( (int) ceil( $wait * 1000000 ) );
		}
		self::$last_request_at = microtime( true );
	}
}

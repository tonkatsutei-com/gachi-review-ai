<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Mastodon;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Rate_Limit_Exception;
final class Mastodon_Client {
	private const SEARCH_PATH = '/api/v2/search';
	private const CONTEXT_PATH = '/api/v1/statuses/%s/context';
	private const TEST_PATH = '/api/v1/accounts/verify_credentials';
	private const USER_AGENT = 'gachi-review-ai/' . GACHIREAI_APP_VERSION . ' (WordPress plugin; private non-commercial use)'; 
	private const TIMEOUT = 30;
	private const MIN_INTERVAL = 1.0;
	private const MAX_LIMIT = 40;
	private const MAX_REPLIES = 50;
	private const MESSAGE_MAX = 200;
	private static float $last_request_at = 0.0;
	public function search_statuses( string $instance, string $token, string $query, int $limit = 20 ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$words = preg_split( '/[\s\x{00A0}\x{3000}]+/u', $query );
		$words = is_array( $words ) ? array_values( array_filter( $words, static fn( $w ): bool => '' !== trim( (string) $w ) ) ) : array();
		$count = count( $words );
		for ( $n = $count; $n >= 1; --$n ) {
			$list = $this->search_once( $instance, $token, implode( ' ', array_slice( $words, 0, $n ) ), $limit );
			if ( array() !== $list ) {
				return $list;
			}
		}
		return array();
	}
	public function test( string $instance, string $token ): array {
		$instance = trim( $instance );
		$token    = trim( $token );
		if ( '' === $instance || '' === $token ) {
			return array(
				'type'      => 'warning',
				'message'   => __( 'インスタンスとアクセストークンを入力してから接続テストを実行してください。', GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		}
		try {
			$result = $this->decode( $this->request( $instance, self::TEST_PATH, array(), $token ) );
			if ( 200 !== $result['code'] ) {
				throw $this->error_for( $result, $token );
			}
		} catch ( \Throwable $e ) {
			return array(
				'type'      => 'error',
				'message'   => sprintf(
					__( '接続テストに失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				),
				'connected' => false,
			);
		}
		return array(
			'type'      => 'success',
			'message'   => __( '接続テストに成功しました。', GACHIREAI_TEXT_DOMAIN ),
			'connected' => true,
		);
	}
	private function search_once( string $instance, string $token, string $query, int $limit ): array {
		$result = $this->decode(
			$this->request(
				$instance,
				self::SEARCH_PATH,
				array(
					'q'      => $query,
					'type'   => 'statuses',
					'offset' => 0,
					'limit'  => max( 1, min( self::MAX_LIMIT, $limit ) ),
				),
				$token
			)
		);
		if ( 200 !== $result['code'] ) {
			throw $this->error_for( $result, $token );
		}
		$statuses = $result['data']['statuses'] ?? array();
		if ( ! is_array( $statuses ) ) {
			return array();
		}
		$list = array();
		foreach ( array_values( array_filter( $statuses, 'is_array' ) ) as $status ) {
			$status_id = trim( (string) ( $status['id'] ?? '' ) );
			$content   = self::clean_text( (string) ( $status['content'] ?? '' ) );
			if ( '' === $status_id || '' === $content ) {
				continue;
			}
			$list[] = array(
				'status_id'  => $status_id,
				'content'    => $content,
				'url'        => trim( (string) ( $status['url'] ?? '' ) ),
				'author'     => trim( (string) ( $status['account']['acct'] ?? '' ) ),
				'created_at' => self::timestamp( (string) ( $status['created_at'] ?? '' ) ),
				'favourites' => max( 0, (int) ( $status['favourites_count'] ?? 0 ) ),
				'replies'    => max( 0, (int) ( $status['replies_count'] ?? 0 ) ),
				'language'   => trim( (string) ( $status['language'] ?? '' ) ),
			);
		}
		return $list;
	}
	public function status_context( string $instance, string $token, string $status_id, int $limit = self::MAX_REPLIES ): array {
		$status_id = trim( $status_id );
		if ( '' === $status_id ) {
			return array();
		}
		$result = $this->decode(
			$this->request(
				$instance,
				sprintf( self::CONTEXT_PATH, rawurlencode( $status_id ) ),
				array(),
				$token
			)
		);
		if ( 200 !== $result['code'] ) {
			throw $this->error_for( $result, $token );
		}
		$descendants = $result['data']['descendants'] ?? array();
		if ( ! is_array( $descendants ) ) {
			return array();
		}
		$list = array();
		foreach ( array_slice( array_values( array_filter( $descendants, 'is_array' ) ), 0, max( 1, $limit ) ) as $status ) {
			$reply_id = trim( (string) ( $status['id'] ?? '' ) );
			$content  = self::clean_text( (string) ( $status['content'] ?? '' ) );
			if ( '' === $reply_id || '' === $content ) {
				continue;
			}
			$list[] = array(
				'reply_id'   => $reply_id,
				'content'    => $content,
				'url'        => trim( (string) ( $status['url'] ?? '' ) ),
				'author'     => trim( (string) ( $status['account']['acct'] ?? '' ) ),
				'created_at' => self::timestamp( (string) ( $status['created_at'] ?? '' ) ),
				'favourites' => max( 0, (int) ( $status['favourites_count'] ?? 0 ) ),
			);
		}
		return $list;
	}
	private function request( string $instance, string $path, array $params, string $token ) {
		$this->throttle();
		$url = self::base_url( $instance ) . $path;
		if ( array() !== $params ) {
			$url .= '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		}
		return wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'User-Agent'    => self::USER_AGENT,
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . trim( $token ),
				),
			)
		);
	}
	private static function base_url( string $instance ): string {
		$host = (string) preg_replace( '#^https?://#i', '', trim( $instance ) );
		$host = rtrim( $host, '/' );
		return 'https://' . $host;
	}
	private function decode( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Mastodon へのリクエストに失敗しました: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( 200 === $code && ! is_array( $data ) ) {
			throw new \RuntimeException( 'Mastodon の応答を解釈できませんでした（JSON ではありません）。' );
		}
		return array(
			'code' => $code,
			'data' => is_array( $data ) ? $data : array(),
			'raw'  => $raw,
		);
	}
	private function error_for( array $result, string $token ): \RuntimeException {
		if ( 429 === $result['code'] ) {
			throw new Rate_Limit_Exception( 300 );
		}
		if ( 401 === $result['code'] || 403 === $result['code'] ) {
			Connection_Status::note_runtime_failure( 'mastodon', $result['code'] );
			return new \RuntimeException(
				__( 'Mastodon のアクセストークンが無効か、権限（read:search）が不足しています。「レビュー設定」タブの「Mastodon」でトークンを確認してください。', GACHIREAI_TEXT_DOMAIN )
			);
		}
		$message = trim( (string) ( $result['data']['error'] ?? '' ) );
		if ( '' === $message ) {
			$message = mb_substr( trim( $result['raw'] ), 0, self::MESSAGE_MAX );
		}
		return new \RuntimeException(
			sprintf( 'Mastodon API エラー（HTTP %1$d）: %2$s', $result['code'], $this->mask( $message, $token ) )
		);
	}
	private static function clean_text( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		$text = (string) preg_replace( '/<\/p\s*>/iu', "\n\n", $text );
		$text = (string) preg_replace( '/<br\s*\/?>/iu', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\n{3,}/u', "\n\n", $text );
		return trim( $text );
	}
	private static function timestamp( string $iso ): int {
		$iso = trim( $iso );
		if ( '' === $iso ) {
			return 0;
		}
		$timestamp = strtotime( $iso );
		return false === $timestamp ? 0 : $timestamp;
	}
	private function mask( string $text, string $token ): string {
		$token = trim( $token );
		if ( '' === $token ) {
			return $text;
		}
		return str_replace( $token, '***', $text );
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

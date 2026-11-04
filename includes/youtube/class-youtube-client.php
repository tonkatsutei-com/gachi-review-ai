<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Youtube;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Rate_Limit_Exception;
final class YouTube_Client {
	private const BASE_URL = 'https://www.googleapis.com/youtube/v3/';
	private const SEARCH_PATH = 'search';
	private const COMMENT_THREADS_PATH = 'commentThreads';
	private const USER_AGENT = 'gachi-review-ai/' . GACHIREAI_APP_VERSION . ' (WordPress plugin; private non-commercial use)'; 
	private const TIMEOUT = 30;
	private const MIN_INTERVAL = 1.0;
	private const MAX_SEARCH_RESULTS = 50;
	private const MAX_COMMENT_RESULTS = 100;
	private const MESSAGE_MAX = 200;
	private static float $last_request_at = 0.0;
	public function search_videos( string $api_key, string $query, int $max_results = 5, int $published_after = 0 ): array {
		$api_key = trim( $api_key );
		$query   = trim( $query );
		if ( '' === $api_key ) {
			throw new \RuntimeException( $this->missing_key_message() );
		}
		if ( '' === $query ) {
			return array();
		}
		$params = array(
			'part'       => 'snippet',
			'type'       => 'video',
			'q'          => $query,
			'maxResults' => max( 1, min( self::MAX_SEARCH_RESULTS, $max_results ) ),
			'key'        => $api_key,
		);
		if ( $published_after > 0 ) {
			$params['publishedAfter'] = gmdate( 'Y-m-d\TH:i:s\Z', $published_after );
		}
		$response = $this->request( self::SEARCH_PATH, $params );
		$result   = $this->decode( $response, $api_key );
		if ( 200 !== $result['code'] ) {
			throw $this->error_for( $result, $api_key );
		}
		$videos = array();
		foreach ( (array) ( $result['data']['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$video_id = (string) ( $item['id']['videoId'] ?? '' );
			if ( '' === $video_id ) {
				continue;
			}
			$snippet  = (array) ( $item['snippet'] ?? array() );
			$videos[] = array(
				'video_id'     => $video_id,
				'title'        => trim( (string) ( $snippet['title'] ?? '' ) ),
				'channel'      => trim( (string) ( $snippet['channelTitle'] ?? '' ) ),
				'published_at' => self::timestamp( (string) ( $snippet['publishedAt'] ?? '' ) ),
				'url'          => 'https://www.youtube.com/watch?v=' . $video_id,
			);
		}
		return $videos;
	}
	public function comment_threads( string $api_key, string $video_id, int $max_results = 50 ): array {
		$api_key  = trim( $api_key );
		$video_id = trim( $video_id );
		$empty    = array(
			'total'    => 0,
			'comments' => array(),
		);
		if ( '' === $api_key ) {
			throw new \RuntimeException( $this->missing_key_message() );
		}
		if ( '' === $video_id ) {
			return $empty;
		}
		$response = $this->request(
			self::COMMENT_THREADS_PATH,
			array(
				'part'       => 'snippet,replies',
				'videoId'    => $video_id,
				'maxResults' => max( 1, min( self::MAX_COMMENT_RESULTS, $max_results ) ),
				'order'      => 'relevance',
				'key'        => $api_key,
			)
		);
		$result   = $this->decode( $response, $api_key );
		if ( 403 === $result['code'] && 'commentsDisabled' === self::error_reason( $result['data'] ) ) {
			return $empty;
		}
		if ( 200 !== $result['code'] ) {
			throw $this->error_for( $result, $api_key );
		}
		$comments = array();
		foreach ( (array) ( $result['data']['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$top = $this->normalize_comment( (array) ( $item['snippet']['topLevelComment'] ?? array() ) );
			if ( array() !== $top ) {
				$comments[] = $top;
			}
			foreach ( (array) ( $item['replies']['comments'] ?? array() ) as $reply ) {
				if ( ! is_array( $reply ) ) {
					continue;
				}
				$normalized = $this->normalize_comment( $reply );
				if ( array() !== $normalized ) {
					$comments[] = $normalized;
				}
			}
		}
		$total = (int) ( $result['data']['pageInfo']['totalResults'] ?? count( $comments ) );
		return array(
			'total'    => max( $total, count( $comments ) ),
			'comments' => $comments,
		);
	}
	public function test( string $api_key ): array {
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			return array(
				'type'      => 'warning',
				'message'   => $this->missing_key_message(),
				'connected' => false,
			);
		}
		try {
			$response = $this->request(
				self::SEARCH_PATH,
				array(
					'part'       => 'snippet',
					'type'       => 'video',
					'q'          => 'test',
					'maxResults' => 1,
					'key'        => $api_key,
				)
			);
			$result   = $this->decode( $response, $api_key );
			if ( 200 !== $result['code'] ) {
				throw $this->error_for( $result, $api_key );
			}
		} catch ( Quota_Exceeded_Exception $e ) {
			return array(
				'type'      => 'error',
				'message'   => __( '接続テストに失敗しました: YouTube のクォータを使い切りました。時間をおいてから再試行してください。', GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
		} catch ( Rate_Limit_Exception $e ) {
			return array(
				'type'      => 'error',
				'message'   => __( '接続テストに失敗しました: YouTube のレート制限に達しました。時間をおいてから再試行してください。', GACHIREAI_TEXT_DOMAIN ),
				'connected' => false,
			);
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
	private function normalize_comment( array $comment ): array {
		$comment_id = trim( (string) ( $comment['id'] ?? '' ) );
		$snippet    = (array) ( $comment['snippet'] ?? array() );
		$body       = self::clean_text( (string) ( $snippet['textDisplay'] ?? ( $snippet['textOriginal'] ?? '' ) ) );
		if ( '' === $comment_id || '' === $body ) {
			return array();
		}
		return array(
			'comment_id'   => $comment_id,
			'body'         => $body,
			'author'       => trim( (string) ( $snippet['authorDisplayName'] ?? '' ) ),
			'like_count'   => max( 0, (int) ( $snippet['likeCount'] ?? 0 ) ),
			'published_at' => self::timestamp( (string) ( $snippet['publishedAt'] ?? '' ) ),
		);
	}
	private function request( string $path, array $query ) {
		$this->throttle();
		return wp_remote_get(
			self::BASE_URL . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'application/json',
				),
			)
		);
	}
	private function decode( $response, string $api_key ): array {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				'YouTube へのリクエストに失敗しました: ' . $this->mask( $response->get_error_message(), $api_key )
			);
		}
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'data' => is_array( $data ) ? $data : array(),
			'raw'  => $raw,
		);
	}
	private function error_for( array $result, string $api_key ): \RuntimeException {
		if ( 429 === $result['code'] ) {
			throw new Rate_Limit_Exception( 60 );
		}
		if ( 403 === $result['code'] && 'quotaExceeded' === self::error_reason( $result['data'] ) ) {
			throw new Quota_Exceeded_Exception();
		}
		Connection_Status::note_runtime_failure( 'youtube', $result['code'] );
		$reason  = self::error_reason( $result['data'] );
		$message = trim( (string) ( $result['data']['error']['message'] ?? '' ) );
		if ( '' === $message ) {
			$message = mb_substr( trim( $result['raw'] ), 0, self::MESSAGE_MAX );
		}
		return new \RuntimeException(
			sprintf(
				'YouTube Data API エラー（HTTP %1$d%2$s）: %3$s',
				$result['code'],
				'' !== $reason ? ' / ' . $reason : '',
				$this->mask( $message, $api_key )
			)
		);
	}
	private static function error_reason( array $data ): string {
		return (string) ( $data['error']['errors'][0]['reason'] ?? '' );
	}
	private function missing_key_message(): string {
		return __( 'YouTube の API キーが未設定です。「レビュー設定」タブの「YouTube」で保存してください。', GACHIREAI_TEXT_DOMAIN );
	}
	private static function timestamp( string $iso ): int {
		$iso = trim( $iso );
		if ( '' === $iso ) {
			return 0;
		}
		$timestamp = strtotime( $iso );
		return false === $timestamp ? 0 : $timestamp;
	}
	private static function clean_text( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		$text = (string) preg_replace( '/<br\s*\/?>/iu', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( $text );
	}
	private function mask( string $text, string $api_key ): string {
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			return $text;
		}
		return str_replace( $api_key, '***', $text );
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

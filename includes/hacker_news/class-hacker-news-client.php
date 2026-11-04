<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Hacker_News;
use Tonkatsutei\Gachi_Review_AI\Rate_Limit_Exception;
final class Hacker_News_Client {
	private const SEARCH_URL = 'https://hn.algolia.com/api/v1/search';
	private const USER_AGENT = 'gachi-review-ai/' . GACHIREAI_APP_VERSION . ' (WordPress plugin; private non-commercial use)'; 
	private const TIMEOUT = 30;
	private const MIN_INTERVAL = 1.0;
	private const MAX_PER_PAGE = 1000;
	private const MESSAGE_MAX = 200;
	private static float $last_request_at = 0.0;
	public function search_stories( string $query, int $max_results = 5, int $period_seconds = 0 ): array {
		$stories = array();
		foreach ( $this->search( 'story', $query, $max_results, $period_seconds ) as $hit ) {
			$story_id = trim( (string) ( $hit['objectID'] ?? '' ) );
			if ( '' === $story_id ) {
				continue;
			}
			$stories[] = array(
				'story_id'     => $story_id,
				'title'        => trim( (string) ( $hit['title'] ?? '' ) ),
				'url'          => trim( (string) ( $hit['url'] ?? '' ) ),
				'points'       => max( 0, (int) ( $hit['points'] ?? 0 ) ),
				'num_comments' => max( 0, (int) ( $hit['num_comments'] ?? 0 ) ),
				'author'       => trim( (string) ( $hit['author'] ?? '' ) ),
				'created_at'   => max( 0, (int) ( $hit['created_at_i'] ?? 0 ) ),
			);
		}
		return $stories;
	}
	public function search_comments( string $query, int $max_results = 50, int $period_seconds = 0 ): array {
		$comments = array();
		foreach ( $this->search( 'comment', $query, $max_results, $period_seconds ) as $hit ) {
			$comment_id = trim( (string) ( $hit['objectID'] ?? '' ) );
			$body       = self::clean_text( (string) ( $hit['comment_text'] ?? '' ) );
			if ( '' === $comment_id || '' === $body ) {
				continue;
			}
			$comments[] = array(
				'comment_id'  => $comment_id,
				'story_id'    => trim( (string) ( $hit['story_id'] ?? '' ) ),
				'story_title' => trim( (string) ( $hit['story_title'] ?? '' ) ),
				'story_url'   => trim( (string) ( $hit['story_url'] ?? '' ) ),
				'body'        => $body,
				'author'      => trim( (string) ( $hit['author'] ?? '' ) ),
				'created_at'  => max( 0, (int) ( $hit['created_at_i'] ?? 0 ) ),
			);
		}
		return $comments;
	}
	private function search( string $tags, string $query, int $max_results, int $period_seconds ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$params = array(
			'query'       => $query,
			'tags'        => $tags,
			'hitsPerPage' => max( 1, min( self::MAX_PER_PAGE, $max_results ) ),
			'removeWordsIfNoResults' => 'lastWords',
		);
		if ( $period_seconds > 0 ) {
			$params['numericFilters'] = 'created_at_i>' . ( time() - $period_seconds );
		}
		$result = $this->decode( $this->request( $params ) );
		if ( 200 !== $result['code'] ) {
			throw $this->error_for( $result );
		}
		$hits = $result['data']['hits'] ?? array();
		if ( ! is_array( $hits ) ) {
			return array();
		}
		return array_values( array_filter( $hits, 'is_array' ) );
	}
	private function request( array $params ) {
		$this->throttle();
		return wp_remote_get(
			self::SEARCH_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'application/json',
				),
			)
		);
	}
	private function decode( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Hacker News へのリクエストに失敗しました: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( 200 === $code && ! is_array( $data ) ) {
			throw new \RuntimeException( 'Hacker News の応答を解釈できませんでした（JSON ではありません）。' );
		}
		return array(
			'code' => $code,
			'data' => is_array( $data ) ? $data : array(),
			'raw'  => $raw,
		);
	}
	private function error_for( array $result ): \RuntimeException {
		if ( 429 === $result['code'] ) {
			throw new Rate_Limit_Exception( 60 );
		}
		$message = trim( (string) ( $result['data']['message'] ?? '' ) );
		if ( '' === $message ) {
			$message = mb_substr( trim( $result['raw'] ), 0, self::MESSAGE_MAX );
		}
		return new \RuntimeException(
			sprintf( 'Hacker News 検索 API エラー（HTTP %1$d）: %2$s', $result['code'], $message )
		);
	}
	private static function clean_text( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		$text = (string) preg_replace( '/<p\s*\/?>/iu', "\n\n", $text );
		$text = (string) preg_replace( '/<br\s*\/?>/iu', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\n{3,}/u', "\n\n", $text );
		return trim( $text );
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

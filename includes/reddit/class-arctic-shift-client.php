<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Reddit;
final class Arctic_Shift_Client {
	private const BASE_URL = 'https://arctic-shift.photon-reddit.com';
	private const USER_AGENT = 'gachi-review-ai/' . GACHIREAI_APP_VERSION . ' (WordPress plugin)'; 
	private const TIMEOUT = 30;
	private const DEFAULT_RETRY_AFTER = 60;
	private const MAX_LIMIT = 100;
	private const POST_FIELDS = array(
		'id',
		'subreddit',
		'title',
		'selftext',
		'url',
		'score',
		'num_comments',
		'created_utc',
		'author',
		'link_flair_text',
	);
	private const COMMENT_FIELDS = array(
		'id',
		'body',
		'score',
		'author',
		'created_utc',
		'link_id',
		'parent_id',
	);
	public function search_posts( string $subreddit, string $after = '', int $limit = self::MAX_LIMIT ): array {
		$query = array(
			'subreddit' => $subreddit,
			'sort'      => 'desc',
			'limit'     => max( 1, min( self::MAX_LIMIT, $limit ) ),
			'fields'    => implode( ',', self::POST_FIELDS ),
		);
		if ( '' !== $after ) {
			$query['after'] = $after;
		}
		return $this->get( '/api/posts/search', $query );
	}
	public function search_comments( string $link_id, int $limit = self::MAX_LIMIT ): array {
		return $this->get(
			'/api/comments/search',
			array(
				'link_id' => $link_id,
				'sort'    => 'desc',
				'limit'   => max( 1, min( self::MAX_LIMIT, $limit ) ),
				'fields'  => implode( ',', self::COMMENT_FIELDS ),
			)
		);
	}
	private function get( string $path, array $query ): array {
		$url = self::BASE_URL . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
					'Accept'     => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Arctic Shift へのリクエストに失敗しました: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			throw new Rate_Limit_Exception( $this->retry_after( $response ) );
		}
		if ( 200 !== $code ) {
			throw new \RuntimeException(
				'Arctic Shift API エラー（HTTP ' . $code . '）: '
				. mb_substr( trim( (string) wp_remote_retrieve_body( $response ) ), 0, 300 )
			);
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			throw new \RuntimeException( 'Arctic Shift API の応答を解析できませんでした。' );
		}
		return array_values( array_filter( $body['data'], 'is_array' ) );
	}
	private function retry_after( array $response ): int {
		$reset = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
		return $reset > 0 ? $reset : self::DEFAULT_RETRY_AFTER;
	}
}

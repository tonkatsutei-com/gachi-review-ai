<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Youtube;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Rate_Limit_Exception;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class YouTube_Collector {
	private const GROUP = 'youtube';
	public const SOURCE = 'youtube';
	public const DEFAULT_SEARCH_TERMS = array( 'レビュー', '開封' );
	public const DEFAULT_VIDEO_LIMIT = 5;
	private const MAX_COMMENTS_PER_VIDEO = 50;
	private const MAX_QUERY_LENGTH = 120;
	private YouTube_Client $client;
	public function __construct( ?YouTube_Client $client = null ) {
		$this->client = $client ?? new YouTube_Client();
	}
	public function is_available(): bool {
		return '' !== $this->api_key() && Connection_Status::is_verified( self::GROUP );
	}
	private function settings(): array {
		$values = ( new Settings() )->get( self::GROUP, array() );
		return is_array( $values ) ? $values : array();
	}
	private function api_key(): string {
		$values = $this->settings();
		return trim( (string) ( $values['api_key'] ?? '' ) );
	}
	public static function appended_terms(): array {
		$values = Source_Settings::values( self::GROUP );
		if ( ! array_key_exists( 'terms', $values ) ) {
			return self::DEFAULT_SEARCH_TERMS;
		}
		$value = trim( (string) $values['terms'] );
		if ( '' === $value ) {
			return array();
		}
		$parts = preg_split( '/[\s,\x{3001}]+/u', $value );
		if ( ! is_array( $parts ) ) {
			return self::DEFAULT_SEARCH_TERMS;
		}
		$terms = array();
		foreach ( $parts as $part ) {
			$text = trim( (string) $part );
			if ( '' !== $text ) {
				$terms[] = $text;
			}
		}
		return array() === $terms ? self::DEFAULT_SEARCH_TERMS : array_values( array_unique( $terms ) );
	}
	public static function search_terms( string $name ): array {
		$name = trim( $name );
		if ( '' === $name ) {
			return array();
		}
		$terms = self::appended_terms();
		if ( array() === $terms ) {
			return array( mb_substr( $name, 0, self::MAX_QUERY_LENGTH ) );
		}
		$queries = array();
		foreach ( $terms as $term ) {
			$queries[] = mb_substr( $name . ' ' . $term, 0, self::MAX_QUERY_LENGTH );
		}
		return $queries;
	}
	public function collect( array $overrides = array() ): array {
		$result = array(
			'posts'    => 0,
			'comments' => 0,
			'found'    => 0,
			'searches' => array(),
			'errors'   => array(),
		);
		$api_key = $this->api_key();
		$name    = trim( (string) ( $overrides['product_name'] ?? '' ) );
		if ( '' === $api_key || '' === $name ) {
			return $result;
		}
		Source_Settings::delete_stale( self::SOURCE );
		$period_seconds = Source_Settings::period_seconds( self::GROUP );
		$cutoff         = $period_seconds > 0 ? time() - $period_seconds : 0;
		$limit          = $this->video_limit();
		$seen         = array();
		$seen_queries = array();
		foreach ( self::search_terms( $name ) as $base ) {
			foreach ( self::narrowed_queries( $base ) as $query ) {
				if ( isset( $seen_queries[ $query ] ) ) {
					continue;
				}
				$seen_queries[ $query ] = true;
				try {
					$videos = $this->client->search_videos( $api_key, $query, $limit, $cutoff );
				} catch ( Quota_Exceeded_Exception $e ) {
					$result['errors'][] = $e->getMessage();
					break 2;
				} catch ( Rate_Limit_Exception $e ) {
					$result['errors'][] = $e->getMessage();
					break 2;
				} catch ( \RuntimeException $e ) {
					$result['errors'][] = $e->getMessage();
					$result['searches'][] = self::search_step( $query, $result );
					continue;
				}
				foreach ( $videos as $video ) {
					$video_id = (string) ( $video['video_id'] ?? '' );
					if ( '' === $video_id || isset( $seen[ $video_id ] ) ) {
						continue;
					}
					$seen[ $video_id ] = true;
					++$result['found'];
					if ( $cutoff > 0 && (int) ( $video['published_at'] ?? 0 ) > 0 && (int) $video['published_at'] < $cutoff ) {
						continue;
					}
					try {
						$comments = $this->client->comment_threads( $api_key, $video_id, self::MAX_COMMENTS_PER_VIDEO );
					} catch ( Quota_Exceeded_Exception $e ) {
						$result['errors'][] = $e->getMessage();
						return $result;
					} catch ( Rate_Limit_Exception $e ) {
						$result['errors'][] = $e->getMessage();
						return $result;
					} catch ( \RuntimeException $e ) {
						$result['errors'][] = $e->getMessage();
						continue;
					}
					$saved = $this->save_thread( $video, (int) ( $comments['total'] ?? 0 ) );
					if ( $saved['id'] <= 0 ) {
						continue;
					}
					if ( $saved['new'] ) {
						++$result['posts'];
					}
					$result['comments'] += $this->save_comments( $video_id, $saved['id'], (array) ( $comments['comments'] ?? array() ) );
				}
				$result['searches'][] = self::search_step( $query, $result );
				if ( array() !== $videos ) {
					break;
				}
			}
		}
		return $result;
	}
	private static function narrowed_queries( string $query ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$words = preg_split( '/[\s\x{00A0}\x{3000}]+/u', $query );
		$words = is_array( $words )
			? array_values( array_filter( $words, static fn( $w ): bool => '' !== trim( (string) $w ) ) )
			: array();
		$count = count( $words );
		if ( $count <= 1 ) {
			return array( mb_substr( $query, 0, self::MAX_QUERY_LENGTH ) );
		}
		$queries = array();
		for ( $n = $count; $n >= 1; --$n ) {
			$queries[] = mb_substr( implode( ' ', array_slice( $words, 0, $n ) ), 0, self::MAX_QUERY_LENGTH );
		}
		return array_values( array_unique( $queries ) );
	}
	private static function search_step( string $query, array $result ): array {
		return array(
			'query'    => $query,
			'posts'    => (int) ( $result['posts'] ?? 0 ),
			'comments' => (int) ( $result['comments'] ?? 0 ),
		);
	}
	private function video_limit(): int {
		$limit = (int) Source_Settings::setting( self::GROUP, 'limit', (string) self::DEFAULT_VIDEO_LIMIT );
		return max( 1, min( self::DEFAULT_VIDEO_LIMIT, $limit ) );
	}
	private function save_thread( array $video, int $num_comments ): array {
		global $wpdb;
		$external_id = (string) ( $video['video_id'] ?? '' );
		if ( '' === $external_id ) {
			return array(
				'id'  => 0,
				'new' => false,
			);
		}
		$existing = $this->thread_id( $external_id );
		if ( $existing > 0 ) {
			return array(
				'id'  => $existing,
				'new' => false,
			);
		}
		$url = (string) ( $video['url'] ?? '' );
		if ( '' === $url ) {
			$url = $this->video_url( $external_id );
		}
		$inserted = $wpdb->insert(
			Activator::table( 'threads' ),
			array(
				'source'       => self::SOURCE,
				'external_id'  => $external_id,
				'community'    => mb_substr( (string) ( $video['channel'] ?? '' ), 0, 64 ),
				'title'        => (string) ( $video['title'] ?? '' ),
				'url'          => $url,
				'permalink'    => $url,
				'score'        => 0,
				'num_comments' => max( 0, $num_comments ),
				'created_utc'  => max( 0, (int) ( $video['published_at'] ?? 0 ) ),
				'collected_at' => (string) current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		if ( false === $inserted ) {
			return array(
				'id'  => 0,
				'new' => false,
			);
		}
		return array(
			'id'  => (int) $wpdb->insert_id,
			'new' => true,
		);
	}
	private function save_comments( string $video_id, int $thread_id, array $comments ): int {
		global $wpdb;
		$saved = 0;
		foreach ( array_slice( $comments, 0, self::MAX_COMMENTS_PER_VIDEO ) as $comment ) {
			$comment_id = (string) ( $comment['comment_id'] ?? '' );
			$body       = trim( (string) ( $comment['body'] ?? '' ) );
			if ( '' === $comment_id || '' === $body ) {
				continue;
			}
			$external_id = $video_id . ':' . $comment_id;
			if ( $this->comment_exists( $external_id ) ) {
				continue;
			}
			$inserted = $wpdb->insert(
				Activator::table( 'comments' ),
				array(
					'source'      => self::SOURCE,
					'external_id' => $external_id,
					'thread_id'   => $thread_id,
					'body'        => $body,
					'score'       => max( 0, (int) ( $comment['like_count'] ?? 0 ) ),
					'author'      => mb_substr( (string) ( $comment['author'] ?? '' ), 0, 64 ),
					'permalink'   => $this->video_url( $video_id ) . '&lc=' . $comment_id,
					'created_utc' => max( 0, (int) ( $comment['published_at'] ?? 0 ) ),
				),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' )
			);
			if ( false !== $inserted ) {
				++$saved;
			}
		}
		return $saved;
	}
	private function video_url( string $video_id ): string {
		return 'https://www.youtube.com/watch?v=' . $video_id;
	}
	private function thread_id( string $external_id ): int {
		global $wpdb;
		$table = Activator::table( 'threads' );
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND external_id = %s", self::SOURCE, $external_id ) );
		return null === $id ? 0 : (int) $id;
	}
	private function comment_exists( string $external_id ): bool {
		global $wpdb;
		$table = Activator::table( 'comments' );
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND external_id = %s", self::SOURCE, $external_id ) );
		return null !== $id;
	}
}

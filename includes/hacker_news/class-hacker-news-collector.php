<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Hacker_News;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Rate_Limit_Exception;
final class Hacker_News_Collector {
	public const SOURCE = 'hackernews';
	private const GROUP = 'hackernews';
	public const DEFAULT_STORY_LIMIT = 5;
	private const MAX_COMMENTS = 50;
	private const MAX_QUERY_LENGTH = 120;
	private const ITEM_URL = 'https://news.ycombinator.com/item?id=';
	private Hacker_News_Client $client;
	public function __construct( ?Hacker_News_Client $client = null ) {
		$this->client = $client ?? new Hacker_News_Client();
	}
	public function is_available(): bool {
		return true;
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
	private static function appended_terms(): array {
		$value = trim( (string) ( Source_Settings::values( self::GROUP )['terms'] ?? '' ) );
		if ( '' === $value ) {
			return array();
		}
		$parts = preg_split( '/[\s,\x{3001}]+/u', $value );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$terms = array();
		foreach ( $parts as $part ) {
			$text = trim( (string) $part );
			if ( '' !== $text ) {
				$terms[] = $text;
			}
		}
		return array_values( array_unique( $terms ) );
	}
	public function collect( array $overrides = array() ): array {
		$result = array(
			'posts'    => 0,
			'comments' => 0,
			'errors'   => array(),
		);
		$name = trim( (string) ( $overrides['product_name'] ?? '' ) );
		if ( '' === $name ) {
			return $result;
		}
		Source_Settings::delete_stale( self::SOURCE );
		$period = Source_Settings::period_seconds( self::GROUP );
		$limit  = $this->story_limit();
		foreach ( self::search_terms( $name ) as $term ) {
			$this->collect_term( $result, $term, $period, $limit );
		}
		return $result;
	}
	private function collect_term( array &$result, string $term, int $period, int $limit ): void {
		if ( '' === $term ) {
			return;
		}
		$threads = array();
		try {
			$stories = $this->client->search_stories( $term, $limit, $period );
		} catch ( Rate_Limit_Exception $e ) {
			$result['errors'][] = $e->getMessage();
			return;
		} catch ( \RuntimeException $e ) {
			$result['errors'][] = $e->getMessage();
			$stories            = array();
		}
		foreach ( $stories as $story ) {
			$story_id = (string) ( $story['story_id'] ?? '' );
			if ( '' === $story_id ) {
				continue;
			}
			$saved = $this->save_thread(
				$story_id,
				(string) ( $story['title'] ?? '' ),
				(string) ( $story['url'] ?? '' ),
				(int) ( $story['points'] ?? 0 ),
				(int) ( $story['num_comments'] ?? 0 ),
				(int) ( $story['created_at'] ?? 0 )
			);
			if ( $saved['id'] <= 0 ) {
				continue;
			}
			if ( $saved['new'] ) {
				++$result['posts'];
			}
			$threads[ $story_id ] = $saved['id'];
		}
		try {
			$comments = $this->client->search_comments( $term, self::MAX_COMMENTS, $period );
		} catch ( Rate_Limit_Exception $e ) {
			$result['errors'][] = $e->getMessage();
			return;
		} catch ( \RuntimeException $e ) {
			$result['errors'][] = $e->getMessage();
			$comments           = array();
		}
		foreach ( $comments as $comment ) {
			$story_id   = (string) ( $comment['story_id'] ?? '' );
			$comment_id = (string) ( $comment['comment_id'] ?? '' );
			if ( '' === $story_id || '' === $comment_id ) {
				continue;
			}
			if ( ! isset( $threads[ $story_id ] ) ) {
				$saved = $this->save_thread(
					$story_id,
					(string) ( $comment['story_title'] ?? '' ),
					(string) ( $comment['story_url'] ?? '' ),
					0,
					0,
					0
				);
				if ( $saved['new'] ) {
					++$result['posts'];
				}
				$threads[ $story_id ] = $saved['id'];
			}
			if ( $threads[ $story_id ] <= 0 ) {
				continue;
			}
			if ( $this->save_comment( $story_id, $threads[ $story_id ], $comment ) ) {
				++$result['comments'];
			}
		}
	}
	private function story_limit(): int {
		$limit = (int) Source_Settings::setting( self::GROUP, 'limit', (string) self::DEFAULT_STORY_LIMIT );
		return max( 1, min( self::DEFAULT_STORY_LIMIT, $limit ) );
	}
	private function save_thread( string $external_id, string $title, string $url, int $score, int $num_comments, int $created_at ): array {
		global $wpdb;
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
		$permalink = self::item_url( $external_id );
		if ( '' === $url ) {
			$url = $permalink;
		}
		$inserted = $wpdb->insert(
			Activator::table( 'threads' ),
			array(
				'source'       => self::SOURCE,
				'external_id'  => $external_id,
				'community'    => '',
				'title'        => $title,
				'url'          => $url,
				'permalink'    => $permalink,
				'score'        => max( 0, $score ),
				'num_comments' => max( 0, $num_comments ),
				'created_utc'  => max( 0, $created_at ),
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
	private function save_comment( string $story_id, int $thread_id, array $comment ): bool {
		global $wpdb;
		$comment_id = (string) ( $comment['comment_id'] ?? '' );
		$body       = trim( (string) ( $comment['body'] ?? '' ) );
		if ( '' === $comment_id || '' === $body ) {
			return false;
		}
		$external_id = $story_id . ':' . $comment_id;
		if ( $this->comment_exists( $external_id ) ) {
			return false;
		}
		$inserted = $wpdb->insert(
			Activator::table( 'comments' ),
			array(
				'source'      => self::SOURCE,
				'external_id' => $external_id,
				'thread_id'   => $thread_id,
				'body'        => $body,
				'score'       => 0,
				'author'      => mb_substr( (string) ( $comment['author'] ?? '' ), 0, 64 ),
				'permalink'   => self::item_url( $comment_id ),
				'created_utc' => max( 0, (int) ( $comment['created_at'] ?? 0 ) ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' )
		);
		return false !== $inserted;
	}
	private static function item_url( string $item_id ): string {
		return self::ITEM_URL . $item_id;
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

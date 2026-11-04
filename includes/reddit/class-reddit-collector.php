<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Reddit;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Reddit_Collector {
	private const GROUP = 'reddit';
	public const SOURCE = 'reddit';
	private const PERIOD_SUFFIX = 'd';
	private const MAX_COMMENT_POSTS = 10;
	private const MAX_COMMENTS_PER_POST = 50;
	public const DEFAULT_LIMIT = 100;
	private Arctic_Shift_Client $client;
	public function __construct( ?Arctic_Shift_Client $client = null ) {
		$this->client = $client ?? new Arctic_Shift_Client();
	}
	public function collect( array $overrides = array() ): array {
		$settings = $this->settings();
		$subreddits = $this->list_from( $overrides['subreddits'] ?? ( $settings['subreddits'] ?? '' ) );
		$keywords   = $this->list_from( $overrides['keywords'] ?? ( $settings['keywords'] ?? '' ) );
		$after      = $this->after( Source_Settings::period_days( self::GROUP ) );
		$selection  = (string) ( $settings['selection'] ?? 'rising' );
		$limit      = $this->limit();
		$result  = array(
			'posts'    => 0,
			'comments' => 0,
			'errors'   => array(),
		);
		$threads = array();
		Source_Settings::delete_stale( self::SOURCE );
		foreach ( $subreddits as $subreddit ) {
			try {
				$posts = $this->client->search_posts( $subreddit, $after, $limit );
			} catch ( Rate_Limit_Exception $e ) {
				$result['errors'][] = sprintf( 'r/%s: %s', $subreddit, $e->getMessage() );
				break;
			} catch ( \RuntimeException $e ) {
				$result['errors'][] = sprintf( 'r/%s: %s', $subreddit, $e->getMessage() );
				continue;
			}
			$posts = $this->filter_keywords( $posts, $keywords );
			$posts = $this->order_posts( $posts, $selection );
			foreach ( $posts as $post ) {
				if ( $this->is_noise_post( $post ) ) {
					continue;
				}
				$saved = $this->save_thread( $post );
				if ( $saved['id'] > 0 ) {
					if ( $saved['new'] ) {
						++$result['posts'];
					}
					$threads[] = array(
						'id'   => $saved['id'],
						'post' => $post,
					);
				}
			}
		}
		usort(
			$threads,
			static fn( array $a, array $b ): int => (int) ( $b['post']['score'] ?? 0 ) <=> (int) ( $a['post']['score'] ?? 0 )
		);
		foreach ( array_slice( $threads, 0, self::MAX_COMMENT_POSTS ) as $thread ) {
			try {
				$result['comments'] += $this->collect_comments( $thread['id'], $thread['post'] );
			} catch ( Rate_Limit_Exception $e ) {
				$result['errors'][] = $e->getMessage();
				break;
			} catch ( \RuntimeException $e ) {
				$result['errors'][] = $e->getMessage();
			}
		}
		return $result;
	}
	private function collect_comments( int $thread_id, array $post ): int {
		$post_id = $this->strip_prefix( (string) ( $post['id'] ?? '' ) );
		if ( '' === $post_id ) {
			return 0;
		}
		$comments  = $this->client->search_comments( $post_id, self::MAX_COMMENTS_PER_POST );
		$subreddit = (string) ( $post['subreddit'] ?? '' );
		$saved     = 0;
		foreach ( $comments as $comment ) {
			if ( $this->is_noise_comment( $comment ) ) {
				continue;
			}
			if ( $this->save_comment( $comment, $thread_id, $subreddit ) ) {
				++$saved;
			}
		}
		return $saved;
	}
	private function settings(): array {
		$values = ( new Settings() )->get( self::GROUP, array() );
		return is_array( $values ) ? $values : array();
	}
	private function list_from( $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$items = preg_split( '/[\s,]+/u', trim( $value ) );
		$items = is_array( $items ) ? $items : array();
		$items = array_filter( $items, static fn( string $item ): bool => '' !== $item );
		return array_values( array_unique( $items ) );
	}
	private function after( int $days ): string {
		return $days > 0 ? $days . self::PERIOD_SUFFIX : '';
	}
	private function limit(): int {
		$limit = (int) Source_Settings::setting( self::GROUP, 'limit', (string) self::DEFAULT_LIMIT );
		return max( 1, min( self::DEFAULT_LIMIT, $limit ) );
	}
	private function filter_keywords( array $posts, array $keywords ): array {
		if ( array() === $keywords ) {
			return $posts;
		}
		$needles = array_map( 'mb_strtolower', $keywords );
		return array_values(
			array_filter(
				$posts,
				static function ( array $post ) use ( $needles ): bool {
					$haystack = mb_strtolower(
						(string) ( $post['title'] ?? '' ) . "\n" . (string) ( $post['selftext'] ?? '' )
					);
					foreach ( $needles as $needle ) {
						if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}
	private function order_posts( array $posts, string $selection ): array {
		$compare = static function ( array $a, array $b ) use ( $selection ): int {
			if ( 'new' === $selection ) {
				return (int) ( $b['created_utc'] ?? 0 ) <=> (int) ( $a['created_utc'] ?? 0 );
			}
			$value_a = (int) ( $a['score'] ?? 0 ) + (int) ( $a['num_comments'] ?? 0 );
			$value_b = (int) ( $b['score'] ?? 0 ) + (int) ( $b['num_comments'] ?? 0 );
			return $value_b <=> $value_a;
		};
		usort( $posts, $compare );
		return $posts;
	}
	private function is_noise_post( array $post ): bool {
		$title = trim( (string) ( $post['title'] ?? '' ) );
		return in_array( $title, array( '', '[removed]', '[deleted]' ), true );
	}
	private function is_noise_comment( array $comment ): bool {
		$body   = trim( (string) ( $comment['body'] ?? '' ) );
		$author = (string) ( $comment['author'] ?? '' );
		if ( in_array( $body, array( '', '[removed]', '[deleted]' ), true ) ) {
			return true;
		}
		return 'AutoModerator' === $author || '[deleted]' === $author;
	}
	private function save_thread( array $post ): array {
		global $wpdb;
		$external_id = $this->strip_prefix( (string) ( $post['id'] ?? '' ) );
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
		$inserted = $wpdb->insert(
			Activator::table( 'threads' ),
			array(
				'source'       => self::SOURCE,
				'external_id'  => $external_id,
				'job_id'       => 0,
				'community'    => mb_substr( (string) ( $post['subreddit'] ?? '' ), 0, 64 ),
				'title'        => (string) ( $post['title'] ?? '' ),
				'url'          => (string) ( $post['url'] ?? '' ),
				'permalink'    => $this->post_permalink( $post ),
				'score'        => (int) ( $post['score'] ?? 0 ),
				'num_comments' => (int) ( $post['num_comments'] ?? 0 ),
				'created_utc'  => (int) ( $post['created_utc'] ?? 0 ),
				'collected_at' => (string) current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
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
	private function save_comment( array $comment, int $thread_id, string $subreddit ): bool {
		global $wpdb;
		$external_id = $this->strip_prefix( (string) ( $comment['id'] ?? '' ) );
		if ( '' === $external_id || $this->comment_exists( $external_id ) ) {
			return false;
		}
		$inserted = $wpdb->insert(
			Activator::table( 'comments' ),
			array(
				'source'      => self::SOURCE,
				'external_id' => $external_id,
				'thread_id'   => $thread_id,
				'body'        => (string) ( $comment['body'] ?? '' ),
				'score'       => (int) ( $comment['score'] ?? 0 ),
				'author'      => mb_substr( (string) ( $comment['author'] ?? '' ), 0, 64 ),
				'permalink'   => $this->comment_permalink( $comment, $subreddit ),
				'created_utc' => (int) ( $comment['created_utc'] ?? 0 ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' )
		);
		return false !== $inserted;
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
	private function post_permalink( array $post ): string {
		$permalink = (string) ( $post['permalink'] ?? '' );
		if ( '' !== $permalink ) {
			return 'https://www.reddit.com' . $permalink;
		}
		return sprintf(
			'https://www.reddit.com/r/%s/comments/%s/',
			(string) ( $post['subreddit'] ?? '' ),
			$this->strip_prefix( (string) ( $post['id'] ?? '' ) )
		);
	}
	private function comment_permalink( array $comment, string $subreddit ): string {
		return sprintf(
			'https://www.reddit.com/r/%s/comments/%s/_/%s/',
			$subreddit,
			$this->strip_prefix( (string) ( $comment['link_id'] ?? '' ) ),
			$this->strip_prefix( (string) ( $comment['id'] ?? '' ) )
		);
	}
	private function strip_prefix( string $id ): string {
		return (string) preg_replace( '/^t\d+_/', '', $id );
	}
}

<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Mastodon;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Rate_Limit_Exception;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Mastodon_Collector {
	public const SOURCE = 'mastodon';
	public const GROUP = 'mastodon';
	public const DEFAULT_INSTANCE = 'mastodon.social';
	public const DEFAULT_LIMIT = 20;
	private const MAX_QUERY_LENGTH = 120;
	private const MAX_LIMIT = 40;
	private Mastodon_Client $client;
	public function __construct( ?Mastodon_Client $client = null ) {
		$this->client = $client ?? new Mastodon_Client();
	}
	public function is_available(): bool {
		return '' !== $this->instance() && '' !== $this->token() && Connection_Status::is_verified( 'mastodon' );
	}
	public function search_terms( string $name ): array {
		$name = trim( $name );
		if ( '' === $name ) {
			return array();
		}
		$terms = $this->appended_terms();
		if ( array() === $terms ) {
			return array( mb_substr( $name, 0, self::MAX_QUERY_LENGTH ) );
		}
		$queries = array();
		foreach ( $terms as $term ) {
			$queries[] = mb_substr( $name . ' ' . $term, 0, self::MAX_QUERY_LENGTH );
		}
		return $queries;
	}
	public function appended_terms(): array {
		$value = trim( (string) ( $this->settings()['terms'] ?? '' ) );
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
	private function settings(): array {
		$values = ( new Settings() )->get( self::GROUP, array() );
		return is_array( $values ) ? $values : array();
	}
	private function setting( string $key, string $default = '' ): string {
		$value = trim( (string) ( $this->settings()[ $key ] ?? '' ) );
		return '' === $value ? $default : $value;
	}
	private function instance(): string {
		return $this->setting( 'instance', self::DEFAULT_INSTANCE );
	}
	private function token(): string {
		return $this->setting( 'token', '' );
	}
	private function fetch_replies(): bool {
		return self::fetches_comments();
	}
	public static function fetches_comments(): bool {
		$values = ( new Settings() )->get( self::GROUP, array() );
		$values = is_array( $values ) ? $values : array();
		return 'on' === trim( (string) ( $values['fetch_replies'] ?? 'off' ) );
	}
	private function limit(): int {
		$limit = (int) $this->setting( 'limit', (string) self::DEFAULT_LIMIT );
		return max( 1, min( self::MAX_LIMIT, $limit ) );
	}
	public function collect( array $overrides = array() ): array {
		$result = array(
			'posts'    => 0,
			'comments' => 0,
			'found'    => 0,
			'errors'   => array(),
		);
		$instance = $this->instance();
		$token    = $this->token();
		$name     = trim( (string) ( $overrides['product_name'] ?? '' ) );
		if ( '' === $instance || '' === $token || '' === $name ) {
			return $result;
		}
		Source_Settings::delete_stale( self::SOURCE );
		$period           = Source_Settings::period_seconds( self::GROUP );
		$cutoff_timestamp = $period > 0 ? time() - $period : 0;
		$limit            = $this->limit();
		$fetch_replies    = $this->fetch_replies();
		$seen = array();
		foreach ( $this->search_terms( $name ) as $query ) {
			try {
				$statuses = $this->client->search_statuses( $instance, $token, $query, $limit );
			} catch ( Rate_Limit_Exception $e ) {
				$result['errors'][] = $e->getMessage();
				break;
			} catch ( \RuntimeException $e ) {
				$result['errors'][] = $e->getMessage();
				continue;
			}
			foreach ( $statuses as $status ) {
				$status_id = (string) ( $status['status_id'] ?? '' );
				if ( '' === $status_id || isset( $seen[ $status_id ] ) ) {
					continue;
				}
				$seen[ $status_id ] = true;
				++$result['found'];
				if ( $cutoff_timestamp > 0 && (int) ( $status['created_at'] ?? 0 ) < $cutoff_timestamp ) {
					continue;
				}
				$saved = $this->save_thread( $status );
				if ( $saved['id'] <= 0 ) {
					continue;
				}
				if ( $saved['new'] ) {
					++$result['posts'];
				}
				if ( ! $fetch_replies ) {
					continue;
				}
				try {
					$replies = $this->client->status_context( $instance, $token, $status_id );
				} catch ( Rate_Limit_Exception $e ) {
					$result['errors'][] = $e->getMessage();
					return $result;
				} catch ( \RuntimeException $e ) {
					$result['errors'][] = $e->getMessage();
					continue;
				}
				foreach ( $replies as $reply ) {
					if ( $this->save_comment( $status_id, $saved['id'], $reply ) ) {
						++$result['comments'];
					}
				}
			}
		}
		return $result;
	}
	private function save_thread( array $status ): array {
		global $wpdb;
		$external_id = (string) ( $status['status_id'] ?? '' );
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
		$url = (string) ( $status['url'] ?? '' );
		$inserted = $wpdb->insert(
			Activator::table( 'threads' ),
			array(
				'source'       => self::SOURCE,
				'external_id'  => $external_id,
				'community'    => mb_substr( $this->instance(), 0, 64 ),
				'title'        => (string) ( $status['content'] ?? '' ),
				'url'          => $url,
				'permalink'    => $url,
				'score'        => max( 0, (int) ( $status['favourites'] ?? 0 ) ),
				'num_comments' => max( 0, (int) ( $status['replies'] ?? 0 ) ),
				'created_utc'  => max( 0, (int) ( $status['created_at'] ?? 0 ) ),
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
	private function save_comment( string $status_id, int $thread_id, array $reply ): bool {
		global $wpdb;
		$reply_id = (string) ( $reply['reply_id'] ?? '' );
		$body     = trim( (string) ( $reply['content'] ?? '' ) );
		if ( '' === $reply_id || '' === $body ) {
			return false;
		}
		$external_id = mb_substr( $status_id . ':' . $reply_id, 0, 64 );
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
				'score'       => max( 0, (int) ( $reply['favourites'] ?? 0 ) ),
				'author'      => mb_substr( (string) ( $reply['author'] ?? '' ), 0, 64 ),
				'permalink'   => (string) ( $reply['url'] ?? '' ),
				'created_utc' => max( 0, (int) ( $reply['created_at'] ?? 0 ) ),
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
}

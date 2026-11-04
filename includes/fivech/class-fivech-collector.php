<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Fivech;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Rate_Limit_Exception;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Fivech_Collector {
	private const GROUP = 'fivech';
	public const SOURCE = 'fivech';
	private const MAX_CANDIDATE_THREADS = 20;
	private const MAX_DAT_THREADS = 6;
	public const DEFAULT_RES_LIMIT = 50;
	private Fivech_Client $client;
	public function __construct( ?Fivech_Client $client = null ) {
		$this->client = $client ?? new Fivech_Client();
	}
	public function collect( array $overrides = array() ): array {
		$settings = $this->settings();
		$boards   = $this->list_from( $overrides['boards'] ?? ( $settings['boards'] ?? '' ) );
		$keywords = $this->list_from( $overrides['keywords'] ?? ( $settings['keywords'] ?? '' ) );
		$result = array(
			'posts'    => 0,
			'comments' => 0,
			'errors'   => array(),
		);
		if ( array() === $boards ) {
			return $result;
		}
		Source_Settings::delete_stale( self::SOURCE );
		$period_seconds = Source_Settings::period_seconds( self::GROUP );
		foreach ( $boards as $board ) {
			try {
				$this->collect_board( $result, $board, $keywords, $period_seconds );
			} catch ( Rate_Limit_Exception $e ) {
				$result['errors'][] = $e->getMessage();
				break;
			}
		}
		return $result;
	}
	private function collect_board( array &$result, string $board, array $keywords, int $period_seconds ): void {
		try {
			$candidates = $this->client->threads( $board );
		} catch ( Rate_Limit_Exception $e ) {
			throw $e;
		} catch ( \RuntimeException $e ) {
			$result['errors'][] = sprintf( '%s: %s', $board, $e->getMessage() );
			return;
		}
		$candidates = $this->filter_keywords( $candidates, $keywords );
		$candidates = array_slice( $candidates, 0, self::MAX_CANDIDATE_THREADS );
		$fetched = 0;
		foreach ( $candidates as $candidate ) {
			if ( $fetched >= self::MAX_DAT_THREADS ) {
				break;
			}
			try {
				$res = $this->client->dat( $board, (string) $candidate['id'] );
			} catch ( Rate_Limit_Exception $e ) {
				throw $e;
			} catch ( \RuntimeException $e ) {
				$result['errors'][] = sprintf( '%s/%s: %s', $board, (string) $candidate['id'], $e->getMessage() );
				continue;
			}
			++$fetched;
			if ( ! $this->within_period( $res, $period_seconds ) ) {
				continue;
			}
			$saved = $this->save_thread( $board, $candidate, $res );
			if ( $saved['id'] <= 0 ) {
				continue;
			}
			if ( $saved['new'] ) {
				++$result['posts'];
			}
			$result['comments'] += $this->save_res( $board, (string) $candidate['id'], $saved['id'], $res );
		}
	}
	private function within_period( array $res, int $period_seconds ): bool {
		$latest = 0;
		foreach ( $res as $item ) {
			$latest = max( $latest, (int) ( $item['posted_at'] ?? 0 ) );
		}
		if ( $latest <= 0 ) {
			return true;
		}
		return $period_seconds <= 0 || $latest >= ( time() - $period_seconds );
	}
	private function res_limit(): int {
		$limit = (int) Source_Settings::setting( self::GROUP, 'limit', (string) self::DEFAULT_RES_LIMIT );
		return max( 1, min( self::DEFAULT_RES_LIMIT, $limit ) );
	}
	private function filter_keywords( array $threads, array $keywords ): array {
		if ( array() === $keywords ) {
			return $threads;
		}
		$needles = array_map( 'mb_strtolower', $keywords );
		return array_values(
			array_filter(
				$threads,
				static function ( array $thread ) use ( $needles ): bool {
					$haystack = mb_strtolower( (string) ( $thread['title'] ?? '' ) );
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
	private function save_thread( string $board, array $thread, array $res ): array {
		global $wpdb;
		$thread_id = (string) ( $thread['id'] ?? '' );
		if ( '' === $thread_id ) {
			return array(
				'id'  => 0,
				'new' => false,
			);
		}
		$external_id = $board . ':' . $thread_id;
		$existing    = $this->thread_id( $external_id );
		if ( $existing > 0 ) {
			return array(
				'id'  => $existing,
				'new' => false,
			);
		}
		$url = $this->thread_url( $board, $thread_id );
		$inserted = $wpdb->insert(
			Activator::table( 'threads' ),
			array(
				'source'       => self::SOURCE,
				'external_id'  => $external_id,
				'job_id'       => 0,
				'community'    => mb_substr( $board, 0, 64 ),
				'title'        => (string) ( $thread['title'] ?? '' ),
				'url'          => $url,
				'permalink'    => $url,
				'score'        => 0,
				'num_comments' => (int) ( $thread['count'] ?? 0 ),
				'created_utc'  => $this->first_posted_at( $res ),
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
	private function save_res( string $board, string $thread_id, int $internal_id, array $res ): int {
		global $wpdb;
		$permalink = $this->thread_url( $board, $thread_id );
		$saved     = 0;
		foreach ( array_slice( $res, 0, $this->res_limit() ) as $item ) {
			$no   = (int) ( $item['no'] ?? 0 );
			$body = trim( (string) ( $item['body'] ?? '' ) );
			if ( $no <= 0 || '' === $body ) {
				continue;
			}
			$external_id = $board . ':' . $thread_id . ':' . $no;
			if ( $this->comment_exists( $external_id ) ) {
				continue;
			}
			$inserted = $wpdb->insert(
				Activator::table( 'comments' ),
				array(
					'source'      => self::SOURCE,
					'external_id' => $external_id,
					'thread_id'   => $internal_id,
					'body'        => $body,
					'score'       => 0,
					'author'      => mb_substr( (string) ( $item['name'] ?? '' ), 0, 64 ),
					'permalink'   => $permalink . $no,
					'created_utc' => (int) ( $item['posted_at'] ?? 0 ),
				),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' )
			);
			if ( false !== $inserted ) {
				++$saved;
			}
		}
		return $saved;
	}
	private function thread_url( string $board, string $thread_id ): string {
		try {
			$base = $this->client->board_url( $board );
		} catch ( \Throwable $e ) {
			return '';
		}
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = (string) ( $parts['scheme'] ?? 'https' );
		return sprintf( '%s://%s/test/read.cgi/%s/%s/', $scheme, $parts['host'], $board, $thread_id );
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
	private function first_posted_at( array $res ): int {
		foreach ( $res as $item ) {
			$timestamp = (int) ( $item['posted_at'] ?? 0 );
			if ( $timestamp > 0 ) {
				return $timestamp;
			}
		}
		return 0;
	}
}

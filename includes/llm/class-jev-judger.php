<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Jev_Judger {
	public const DEFAULT_LIMIT = 300;
	private const TIME_LIMIT = 300;
	private const MAX_FAILURES = 3;
	private const MAX_RETRIES = 1;
	private const RETRY_WAIT_SECONDS = 2;
	private const MAX_COMMENTS = 10;
	private const MAX_STATE_TEXT = 2000;
	private const SCHEMA_VERSION = 1;
	public const REASON_NO_KEY = 'no_key';
	public const REASON_FAILED = 'failed';
	private string $api_key;
	private string $model;
	private Jev_Client $client;
	private int $wait_seconds = self::RETRY_WAIT_SECONDS;
	public function __construct( string $api_key, string $model = '', ?Jev_Client $client = null ) {
		$this->api_key = trim( $api_key );
		$this->model   = trim( $model );
		$this->client  = $client ?? new Jev_Client( $this->api_key, $this->model );
	}
	public static function from_settings( ?Settings $settings = null ): self {
		$settings = $settings ?? new Settings();
		$jev      = $settings->jev();
		return new self( (string) ( $jev['api_key'] ?? '' ), (string) ( $jev['model'] ?? '' ) );
	}
	public function judge_pending( array $sources, string $since, int $limit = self::DEFAULT_LIMIT ): array {
		$result = array(
			'attempted' => 0,
			'adopt'     => 0,
			'hold'      => 0,
			'exclude'   => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'capped'    => false,
			'reason'    => '',
		);
		if ( ! Status::capabilities()->can_judge_with_jev() ) {
			return $result;
		}
		$sources = self::source_ids( $sources );
		if ( array() === $sources ) {
			return $result;
		}
		if ( '' === $this->api_key ) {
			$result['reason'] = self::REASON_NO_KEY;
			$this->record( $sources, $result, 0 );
			return $result;
		}
		if ( ! Connection_Status::is_verified( 'jev' ) ) {
			$result['reason'] = self::REASON_NO_KEY;
			$this->record( $sources, $result, 0 );
			return $result;
		}
		$limit = max( 1, $limit );
		$rows  = $this->pending_rows( $sources, $since, $limit + 1 );
		if ( count( $rows ) > $limit ) {
			$rows             = array_slice( $rows, 0, $limit );
			$result['capped'] = true;
		}
		if ( array() === $rows ) {
			return $result;
		}
		$started = microtime( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_LIMIT );
		}
		$judged_at = (string) current_time( 'mysql' );
		foreach ( $rows as $row ) {
			if ( $result['failed'] >= self::MAX_FAILURES ) {
				$result['reason'] = self::REASON_FAILED;
				break;
			}
			$state = $this->state( $row );
			if ( '' === $state ) {
				++$result['skipped'];
				continue;
			}
			++$result['attempted'];
			try {
				$judgement = $this->judge( $state );
			} catch ( \Throwable $e ) {
				++$result['failed'];
				continue;
			}
			$source  = (string) ( $row['source'] ?? '' );
			$verdict = Jev_Questions::verdict_for( $judgement['answers'], $source );
			if ( '' === $verdict ) {
				++$result['failed'];
				continue;
			}
			if ( ! $this->save( (int) ( $row['id'] ?? 0 ), $judged_at, $judgement, $verdict, $source ) ) {
				++$result['failed'];
				continue;
			}
			++$result[ $verdict ];
		}
		$this->record( $sources, $result, self::elapsed_ms( $started ) );
		return $result;
	}
	private function pending_rows( array $sources, string $since, int $limit ): array {
		global $wpdb;
		$table        = Activator::table( 'threads' );
		$placeholders = implode( ', ', array_fill( 0, count( $sources ), '%s' ) );
		$args         = array_merge( array( $since ), $sources, array( $limit ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source, external_id, title, product_name FROM {$table} WHERE judge IS NULL AND collected_at >= %s AND source IN ({$placeholders}) ORDER BY collected_at DESC, id DESC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}
	private function state( array $row ): string {
		$parts   = array();
		$product = trim( (string) ( $row['product_name'] ?? '' ) );
		$title   = trim( (string) ( $row['title'] ?? '' ) );
		if ( '' !== $product ) {
			$parts[] = '商品名: ' . $product;
		}
		if ( '' !== $title ) {
			$parts[] = 'タイトル: ' . $title;
		}
		$body = $this->comments_text( (int) ( $row['id'] ?? 0 ) );
		if ( '' !== $body ) {
			$parts[] = '本文: ' . $body;
		}
		if ( '' === $title && '' === $body ) {
			return '';
		}
		return implode( "\n", $parts );
	}
	private function comments_text( int $thread_id ): string {
		global $wpdb;
		if ( $thread_id <= 0 ) {
			return '';
		}
		$table = Activator::table( 'comments' );
		$bodies = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT body FROM {$table} WHERE thread_id = %d ORDER BY score DESC LIMIT %d",
				$thread_id,
				self::MAX_COMMENTS
			)
		);
		$bodies = is_array( $bodies ) ? $bodies : array();
		$text   = implode( "\n\n", array_filter( array_map( 'trim', array_map( 'strval', $bodies ) ), 'strlen' ) );
		if ( mb_strlen( $text ) > self::MAX_STATE_TEXT ) {
			$text = mb_substr( $text, 0, self::MAX_STATE_TEXT ) . '…';
		}
		return $text;
	}
	private function judge( string $state ): array {
		$attempt = 0;
		while ( true ) {
			try {
				return $this->client->judge( $state, Jev_Questions::set_v4() );
			} catch ( Provider_Exception $e ) {
				if ( ! $e->is_retryable() || $attempt >= self::MAX_RETRIES ) {
					throw $e;
				}
				++$attempt;
				if ( $this->wait_seconds > 0 ) {
					sleep( $this->wait_seconds );
				}
			}
		}
	}
	private function save( int $thread_id, string $judged_at, array $judgement, string $verdict, string $source ): bool {
		global $wpdb;
		if ( $thread_id <= 0 ) {
			return false;
		}
		$encoded = wp_json_encode(
			array(
				'v'          => self::SCHEMA_VERSION,
				'set'        => Jev_Questions::SET_V4,
				'model'      => (string) ( $judgement['model'] ?? '' ),
				'judged_at'  => $judged_at,
				'thresholds' => Jev_Questions::thresholds_for( $source ),
				'verdict'    => $verdict,
				'answers'    => (array) ( $judgement['answers'] ?? array() ),
			)
		);
		if ( false === $encoded ) {
			return false;
		}
		$updated = $wpdb->update(
			Activator::table( 'threads' ),
			array( 'judge' => $encoded ),
			array( 'id' => $thread_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $updated;
	}
	private function record( array $sources, array $result, int $elapsed_ms ): void {
		$payload = array(
			'sources' => $sources,
			'judge'   => $result,
		);
		if ( self::REASON_NO_KEY === (string) ( $result['reason'] ?? '' ) ) {
			Event_Logger::record(
				Event_Logger::TYPE_COLLECT,
				Event_Logger::LEVEL_WARN,
				__( 'Jev の API キーが未設定のため、レビューの判定を行いませんでした（判定なし＝現行どおり採用します）。「AI設定」タブで保存してください。', GACHIREAI_TEXT_DOMAIN ),
				$payload
			);
			return;
		}
		$attempted = (int) ( $result['attempted'] ?? 0 );
		$skipped   = (int) ( $result['skipped'] ?? 0 );
		$capped    = ! empty( $result['capped'] );
		if ( 0 === $attempted && 0 === $skipped && ! $capped ) {
			return;
		}
		$pending = (int) ( $result['failed'] ?? 0 ) + $skipped;
		$message = sprintf(
			__( 'レビューの判定を行いました（対象 %1$d 件 / 採用 %2$d・保留 %3$d・除外 %4$d / 未判定 %5$d）。', GACHIREAI_TEXT_DOMAIN ),
			$attempted,
			(int) ( $result['adopt'] ?? 0 ),
			(int) ( $result['hold'] ?? 0 ),
			(int) ( $result['exclude'] ?? 0 ),
			$pending
		);
		if ( $capped ) {
			$message .= __( '（1 回の上限に達したため打ち切りました。残りは判定なし＝採用側になります）', GACHIREAI_TEXT_DOMAIN );
		}
		Event_Logger::record(
			Event_Logger::TYPE_COLLECT,
			( 0 < $pending || $capped ) ? Event_Logger::LEVEL_WARN : Event_Logger::LEVEL_INFO,
			$message,
			$payload,
			0,
			$elapsed_ms
		);
	}
	private static function source_ids( array $sources ): array {
		$ids = array();
		foreach ( $sources as $source ) {
			$id = trim( (string) $source );
			if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
	private static function elapsed_ms( float $started ): int {
		return max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}
}

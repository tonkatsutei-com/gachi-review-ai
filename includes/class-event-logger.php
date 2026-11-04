<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
final class Event_Logger {
	public const TYPE_SELECT = 'select';
	public const TYPE_COLLECT = 'collect';
	public const TYPE_GENERATE = 'generate';
	public const TYPE_SYSTEM = 'system';
	public const LEVEL_INFO = 'info';
	public const LEVEL_WARN = 'warn';
	public const LEVEL_ERROR = 'error';
	private const RETENTION_DAYS = 30;
	private const PAGE_SIZE = 100;
	private const TYPE_LABELS = array(
		self::TYPE_SELECT   => '商品選定',
		self::TYPE_COLLECT  => 'レビュー取得',
		self::TYPE_GENERATE => '記事生成',
		self::TYPE_SYSTEM   => 'システム',
	);
	private const LEVEL_LABELS = array(
		self::LEVEL_INFO  => '情報',
		self::LEVEL_WARN  => '警告',
		self::LEVEL_ERROR => 'エラー',
	);
	public static function record( string $type, string $level, string $message, array $payload = array(), int $post_id = 0, int $elapsed_ms = 0 ): void {
		try {
			global $wpdb;
			if ( ! is_object( $wpdb ) ) {
				return;
			}
			$encoded = array() === $payload ? null : wp_json_encode( $payload );
			if ( false === $encoded ) {
				$encoded = null;
			}
			$wpdb->insert(
				self::table(),
				array(
					'job_id'     => 0,
					'type'       => self::valid_type( $type ),
					'level'      => self::valid_level( $level ),
					'message'    => $message,
					'payload'    => $encoded,
					'post_id'    => max( 0, $post_id ),
					'user_id'    => self::current_user_id(),
					'elapsed_ms' => max( 0, $elapsed_ms ),
					'created_at' => self::now(),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
			);
			self::prune();
		} catch ( \Throwable $e ) {
		}
	}
	public static function latest( int $limit = self::PAGE_SIZE, int $offset = 0 ): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return array();
		}
		$table = self::table();
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, level, message, payload, post_id, user_id, elapsed_ms, created_at
				 FROM {$table}
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}
	public static function display( int $limit = self::PAGE_SIZE, int $offset = 0 ): array {
		$logs = array();
		foreach ( self::latest( $limit, $offset ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$level = self::valid_level( (string) ( $row['level'] ?? '' ) );
			$type  = self::valid_type( (string) ( $row['type'] ?? '' ) );
			$logs[] = array(
				'log_time'        => esc_html( self::format_time( (string) ( $row['created_at'] ?? '' ) ) ),
				'log_item_class'  => esc_attr( 'gachireai-log gachireai-log--' . $level ),
				'log_badge_class' => esc_attr( 'gachireai-log__badge gachireai-log__badge--' . $level ),
				'log_level_label' => esc_html( self::LEVEL_LABELS[ $level ] ),
				'log_type_label'  => esc_html( self::TYPE_LABELS[ $type ] ),
				'log_text'        => esc_html( (string) ( $row['message'] ?? '' ) ),
			);
		}
		return $logs;
	}
	public static function type_label( string $type ): string {
		return self::TYPE_LABELS[ self::valid_type( $type ) ];
	}
	public static function level_label( string $level ): string {
		return self::LEVEL_LABELS[ self::valid_level( $level ) ];
	}
	private static function valid_type( string $type ): string {
		return isset( self::TYPE_LABELS[ $type ] ) ? $type : self::TYPE_SYSTEM;
	}
	private static function valid_level( string $level ): string {
		return isset( self::LEVEL_LABELS[ $level ] ) ? $level : self::LEVEL_INFO;
	}
	private static function format_time( string $created_at ): string {
		return '' === $created_at ? '' : str_replace( '-', '/', substr( $created_at, 0, 16 ) );
	}
	private static function now(): string {
		return function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
	private static function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}
	private static function table(): string {
		global $wpdb;
		return ( is_object( $wpdb ) ? (string) $wpdb->prefix : '' ) . 'gachireai_events';
	}
	public static function count(): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return 0;
		}
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
	public static function page_size(): int {
		return self::PAGE_SIZE;
	}
	private static function prune(): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return;
		}
		$threshold = date( 'Y-m-d H:i:s', strtotime( self::now() ) - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$table     = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $threshold ) );
	}
}

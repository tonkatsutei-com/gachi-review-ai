<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
final class Cron_Schedule {
	public const KEY = 'cron_schedule';
	public const MIN_INTERVAL_MINUTES = 60;
	public const MINUTES_PER_DAY = 1440;
	private const MINUTE_MAX = 59;
	private const HOUR_MAX = 23;
	public static function display_value( string $minute, string $hour ): string {
		return $minute . '|' . $hour;
	}
	public static function parts( string $value ): array {
		if ( str_contains( $value, '|' ) ) {
			$split = explode( '|', $value, 2 );
			return array(
				'minute' => $split[0],
				'hour'   => $split[1] ?? '',
			);
		}
		$fields = self::split_fields( $value );
		return array(
			'minute' => $fields[0] ?? '',
			'hour'   => $fields[1] ?? '',
		);
	}
	public static function parse( $raw ): array {
		$input  = self::input_parts( $raw );
		$minute = self::normalize_field( $input['minute'] );
		$hour   = self::normalize_field( $input['hour'] );
		if ( '' === $minute && '' === $hour ) {
			return array(
				'schedule' => '',
				'errors'   => array(),
			);
		}
		if ( '' === $minute || '' === $hour ) {
			return array(
				'schedule' => '',
				'errors'   => array(
					__( '自動記事生成時刻は「分」と「時」の両方を入力してください（例: 分=30、時=12）。', GACHIREAI_TEXT_DOMAIN ),
				),
			);
		}
		$minutes = self::expand_field( $minute, self::MINUTE_MAX );
		if ( null === $minutes ) {
			return array(
				'schedule' => '',
				'errors'   => array(
					__( '「分」の形式が正しくありません。0〜59 の整数を 1 つ入力してください。', GACHIREAI_TEXT_DOMAIN ),
				),
			);
		}
		$hours = self::expand_field( $hour, self::HOUR_MAX );
		if ( null === $hours ) {
			return array(
				'schedule' => '',
				'errors'   => array(
					__( '「時」の形式が正しくありません。0〜23 の数値・範囲（例: 9-18）・間隔（例: */2）・カンマ区切り（例: 9,12,18）で入力してください。', GACHIREAI_TEXT_DOMAIN ),
				),
			);
		}
		$errors = self::validate_interval( self::expand_times( $minutes, $hours ) );
		if ( array() !== $errors ) {
			return array(
				'schedule' => '',
				'errors'   => $errors,
			);
		}
		return array(
			'schedule' => $minute . ' ' . $hour . ' * * *',
			'errors'   => array(),
		);
	}
	public static function times( string $schedule ): array {
		$fields = self::split_fields( $schedule );
		if ( count( $fields ) < 2 ) {
			return array();
		}
		$minutes = self::expand_field( $fields[0], self::MINUTE_MAX );
		$hours   = self::expand_field( $fields[1], self::HOUR_MAX );
		if ( null === $minutes || null === $hours ) {
			return array();
		}
		return self::expand_times( $minutes, $hours );
	}
	private static function input_parts( $raw ): array {
		if ( is_scalar( $raw ) ) {
			$fields = self::split_fields( (string) $raw );
			return array(
				'minute' => $fields[0] ?? '',
				'hour'   => $fields[1] ?? '',
			);
		}
		$array = (array) $raw;
		return array(
			'minute' => isset( $array['minute'] ) && is_scalar( $array['minute'] ) ? (string) $array['minute'] : '',
			'hour'   => isset( $array['hour'] ) && is_scalar( $array['hour'] ) ? (string) $array['hour'] : '',
		);
	}
	private static function split_fields( string $schedule ): array {
		$fields = preg_split( '/\s+/u', trim( $schedule ) ) ?: array();
		return array_values(
			array_filter(
				$fields,
				static function ( $field ): bool {
					return '' !== $field;
				}
			)
		);
	}
	private static function normalize_field( string $field ): string {
		$field = mb_convert_kana( $field, 'as', 'UTF-8' );
		$field = str_replace(
			array( '、', '，', '－', '−', '‐', 'ー', '～', '〜', '＊', '／' ),
			array( ',', ',', '-', '-', '-', '-', '-', '-', '*', '/' ),
			$field
		);
		return (string) preg_replace( '/\s+/u', '', $field );
	}
	private static function expand_field( string $field, int $max ): ?array {
		if ( '' === $field ) {
			return null;
		}
		$values = array();
		foreach ( explode( ',', $field ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				return null;
			}
			$step = 1;
			if ( str_contains( $item, '/' ) ) {
				$parts = explode( '/', $item );
				if ( 2 !== count( $parts ) || 1 !== preg_match( '/^\d+$/', $parts[1] ) ) {
					return null;
				}
				$step = (int) $parts[1];
				if ( $step < 1 ) {
					return null;
				}
				$item = $parts[0];
			}
			if ( '*' === $item ) {
				$from = 0;
				$to   = $max;
			} elseif ( 1 === preg_match( '/^(\d+)-(\d+)$/', $item, $matches ) ) {
				$from = (int) $matches[1];
				$to   = (int) $matches[2];
			} elseif ( 1 === preg_match( '/^\d+$/', $item ) ) {
				$from = (int) $item;
				$to   = (int) $item;
			} else {
				return null;
			}
			if ( $from < 0 || $to > $max || $from > $to ) {
				return null;
			}
			for ( $value = $from; $value <= $to; $value += $step ) {
				$values[ $value ] = true;
			}
		}
		$result = array_keys( $values );
		sort( $result, SORT_NUMERIC );
		return $result;
	}
	private static function expand_times( array $minutes, array $hours ): array {
		$times = array();
		foreach ( $hours as $hour ) {
			foreach ( $minutes as $minute ) {
				$times[] = sprintf( '%02d:%02d', $hour, $minute );
			}
		}
		$times = array_values( array_unique( $times ) );
		sort( $times, SORT_STRING );
		return $times;
	}
	private static function validate_interval( array $times ): array {
		$count = count( $times );
		if ( $count < 2 ) {
			return array();
		}
		$minutes = array();
		foreach ( $times as $time ) {
			$minutes[] = self::to_minutes( $time );
		}
		for ( $index = 1; $index < $count; $index++ ) {
			if ( $minutes[ $index ] - $minutes[ $index - 1 ] < self::MIN_INTERVAL_MINUTES ) {
				return array( self::interval_message() );
			}
		}
		$wrap = ( self::MINUTES_PER_DAY - $minutes[ $count - 1 ] ) + $minutes[0];
		if ( $wrap < self::MIN_INTERVAL_MINUTES ) {
			return array( self::interval_message() );
		}
		return array();
	}
	private static function interval_message(): string {
		return __( '投稿時刻は最低 1 時間以上空けてください。', GACHIREAI_TEXT_DOMAIN );
	}
	private static function to_minutes( string $time ): int {
		$parts = array_map( 'intval', explode( ':', $time ) );
		return ( (int) ( $parts[0] ?? 0 ) * 60 ) + (int) ( $parts[1] ?? 0 );
	}
}

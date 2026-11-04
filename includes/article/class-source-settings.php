<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Ec\Ec_Review_Collector;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Source_Settings {
	public const GROUP = 'source_common';
	public const DEFAULT_PERIOD_DAYS = 30;
	public const DEFAULT_RETENTION_DAYS = 30;
	public const DEFAULT_CREDIT = 'quote';
	public const PERIOD_VALUES = array( '30', '90', '180', '365', '0' );
	public const RETENTION_VALUES = array( '30', '90', '180', '365' );
	public const CREDIT_VALUES = array( 'on', 'quote', 'off' );
	public const PARTNER_GACHI_KEY = 'gachi';
	public const DIRECT_SOURCES = array( 'fivech', 'reddit', 'youtube', 'hackernews', 'mastodon' );
	public const PARTNER_GROUP = 'partner';
	public const PARTNER_EC_KEY = 'ec';
	public const PARTNER_SNS_KEY = 'sns';
	public const PARTNER_SNS = array( 'x', 'instagram', 'facebook' );
	private const SWITCH_ON = '1';
	private const SWITCH_OFF = '0';
	public static function period_options(): array {
		return array(
			'30'  => __( '直近 30 日', GACHIREAI_TEXT_DOMAIN ),
			'90'  => __( '直近 3 ヶ月', GACHIREAI_TEXT_DOMAIN ),
			'180' => __( '直近 6 ヶ月', GACHIREAI_TEXT_DOMAIN ),
			'365' => __( '直近 1 年', GACHIREAI_TEXT_DOMAIN ),
			'0'   => __( '全期間', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function retention_options(): array {
		return array(
			'30'  => __( '30 日', GACHIREAI_TEXT_DOMAIN ),
			'90'  => __( '3 ヶ月', GACHIREAI_TEXT_DOMAIN ),
			'180' => __( '6 ヶ月', GACHIREAI_TEXT_DOMAIN ),
			'365' => __( '1 年', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function credit_options(): array {
		return array(
			'on'    => __( '表示する', GACHIREAI_TEXT_DOMAIN ),
			'quote' => __( '引用ブロックのみ表示', GACHIREAI_TEXT_DOMAIN ),
			'off'   => __( '表示しない', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function direct_source_options(): array {
		$options = array();
		foreach ( self::DIRECT_SOURCES as $source ) {
			$options[ $source ] = self::source_label( $source );
		}
		return $options;
	}
	public static function partner_sns_options(): array {
		$options = array();
		foreach ( self::PARTNER_SNS as $sns ) {
			$options[ $sns ] = self::source_label( $sns );
		}
		return $options;
	}
	public static function source_label( string $source ): string {
		$labels = array(
			'x'          => 'X',
			'instagram'  => 'Instagram',
			'facebook'   => 'Facebook',
			'youtube'    => __( 'YouTube', GACHIREAI_TEXT_DOMAIN ),
			'reddit'     => __( 'Reddit', GACHIREAI_TEXT_DOMAIN ),
			'fivech'     => __( '5ちゃんねる', GACHIREAI_TEXT_DOMAIN ),
			'hackernews' => __( 'Hacker News', GACHIREAI_TEXT_DOMAIN ),
			'mastodon'   => __( 'Mastodon', GACHIREAI_TEXT_DOMAIN ),
		);
		return $labels[ $source ] ?? $source;
	}
	public static function is_sns( string $source ): bool {
		return in_array( $source, self::PARTNER_SNS, true );
	}
	public static function switch_off_reason( string $source ): string {
		if ( self::PARTNER_EC_KEY === $source ) {
			return __( '「レビュー設定」タブの「レビュー取得元」で「メインEC」にチェックを入れてください。', GACHIREAI_TEXT_DOMAIN );
		}
		return sprintf(
			__( '「レビュー設定」タブの「レビュー取得元」で「%s」にチェックを入れてください。', GACHIREAI_TEXT_DOMAIN ),
			self::source_label( $source )
		);
	}
	public static function normalize_enabled( $value ): string {
		return self::SWITCH_ON === (string) $value ? self::SWITCH_ON : self::SWITCH_OFF;
	}
	public static function gachi_sources(): array {
		$value  = (string) ( self::values( self::PARTNER_GROUP )[ self::PARTNER_GACHI_KEY ] ?? '' );
		$stored = array_map( 'trim', explode( ',', $value ) );
		$found  = array();
		foreach ( self::DIRECT_SOURCES as $source ) {
			if ( in_array( $source, $stored, true ) ) {
				$found[] = $source;
			}
		}
		return $found;
	}
	public static function any_source_enabled(): bool {
		return self::is_partner_enabled( self::PARTNER_EC_KEY )
			|| array() !== self::partner_sns_ids()
			|| array() !== self::gachi_sources();
	}
	public static function is_partner_enabled( string $site ): bool {
		if ( self::PARTNER_EC_KEY === $site ) {
			return self::SWITCH_ON === trim( (string) ( self::values( self::PARTNER_GROUP )[ self::PARTNER_EC_KEY ] ?? '' ) );
		}
		return in_array( $site, self::partner_sns_ids(), true );
	}
	public static function partner_sns_ids(): array {
		$value = (string) ( self::values( self::PARTNER_GROUP )[ self::PARTNER_SNS_KEY ] ?? '' );
		$ids   = array();
		foreach ( array_map( 'trim', explode( ',', $value ) ) as $id ) {
			if ( in_array( $id, self::PARTNER_SNS, true ) && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
	public static function can_collect( string $source ): bool {
		if ( self::PARTNER_EC_KEY === $source || in_array( $source, self::PARTNER_SNS, true ) ) {
			return self::is_partner_enabled( $source );
		}
		return in_array( $source, self::gachi_sources(), true );
	}
	public static function collectable( array $sources ): array {
		$collectable = array();
		foreach ( $sources as $source ) {
			if ( self::can_collect( (string) $source ) ) {
				$collectable[] = (string) $source;
			}
		}
		return $collectable;
	}
	public static function values( string $group ): array {
		$values = ( new Settings() )->get( $group, array() );
		return is_array( $values ) ? $values : array();
	}
	public static function common_values(): array {
		return self::values( self::GROUP );
	}
	public static function setting( string $group, string $key, string $default = '' ): string {
		$value = trim( (string) ( self::values( $group )[ $key ] ?? '' ) );
		return '' === $value ? $default : $value;
	}
	public static function common_setting( string $key, string $default = '' ): string {
		$value = trim( (string) ( self::common_values()[ $key ] ?? '' ) );
		return '' === $value ? $default : $value;
	}
	public static function period_days( string $group ): int {
		$value = self::setting( $group, 'period', (string) self::DEFAULT_PERIOD_DAYS );
		return in_array( $value, self::PERIOD_VALUES, true ) ? (int) $value : self::DEFAULT_PERIOD_DAYS;
	}
	public static function period_seconds( string $group ): int {
		$days = self::period_days( $group );
		return $days > 0 ? $days * 86400 : 0;
	}
	public static function retention_days(): int {
		$value = self::common_setting( 'retention', (string) self::DEFAULT_RETENTION_DAYS );
		return in_array( $value, self::RETENTION_VALUES, true ) ? (int) $value : self::DEFAULT_RETENTION_DAYS;
	}
	public static function credit_mode(): string {
		$value = self::common_setting( 'credit', self::DEFAULT_CREDIT );
		return in_array( $value, self::CREDIT_VALUES, true ) ? $value : self::DEFAULT_CREDIT;
	}
	public static function delete_stale( string $source ): int {
		$days = self::retention_days();
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$threads  = Activator::table( 'threads' );
		$comments = Activator::table( 'comments' );
		$cutoff   = wp_date( 'Y-m-d H:i:s', time() - $days * 86400 );
		$deleted_comments = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$comments} WHERE source = %s AND thread_id IN ( SELECT id FROM {$threads} WHERE source = %s AND collected_at < %s )",
				$source,
				$source,
				$cutoff
			)
		);
		$deleted_threads = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$threads} WHERE source = %s AND collected_at < %s",
				$source,
				$cutoff
			)
		);
		return ( false === $deleted_comments ? 0 : (int) $deleted_comments )
			+ ( false === $deleted_threads ? 0 : (int) $deleted_threads );
	}
	public static function all_sources(): array {
		return array_merge(
			array( Ec_Review_Collector::SOURCE ),
			self::PARTNER_SNS,
			self::DIRECT_SOURCES
		);
	}
	public static function delete_stale_all(): int {
		$deleted = 0;
		foreach ( self::all_sources() as $source ) {
			$deleted += self::delete_stale( $source );
		}
		return $deleted;
	}
}

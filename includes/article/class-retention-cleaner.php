<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
final class Retention_Cleaner {
	public const HOOK = 'gachireai_retention_cleanup';
	private const RECURRENCE = 'daily';
	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_schedule_event( time(), self::RECURRENCE, self::HOOK );
	}
	public static function sync(): void {
		self::schedule();
	}
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
	public static function run(): void {
		try {
			$deleted = Source_Settings::delete_stale_all();
		} catch ( \Throwable $e ) {
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( '期限切れの生データの削除に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				)
			);
			return;
		}
		if ( $deleted <= 0 ) {
			return;
		}
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '保持期限を過ぎた生データを %1$d 件削除しました（保持期間 %2$d 日）。', GACHIREAI_TEXT_DOMAIN ),
				$deleted,
				Source_Settings::retention_days()
			)
		);
	}
}

<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Setup_Progress;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Auto_Post_Scheduler {
	public const GROUP = 'article';
	public const KEY_ENABLED = 'auto_publish';
	public const HOOK = 'gachireai_auto_post';
	private const RUNNING_KEY = 'gachireai_auto_post_running';
	private const RUNNING_TTL = 900;
	private const MAX_ATTEMPTS = 3;
	public const KEY_CRON_MODE = 'cron_mode';
	public const MODE_WP = 'wp';
	public const MODE_SERVER = 'server';
	public const LAST_RUN_KEY = 'gachireai_auto_post_last';
	private const GUARD_LOG_KEY = 'gachireai_auto_post_guard_logged';
	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}
	public static function is_enabled( ?Settings $settings = null ): bool {
		$article = ( $settings ?? new Settings() )->get( self::GROUP, array() );
		if ( ! is_array( $article ) ) {
			return false;
		}
		return '1' === (string) ( $article[ self::KEY_ENABLED ] ?? '' );
	}
	public static function cron_mode( ?Settings $settings = null ): string {
		if ( ! Status::capabilities()->can_use_cron() ) {
			return self::MODE_WP;
		}
		$article = ( $settings ?? new Settings() )->get( self::GROUP, array() );
		$mode    = is_array( $article ) ? (string) ( $article[ self::KEY_CRON_MODE ] ?? '' ) : '';
		return in_array( $mode, self::modes(), true ) ? $mode : self::MODE_WP;
	}
	public static function modes(): array {
		return array( self::MODE_WP, self::MODE_SERVER );
	}
	public static function apply_cron_runtime(): void {
		if ( self::MODE_SERVER !== self::cron_mode() ) {
			return;
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
	}
	public static function cron_warning(): ?string {
		if ( self::MODE_SERVER !== self::cron_mode() ) {
			return null;
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) || DISABLE_WP_CRON ) {
			return null;
		}
		$location = self::find_constant_definition( 'DISABLE_WP_CRON' );
		$where    = null !== $location ? $location : __( '定義位置を特定できませんでした', GACHIREAI_TEXT_DOMAIN );
		return sprintf(
			__( '「実行方式」が「サーバーcron」ですが、DISABLE_WP_CRON が false で定義されています（%s）。サーバーcron ではサイト訪問による WP-Cron を止める必要があります。その定義を削除する（削除すればプラグインが true を設定します）か、true に変更してください。', GACHIREAI_TEXT_DOMAIN ),
			$where
		);
	}
	private static function find_constant_definition( string $name ): ?string {
		$pattern = '/define\s*\(\s*[\'"]' . preg_quote( $name, '/' ) . '[\'"]/';
		foreach ( get_included_files() as $file ) {
			if ( ! is_readable( $file ) || filesize( $file ) > 1048576 ) {
				continue;
			}
			$lines = explode( "\n", (string) file_get_contents( $file ) );
			foreach ( $lines as $index => $line ) {
				if ( 1 === preg_match( $pattern, $line ) ) {
					return $file . ':' . ( $index + 1 );
				}
			}
		}
		return null;
	}
	public static function post_times( ?Settings $settings = null ): array {
		$article = ( $settings ?? new Settings() )->get( self::GROUP, array() );
		if ( ! is_array( $article ) ) {
			return array();
		}
		$schedule = is_scalar( $article[ Cron_Schedule::KEY ] ?? null ) ? (string) $article[ Cron_Schedule::KEY ] : '';
		return Cron_Schedule::times( $schedule );
	}
	public static function next_occurrence( string $time, ?int $now = null ): int {
		$now                   = $now ?? time();
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$next     = ( new \DateTimeImmutable( 'now', $timezone ) )->setTime( $hour, $minute, 0, 0 );
		if ( $next->getTimestamp() <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}
	public static function sync(): void {
		if ( ! Status::capabilities()->can_use_cron() ) {
			Auto_Post_Pipeline::clear();
			self::unschedule();
			return;
		}
		if ( ! self::is_enabled() ) {
			Auto_Post_Pipeline::clear();
			self::unschedule();
			return;
		}
		if ( self::MODE_SERVER === self::cron_mode() ) {
			self::unschedule();
			return;
		}
		Auto_Post_Pipeline::clear();
		foreach ( self::post_times() as $time ) {
			self::schedule_next( $time );
		}
	}
	public static function unschedule(): void {
		wp_unschedule_hook( self::HOOK );
	}
	private static function schedule_next( string $time ): void {
		if ( wp_next_scheduled( self::HOOK, array( $time ) ) ) {
			return;
		}
		wp_schedule_single_event( self::next_occurrence( $time ), self::HOOK, array( $time ) );
	}
	public static function server_tick( ?int $now = null ): void {
		if ( ! self::is_enabled() || self::MODE_SERVER !== self::cron_mode() ) {
			return;
		}
		if ( false !== get_transient( self::RUNNING_KEY ) ) {
			return;
		}
		$pipeline = new Auto_Post_Pipeline();
		if ( $pipeline->is_ready() ) {
			$slot = self::due_slot( $now );
			if ( null === $slot || (string) get_option( self::LAST_RUN_KEY, '' ) === $slot['key'] ) {
				return;
			}
			set_transient( self::RUNNING_KEY, 1, self::RUNNING_TTL );
			try {
				self::publish_ready( $pipeline, $slot['time'] );
				update_option( self::LAST_RUN_KEY, $slot['key'], false );
			} finally {
				delete_transient( self::RUNNING_KEY );
			}
			return;
		}
		$progress = new Setup_Progress();
		if ( $pipeline->is_empty() && ( ! $progress->all_complete() || ! $progress->can_auto_post() ) ) {
			self::log_guard_throttled();
			return;
		}
		set_transient( self::RUNNING_KEY, 1, self::RUNNING_TTL );
		try {
			if ( $pipeline->is_empty() ) {
				$pipeline->start( self::author_id() );
			}
			$pipeline->advance();
		} finally {
			delete_transient( self::RUNNING_KEY );
		}
	}
	private static function publish_ready( Auto_Post_Pipeline $pipeline, string $time ): void {
		$post_id = $pipeline->post_id();
		if ( $post_id <= 0 ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_ERROR,
				__( '公開する記事 ID がありません。準備をやり直します。', GACHIREAI_TEXT_DOMAIN ),
				array( 'time' => $time )
			);
			$pipeline->mark_published();
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_WARN,
				__( '用意した下書きが見つからないため、準備をやり直します。', GACHIREAI_TEXT_DOMAIN ),
				array(
					'time'    => $time,
					'post_id' => $post_id,
				)
			);
			$pipeline->mark_published();
			return;
		}
		try {
			self::publish( $post_id );
		} catch ( \Throwable $e ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( '記事の自動公開に失敗しました（下書きのまま残します）: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				),
				array(
					'time'    => $time,
					'post_id' => $post_id,
				),
				$post_id
			);
			return;
		}
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '記事を自動公開しました: %s', GACHIREAI_TEXT_DOMAIN ),
				$pipeline->post_title()
			),
			array(
				'time'    => $time,
				'post_id' => $post_id,
			),
			$post_id
		);
		$pipeline->mark_published();
	}
	private static function log_guard_throttled(): void {
		if ( false !== get_transient( self::GUARD_LOG_KEY ) ) {
			return;
		}
		set_transient( self::GUARD_LOG_KEY, 1, 3600 );
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_WARN,
			__( '自動投稿の準備をスキップしました（設定が未完了、または公開が許可されていません）。', GACHIREAI_TEXT_DOMAIN )
		);
	}
	public static function due_slot( ?int $now = null ): ?array {
		$now    = $now ?? time();
		$tz     = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$today  = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$latest = null;
		foreach ( self::post_times() as $time ) {
			list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );
			$slot                   = $today->setTime( $hour, $minute, 0, 0 );
			if ( $slot->getTimestamp() <= $now && ( null === $latest || $slot->getTimestamp() > $latest->getTimestamp() ) ) {
				$latest = $slot;
			}
		}
		if ( null === $latest ) {
			return null;
		}
		return array(
			'time' => $latest->format( 'H:i' ),
			'key'  => $latest->format( 'Y-m-d H:i' ),
		);
	}
	public static function run( string $time = '' ): void {
		$time = (string) $time;
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( false !== get_transient( self::RUNNING_KEY ) ) {
			if ( '' !== $time ) {
				self::schedule_next( $time );
			}
			return;
		}
		set_transient( self::RUNNING_KEY, 1, self::RUNNING_TTL );
		try {
			self::execute( $time );
		} finally {
			delete_transient( self::RUNNING_KEY );
			if ( '' !== $time && self::is_enabled() ) {
				self::schedule_next( $time );
			}
		}
	}
	public static function publish( int $post_id ): void {
		if ( ! ( new Setup_Progress() )->can_auto_post() ) {
			throw new \RuntimeException( __( '自動公開が許可されていません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( __( '公開する記事が見つかりません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( 'publish' === $post->post_status ) {
			return;
		}
		$post_date = (string) current_time( 'mysql' );
		$result = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'publish',
				'post_date'     => $post_date,
				'post_date_gmt' => (string) get_gmt_from_date( $post_date ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}
	}
	private static function execute( string $time ): void {
		$progress = new Setup_Progress();
		if ( ! $progress->all_complete() || ! $progress->can_auto_post() ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_WARN,
				__( '自動投稿をスキップしました（設定が未完了、または公開が許可されていません）。', GACHIREAI_TEXT_DOMAIN ),
				array( 'time' => $time )
			);
			return;
		}
		$started = microtime( true );
		$saved   = null;
		$error   = '';
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '自動投稿を開始しました（%s）。', GACHIREAI_TEXT_DOMAIN ),
				$time
			),
			array( 'time' => $time )
		);
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$saved = ( new Article_Generator() )->generate( null, null, '', '', false, self::author_id() );
				break;
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
				$saved = null;
				if ( $attempt < self::MAX_ATTEMPTS ) {
					Event_Logger::record(
						Event_Logger::TYPE_GENERATE,
						Event_Logger::LEVEL_WARN,
						sprintf(
							__( '自動投稿の生成に失敗しました（%1$d/%2$d）。再試行します: %3$s', GACHIREAI_TEXT_DOMAIN ),
							$attempt,
							self::MAX_ATTEMPTS,
							$error
						),
						array(
							'time'    => $time,
							'attempt' => $attempt,
						)
					);
				}
			}
		}
		if ( null === $saved ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( '自動投稿に失敗しました（%1$d 回試行）: %2$s', GACHIREAI_TEXT_DOMAIN ),
					self::MAX_ATTEMPTS,
					$error
				),
				array( 'time' => $time ),
				0,
				self::elapsed_ms( $started )
			);
			return;
		}
		$post_id = (int) ( $saved['post_id'] ?? 0 );
		try {
			self::publish( $post_id );
		} catch ( \Throwable $e ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( '記事の自動公開に失敗しました（下書きのまま残します）: %s', GACHIREAI_TEXT_DOMAIN ),
					$e->getMessage()
				),
				array(
					'time'    => $time,
					'post_id' => $post_id,
				),
				$post_id,
				self::elapsed_ms( $started )
			);
			return;
		}
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '記事を自動公開しました: %s', GACHIREAI_TEXT_DOMAIN ),
				(string) ( $saved['post_title'] ?? '' )
			),
			array(
				'time'         => $time,
				'post_id'      => $post_id,
				'product_name' => (string) ( $saved['product_name'] ?? '' ),
			),
			$post_id,
			self::elapsed_ms( $started )
		);
	}
	private static function author_id(): int {
		$persona = ( new Site_Profile() )->get_my_persona();
		$slug    = trim( (string) ( $persona['author_slug'] ?? '' ) );
		if ( '' !== $slug ) {
			$user = get_user_by( 'slug', $slug );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$id     = is_array( $admins ) ? (int) ( $admins[0] ?? 0 ) : 0;
		return $id > 0 ? $id : 1;
	}
	private static function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
}

<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager;
final class Generate_Progress {
	private const DIRNAME = 'gachireai-progress';
	private const TOKEN_PREFIX = 'gachireai_progress_token_';
	private const TOKEN_TTL = 3600;
	private const STALE_AGE = 3600;
	public const STEP_SELECT    = 1;  
	public const STEP_COLLECT   = 2;  
	public const STEP_PICK      = 3;  
	public const STEP_REWRITE   = 4;  
	public const STEP_AI_REVIEW = 5;  
	public const STEP_GENERATE  = 6;  
	public const STEP_AFFILIATE = 7;  
	public const STEP_ASSEMBLE  = 8;  
	public const STEP_SAVE      = 9;  
	public const STEP_EYECATCH  = 10; 
	public const STEP_TOTAL = 10;
	private static int $phase = 0;
	private static int $total = 0;
	public static function issue_token( int $user_id ): string {
		if ( 0 >= $user_id ) {
			return '';
		}
		$token = self::token( $user_id );
		if ( '' !== $token ) {
			return $token;
		}
		$token = (string) wp_generate_password( 20, false, false );
		set_transient( self::key( $user_id ), $token, self::TOKEN_TTL );
		return $token;
	}
	public static function token( int $user_id ): string {
		if ( 0 >= $user_id ) {
			return '';
		}
		$token = get_transient( self::key( $user_id ) );
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9]{8,64}$/', $token ) ? $token : '';
	}
	public static function base_url(): string {
		$uploads = wp_upload_dir();
		$baseurl = is_array( $uploads ) ? (string) ( $uploads['baseurl'] ?? '' ) : '';
		if ( '' === $baseurl ) {
			return '';
		}
		$baseurl = set_url_scheme( $baseurl );
		return rtrim( $baseurl, '/' ) . '/' . self::DIRNAME;
	}
	public static function start( int $user_id, string $text = '', int $phase = 0, int $total = 0 ): void {
		if ( 0 >= $user_id ) {
			return;
		}
		$dir = self::dir();
		if ( '' === $dir ) {
			return;
		}
		self::sweep( $dir );
		if ( 0 < $phase ) {
			self::$phase = $phase;
		}
		if ( 0 < $total ) {
			self::$total = $total;
		}
		self::write( $user_id, '' !== $text ? $text : __( '記事即時生成中...', GACHIREAI_TEXT_DOMAIN ), null );
		Manager::set_progress_callback(
			static function ( array $notification ) use ( $user_id ): void {
				self::retry(
					$user_id,
					(array) ( $notification['retry'] ?? array() ),
					(string) ( $notification['label'] ?? '' )
				);
			}
		);
	}
	public static function update( int $user_id, string $text, ?int $phase = null, ?int $total = null ): void {
		if ( null !== $phase && 0 < $phase ) {
			self::$phase = $phase;
		}
		if ( null !== $total && 0 < $total ) {
			self::$total = $total;
		}
		self::write( $user_id, $text, null );
	}
	public static function retry( int $user_id, array $retry, string $label = '' ): void {
		self::write(
			$user_id,
			'' !== $label ? $label : __( 'リトライ中...', GACHIREAI_TEXT_DOMAIN ),
			$retry
		);
	}
	private static function write( int $user_id, string $label, ?array $retry ): void {
		$path = self::file_path( $user_id );
		if ( null === $path ) {
			return;
		}
		$json = wp_json_encode(
			array(
				'label' => $label,
				'phase' => self::$phase,
				'total' => self::$total,
				'retry' => $retry,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $json ) || '' === $json ) {
			$json = $label;
		}
		@file_put_contents( $path, $json, LOCK_EX ); 
	}
	public static function finish( int $user_id ): void {
		Manager::set_progress_callback( null );
		self::$phase = 0;
		self::$total = 0;
		$path = self::file_path( $user_id );
		if ( null !== $path && is_file( $path ) ) {
			@unlink( $path );
		}
	}
	private static function file_path( int $user_id ): ?string {
		$token = self::token( $user_id );
		$dir   = self::dir();
		if ( '' === $token || '' === $dir ) {
			return null;
		}
		return $dir . '/' . $token . '.txt';
	}
	private static function dir(): string {
		$uploads = wp_upload_dir();
		$basedir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		if ( '' === $basedir ) {
			return '';
		}
		$dir = rtrim( $basedir, '/\\' ) . '/' . self::DIRNAME;
		if ( is_dir( $dir ) ) {
			return $dir;
		}
		$created = function_exists( 'wp_mkdir_p' ) ? (bool) wp_mkdir_p( $dir ) : false;
		return $created ? $dir : '';
	}
	private static function sweep( string $dir ): void {
		$files = glob( $dir . '/*.txt' );
		if ( ! is_array( $files ) ) {
			return;
		}
		$limit = time() - self::STALE_AGE;
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || ! is_file( $file ) ) {
				continue;
			}
			$mtime = filemtime( $file );
			if ( is_int( $mtime ) && $mtime < $limit ) {
				@unlink( $file );
			}
		}
	}
	private static function key( int $user_id ): string {
		return self::TOKEN_PREFIX . $user_id;
	}
}

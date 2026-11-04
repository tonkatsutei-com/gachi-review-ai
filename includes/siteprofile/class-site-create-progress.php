<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\SiteProfile;
use Tonkatsutei\Gachi_Review_AI\Article\Generate_Progress;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Site_Create_Progress {
	private const DIRNAME = 'gachireai-progress';
	private string $dir;
	private string $token;
	public function __construct( int $user_id ) {
		$this->dir   = $this->resolve_dir();
		$this->token = Generate_Progress::token( $user_id );
	}
	public function callback(): callable {
		return function ( string $label, int $phase = 0, int $total = 0 ): void {
			$this->write( $label, $phase, $total );
		};
	}
	public function finish(): void {
		$path = $this->path();
		if ( '' !== $path && is_file( $path ) ) {
			@unlink( $path ); 
		}
	}
	private function write( string $label, int $phase, int $total ): void {
		$path = $this->path();
		if ( '' === $path ) {
			return;
		}
		$json = wp_json_encode(
			array(
				'label' => $label,
				'phase' => max( 0, $phase ),
				'total' => max( 0, $total ),
				'retry' => null,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $json ) || '' === $json ) {
			$json = $label;
		}
		@file_put_contents( $path, $json, LOCK_EX ); 
	}
	private function path(): string {
		if ( '' === $this->dir || '' === $this->token ) {
			return '';
		}
		return rtrim( $this->dir, '/\\' ) . '/' . $this->token . '.txt';
	}
	private function resolve_dir(): string {
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
}

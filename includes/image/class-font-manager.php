<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Font_Manager {
	public const OPTION_KEY = 'gachireai_font_custom';
	private const DIRECTORY = 'gachireai-fonts';
	private const CUSTOM_PREFIX = 'custom_';
	private const DOWNLOAD_UA = 'Wget/1.20.3 (linux-gnu)';
	private const MAX_FONT_BYTES = 20971520;
	private const FALLBACK_FONT = 'assets/fonts/NotoSansJP-Bold.otf';
	public static function custom_fonts(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			return array();
		}
		$fonts = array();
		foreach ( $saved as $slug => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['file'] ) || ! is_scalar( $entry['file'] ) ) {
				continue;
			}
			$file = basename( (string) $entry['file'] );
			if ( '' === $file ) {
				continue;
			}
			$fonts[ (string) $slug ] = array(
				'label'  => isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? (string) $entry['label'] : (string) $slug,
				'file'   => $file,
				'format' => isset( $entry['format'] ) && is_scalar( $entry['format'] ) ? (string) $entry['format'] : 'truetype',
			);
		}
		return $fonts;
	}
	public static function is_custom( string $slug ): bool {
		return str_starts_with( $slug, self::CUSTOM_PREFIX );
	}
	public static function all_options(): array {
		$options = Font_Catalog::options();
		foreach ( self::custom_fonts() as $slug => $entry ) {
			$options[ (string) $slug ] = (string) $entry['label'];
		}
		return $options;
	}
	public static function normalize_font( string $slug ): string {
		return isset( self::all_options()[ $slug ] ) ? $slug : Font_Catalog::DEFAULT_FONT;
	}
	public static function weights_for( string $slug ): array {
		if ( self::is_custom( $slug ) ) {
			return array( '400' );
		}
		return array_map( 'strval', Font_Catalog::weights( $slug ) );
	}
	public static function default_weight( string $slug ): string {
		return self::is_custom( $slug ) ? '400' : Font_Catalog::default_weight( $slug );
	}
	public static function normalize_weight( string $slug, string $weight ): string {
		if ( self::is_custom( $slug ) ) {
			return '400';
		}
		return Font_Catalog::normalize_weight( $slug, $weight );
	}
	public static function resolve( string $slug, string $weight ): ?string {
		if ( self::is_custom( $slug ) ) {
			$path = self::custom_entry_path( $slug );
			return '' !== $path && self::is_font_file( $path ) ? $path : null;
		}
		$family = Font_Catalog::family( $slug );
		if ( null === $family ) {
			return self::fallback_font();
		}
		$weight = Font_Catalog::normalize_weight( $slug, $weight );
		$dir    = self::dir();
		if ( '' === $dir['path'] ) {
			return self::fallback_font();
		}
		$cache = $dir['path'] . '/' . $slug . '-' . $weight . '.ttf';
		if ( self::is_font_file( $cache ) ) {
			return $cache;
		}
		$downloaded = self::download( $slug, $weight );
		return null !== $downloaded ? $downloaded : self::fallback_font();
	}
	public static function family_name( string $path ): string {
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		$data = @file_get_contents( $path ); 
		if ( ! is_string( $data ) || 12 > strlen( $data ) ) {
			return '';
		}
		$num_tables  = self::uint16( $data, 4 );
		$name_offset = 0;
		for ( $i = 0; $i < $num_tables; $i++ ) {
			$record = 12 + ( $i * 16 );
			if ( $record + 16 > strlen( $data ) ) {
				break;
			}
			if ( 'name' === substr( $data, $record, 4 ) ) {
				$name_offset = self::uint32( $data, $record + 8 );
				break;
			}
		}
		if ( 0 >= $name_offset || $name_offset + 6 > strlen( $data ) ) {
			return '';
		}
		$count         = self::uint16( $data, $name_offset + 2 );
		$string_offset = self::uint16( $data, $name_offset + 4 );
		$strings_base  = $name_offset + $string_offset;
		$best          = '';
		$best_score    = -1;
		for ( $i = 0; $i < $count; $i++ ) {
			$record = $name_offset + 6 + ( $i * 12 );
			if ( $record + 12 > strlen( $data ) ) {
				break;
			}
			$platform = self::uint16( $data, $record );
			$language = self::uint16( $data, $record + 4 );
			$name_id  = self::uint16( $data, $record + 6 );
			$length   = self::uint16( $data, $record + 8 );
			$offset   = self::uint16( $data, $record + 10 );
			if ( ! in_array( $name_id, array( 1, 16 ), true ) || 0 >= $length ) {
				continue;
			}
			$start = $strings_base + $offset;
			if ( $start + $length > strlen( $data ) ) {
				continue;
			}
			$text = trim( self::decode_name( substr( $data, $start, $length ), $platform ) );
			if ( '' === $text ) {
				continue;
			}
			$score  = 16 === $name_id ? 100 : 0;
			$score += 3 === $platform ? 50 : ( 0 === $platform ? 40 : 0 );
			$score += 0x0411 === $language ? 20 : ( 0x0409 === $language ? 10 : 0 );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $text;
			}
		}
		return $best;
	}
	public static function upload( array $file ): array {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			throw new \RuntimeException( __( 'フォントファイルを選択してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( UPLOAD_ERR_OK !== $error ) {
			throw new \RuntimeException( __( 'フォントファイルをアップロードできませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			throw new \RuntimeException( __( 'アップロードされたファイルを確認できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( 0 >= $size || self::MAX_FONT_BYTES < $size ) {
			throw new \RuntimeException( __( 'フォントファイルのサイズが上限（20MB）を超えています。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$original = isset( $file['name'] ) ? (string) $file['name'] : '';
		$name     = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $original ) : basename( $original );
		$ext      = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'ttf', 'otf' ), true ) ) {
			throw new \RuntimeException( __( 'フォントは .ttf または .otf のみアップロードできます。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( ! self::is_font_file( $tmp ) ) {
			throw new \RuntimeException( __( 'フォントファイルとして読み込めませんでした（ファイルが壊れている可能性があります）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( ! self::ensure_dir() ) {
			throw new \RuntimeException( __( 'フォントの保存先を作成できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$base = function_exists( 'sanitize_title' ) ? sanitize_title( (string) pathinfo( $name, PATHINFO_FILENAME ) ) : '';
		$base = trim( (string) preg_replace( '/[^a-z0-9_-]+/i', '-', $base ), '-' );
		if ( '' === $base ) {
			$base = 'font';
		}
		$suffix = function_exists( 'wp_generate_password' )
			? wp_generate_password( 6, false, false )
			: substr( md5( $tmp . microtime() ), 0, 6 );
		$suffix = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $suffix ) );
		if ( '' === $suffix ) {
			$suffix = substr( md5( $tmp . microtime() ), 0, 6 );
		}
		$slug     = self::CUSTOM_PREFIX . $suffix;
		$filename = $base . '-' . $suffix . '.' . $ext;
		$dir      = self::dir();
		$dest     = $dir['path'] . '/' . $filename;
		if ( ! move_uploaded_file( $tmp, $dest ) ) {
			throw new \RuntimeException( __( 'フォントファイルを保存できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$family_name = self::family_name( $dest );
		$raw_label   = '' !== $family_name
			? $family_name
			: (string) pathinfo( $original, PATHINFO_FILENAME );
		$label       = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw_label ) : $raw_label;
		$label       = trim( $label );
		if ( '' === $label ) {
			$label = __( 'カスタムフォント', GACHIREAI_TEXT_DOMAIN );
		}
		$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 80 ) : substr( $label, 0, 80 );
		$entry = array(
			'label'  => $label,
			'file'   => $filename,
			'format' => 'otf' === $ext ? 'opentype' : 'truetype',
		);
		$custom          = self::custom_fonts();
		$custom[ $slug ] = $entry;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $custom );
		}
		return array(
			'slug'  => $slug,
			'label' => $label,
			'entry' => $entry,
		);
	}
	private static function download( string $slug, string $weight ): ?string {
		$family = Font_Catalog::family( $slug );
		if ( null === $family || ! self::ensure_dir() ) {
			return null;
		}
		$css_url = self::css_url(
			(string) $family['google'],
			$weight,
			count( (array) $family['weights'] )
		);
		$css     = self::http_get( $css_url );
		if ( '' === $css || 1 !== preg_match( '/url\((https:\/\/fonts\.gstatic\.com\/[^)]+)\)/', $css, $matches ) ) {
			return null;
		}
		$binary = self::http_get( (string) $matches[1] );
		if ( '' === $binary || ! self::is_font_binary( $binary ) ) {
			return null;
		}
		$dir  = self::dir();
		$path = $dir['path'] . '/' . $slug . '-' . $weight . '.ttf';
		$tmp  = $path . '.' . getmypid() . '.tmp';
		if ( false === @file_put_contents( $tmp, $binary ) ) { 
			return null;
		}
		if ( ! @rename( $tmp, $path ) ) { 
			@unlink( $tmp ); 
			return null;
		}
		return self::is_font_file( $path ) ? $path : null;
	}
	private static function css_url( string $google, string $weight, int $weight_count ): string {
		$name = str_replace( '%20', '+', rawurlencode( $google ) );
		$url  = 'https://fonts.googleapis.com/css2?family=' . $name;
		if ( 1 < $weight_count ) {
			$url .= ':wght@' . $weight;
		}
		return $url . '&display=swap';
	}
	private static function http_get( string $url ): string {
		if ( function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 30,
					'redirection' => 5,
					'headers'     => array( 'User-Agent' => self::DOWNLOAD_UA ),
				)
			);
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
				return '';
			}
			$code = function_exists( 'wp_remote_retrieve_response_code' )
				? (int) wp_remote_retrieve_response_code( $response )
				: 200;
			if ( 200 > $code || 300 <= $code ) {
				return '';
			}
			return function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		}
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$context = stream_context_create(
			array(
				'http' => array(
					'timeout' => 30,
					'header'  => 'User-Agent: ' . self::DOWNLOAD_UA . "\r\n",
				),
			)
		);
		$body    = @file_get_contents( $url, false, $context ); 
		return is_string( $body ) ? $body : '';
	}
	private static function decode_name( string $raw, int $platform ): string {
		if ( 1 === $platform ) {
			try {
				$text = mb_convert_encoding( $raw, 'UTF-8', 'MacRoman' );
			} catch ( \ValueError $e ) {
				return $raw;
			}
			return is_string( $text ) ? $text : $raw;
		}
		try {
			$text = mb_convert_encoding( $raw, 'UTF-8', 'UTF-16BE' );
		} catch ( \ValueError $e ) {
			return '';
		}
		return is_string( $text ) ? $text : '';
	}
	private static function uint16( string $data, int $offset ): int {
		$value = unpack( 'n', substr( $data, $offset, 2 ) );
		return is_array( $value ) ? (int) $value[1] : 0;
	}
	private static function uint32( string $data, int $offset ): int {
		$value = unpack( 'N', substr( $data, $offset, 4 ) );
		return is_array( $value ) ? (int) $value[1] : 0;
	}
	private static function is_font_binary( string $binary ): bool {
		if ( 4 > strlen( $binary ) ) {
			return false;
		}
		return in_array(
			substr( $binary, 0, 4 ),
			array( "\x00\x01\x00\x00", 'true', 'ttcf', 'OTTO' ),
			true
		);
	}
	private static function is_font_file( string $path ): bool {
		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}
		$handle = @fopen( $path, 'rb' ); 
		if ( false === $handle ) {
			return false;
		}
		$magic = (string) fread( $handle, 4 );
		fclose( $handle );
		return self::is_font_binary( $magic );
	}
	private static function fallback_font(): ?string {
		if ( ! defined( 'GACHIREAI_PLUGIN_DIR' ) ) {
			return null;
		}
		$path = GACHIREAI_PLUGIN_DIR . self::FALLBACK_FONT;
		return is_readable( $path ) ? $path : null;
	}
	private static function custom_entry_path( string $slug ): string {
		$entries = self::custom_fonts();
		if ( ! isset( $entries[ $slug ] ) ) {
			return '';
		}
		$dir = self::dir();
		if ( '' === $dir['path'] ) {
			return '';
		}
		return $dir['path'] . '/' . (string) $entries[ $slug ]['file'];
	}
	private static function dir(): array {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return array(
				'path' => '',
				'url'  => '',
			);
		}
		$upload   = wp_upload_dir();
		$base     = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		$base_url = isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '';
		if ( '' === $base ) {
			return array(
				'path' => '',
				'url'  => '',
			);
		}
		return array(
			'path' => rtrim( $base, '/\\' ) . '/' . self::DIRECTORY,
			'url'  => '' !== $base_url ? rtrim( $base_url, '/\\' ) . '/' . self::DIRECTORY : '',
		);
	}
	private static function ensure_dir(): bool {
		$dir = self::dir();
		if ( '' === $dir['path'] ) {
			return false;
		}
		if ( ! is_dir( $dir['path'] ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir['path'] );
			} else {
				@mkdir( $dir['path'], 0755, true ); 
			}
		}
		return is_dir( $dir['path'] ) && is_writable( $dir['path'] );
	}
}

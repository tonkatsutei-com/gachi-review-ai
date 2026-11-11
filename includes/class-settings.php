<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
use Tonkatsutei\Gachi_Review_AI\Image\Font_Catalog;
class Settings {
	public const OPTION_KEY = 'gachireai_settings';
	public const COMMON_GROUPS = array( 'rakuten', 'amazon', 'yahoo', 'llm', 'jev', 'image', 'youtube', 'mastodon', 'scraping' );
	private const DEFAULT_PROVIDER = 'openai';
	public const TOP_HERO_WITH_TITLE = 'with_title';
	public const TOP_HERO_IMAGE_ONLY = 'image_only';
	public const TOP_HERO_HIDDEN     = 'hidden';
	public const DEFAULT_DISCLOSURE_TEXT = 'この画像は記事を元にAIで生成したものであり、紹介する商品とは無関係です。';
	public const DISCLOSURE_KEYS = array( 'affiliate', 'image', 'review', 'article' );
	public const DISCLOSURE_POSITION_TOP = 'top';
	public const DISCLOSURE_POSITION_BOTTOM = 'bottom';
	public const DISCLOSURE_DEFAULT_ENABLED = array(
		'affiliate' => '1',
		'image'     => '1',
		'review'    => '1',
		'article'   => '0',
	);
	public const DISCLOSURE_DEFAULT_TEXTS = array(
		'affiliate' => '当サイトはアフィリエイト広告（楽天市場・Yahoo!ショッピング・Amazon 等）を利用しています。',
		'image'     => self::DEFAULT_DISCLOSURE_TEXT,
		'review'    => '一部の記事には、販売ページや口コミサイト等に投稿されたレビューや商品情報を元に、AIが生成、リライト、要約したものが含まれることがあります。',
		'article'   => '当サイトの記事は、収集した販売ページや口コミサイト等に投稿された情報を元に、AIがリライト、要約、集約したものです。',
	);
	protected array $data;
	protected bool $common_fallback = true;
	private ?array $common_overrides_cache = null;
	public function __construct() {
		$saved      = $this->load_data();
		$this->data = is_array( $saved ) ? $saved : array();
	}
	protected function load_data(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		return is_array( $saved ) ? $saved : array();
	}
	protected function persist(): void {
		update_option( self::OPTION_KEY, $this->data );
		$this->common_overrides_cache = null;
	}
	public function all(): array {
		return $this->data;
	}
	public function get( string $path, $default = null ) {
		$group = explode( '.', $path )[0];
		$site_found = false;
		$site_value = self::lookup( $this->data, $path, $site_found );
		if ( $this->uses_common( $group ) ) {
			$common_found = false;
			$common_value = self::lookup( Common\Store::data(), $path, $common_found );
			if ( $common_found ) {
				if ( is_array( $common_value ) && $site_found && is_array( $site_value ) ) {
					return array_replace( $site_value, $common_value );
				}
				return $common_value;
			}
		}
		return $site_found ? $site_value : $default;
	}
	public function uses_common( string $group ): bool {
		if ( ! $this->common_fallback || ! in_array( $group, self::COMMON_GROUPS, true ) ) {
			return false;
		}
		return ! in_array( $group, $this->common_overrides(), true );
	}
	public function common_overrides(): array {
		if ( null !== $this->common_overrides_cache ) {
			return $this->common_overrides_cache;
		}
		$raw = $this->data['common_overrides'] ?? null;
		if ( is_array( $raw ) ) {
			$list = array();
			foreach ( $raw as $group ) {
				if ( is_scalar( $group ) && in_array( (string) $group, self::COMMON_GROUPS, true ) ) {
					$list[] = (string) $group;
				}
			}
			$this->common_overrides_cache = array_values( array_unique( $list ) );
			return $this->common_overrides_cache;
		}
		$list = array();
		foreach ( self::COMMON_GROUPS as $group ) {
			$value = $this->data[ $group ] ?? null;
			if ( null !== $value && ! self::all_empty_fields( $value ) ) {
				$list[] = $group;
			}
		}
		$this->common_overrides_cache = $list;
		return $list;
	}
	public function set_common_override( string $group, bool $override ): void {
		if ( ! in_array( $group, self::COMMON_GROUPS, true ) ) {
			return;
		}
		$list = $this->common_overrides();
		if ( $override ) {
			$list[] = $group;
			$list   = array_values( array_unique( $list ) );
		} else {
			$list = array_values( array_diff( $list, array( $group ) ) );
		}
		$this->set( 'common_overrides', $list );
	}
	private static function all_empty_fields( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( ! self::all_empty_fields( $child ) ) {
					return false;
				}
			}
			return true;
		}
		if ( null === $value || false === $value ) {
			return true;
		}
		return is_scalar( $value ) ? '' === trim( (string) $value ) : true;
	}
	private static function lookup( array $data, string $path, bool &$found ) {
		$value = $data;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				$found = false;
				return null;
			}
			$value = $value[ $key ];
		}
		$found = true;
		return $value;
	}
	private static function is_empty_value( $value ): bool {
		return null === $value || '' === $value || ( is_array( $value ) && array() === $value );
	}
	public function set( string $path, $value ): void {
		$keys = explode( '.', $path );
		$last = (string) array_pop( $keys );
		$ref  = &$this->data;
		foreach ( $keys as $key ) {
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				$ref[ $key ] = array();
			}
			$ref = &$ref[ $key ];
		}
		$ref[ $last ] = $value;
		unset( $ref );
		$this->persist();
	}
	public function replace_all( array $data ): void {
		$this->data = $data;
		$this->persist();
	}
	public function remove( string $path ): void {
		$keys = explode( '.', $path );
		$last = (string) array_pop( $keys );
		$ref  = &$this->data;
		foreach ( $keys as $key ) {
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				return;
			}
			$ref = &$ref[ $key ];
		}
		if ( array_key_exists( $last, $ref ) ) {
			unset( $ref[ $last ] );
		}
		unset( $ref );
		$this->persist();
	}
	public function llm_entries(): array {
		$llm = $this->get( 'llm', array() );
		if ( ! is_array( $llm ) ) {
			return array();
		}
		$entries = $llm['entries'] ?? null;
		if ( is_array( $entries ) && array() !== $entries ) {
			$normalized = array();
			foreach ( $entries as $id => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$provider = (string) ( $entry['provider'] ?? $id );
				if ( '' === $provider ) {
					continue;
				}
				$normalized[ (string) $id ] = array(
					'provider' => $provider,
					'api_key'  => self::trim_secret( (string) ( $entry['api_key'] ?? '' ) ),
					'model'    => self::trim_secret( (string) ( $entry['model'] ?? '' ) ),
				);
			}
			if ( array() !== $normalized ) {
				return $normalized;
			}
		}
		$provider = (string) ( $llm['provider'] ?? '' );
		$api_key  = self::trim_secret( (string) ( $llm['api_key'] ?? '' ) );
		$model    = self::trim_secret( (string) ( $llm['model'] ?? '' ) );
		if ( '' === $provider && '' === $api_key && '' === $model ) {
			return array();
		}
		$provider = '' !== $provider ? $provider : self::DEFAULT_PROVIDER;
		return array(
			$provider => array(
				'provider' => $provider,
				'api_key'  => $api_key,
				'model'    => $model,
			),
		);
	}
	public function active_llm_id(): string {
		$entries = $this->llm_entries();
		if ( array() === $entries ) {
			return '';
		}
		$active = (string) $this->get( 'llm.active', '' );
		if ( '' !== $active && isset( $entries[ $active ] ) ) {
			return $active;
		}
		foreach ( $entries as $id => $entry ) {
			if ( '' !== $entry['api_key'] ) {
				return (string) $id;
			}
		}
		$first = array_key_first( $entries );
		return is_string( $first ) ? $first : '';
	}
	public function active_llm(): array {
		$entries = $this->llm_entries();
		$id      = $this->active_llm_id();
		if ( '' === $id || ! isset( $entries[ $id ] ) ) {
			return array(
				'provider' => '',
				'api_key'  => '',
				'model'    => '',
			);
		}
		return $entries[ $id ];
	}
	public function save_llm( array $entries, string $active ): void {
		$this->set(
			'llm',
			array(
				'active'  => $active,
				'entries' => $entries,
			)
		);
	}
	public function jev(): array {
		$jev = $this->get( 'jev', array() );
		if ( ! is_array( $jev ) ) {
			$jev = array();
		}
		return array(
			'api_key' => (string) ( $jev['api_key'] ?? '' ),
			'model'   => (string) ( $jev['model'] ?? '' ),
		);
	}
	public function image(): array {
		$image = $this->get( 'image', array() );
		if ( ! is_array( $image ) ) {
			$image = array();
		}
		$value = static function ( array $source, string $key, string $default ): string {
			return array_key_exists( $key, $source ) && is_scalar( $source[ $key ] )
				? (string) $source[ $key ]
				: $default;
		};
		return array(
			'provider'     => $value( $image, 'provider', 'cloudflare' ),
			'api_key'      => $value( $image, 'api_key', '' ),
			'account_id'   => $value( $image, 'account_id', '' ),
			'model'        => $value( $image, 'model', '' ),
			'steps'        => $value( $image, 'steps', '4' ),
			'reuse_llm'    => $value( $image, 'reuse_llm', '' ),
			'style'        => $value( $image, 'style', 'cinematic' ),
			'style_text'   => $value( $image, 'style_text', '' ),
			'style_text_en' => $value( $image, 'style_text_en', '' ),
			'style_text_src' => $value( $image, 'style_text_src', '' ),
			'text_overlay' => $value( $image, 'text_overlay', '1' ),
			'text_side'    => $value( $image, 'text_side', 'left' ),
			'prompt_llm'   => $value( $image, 'prompt_llm', '1' ),
			'eyecatch'     => $value( $image, 'eyecatch', '1' ),
			'eyecatch_mode'  => self::normalize_eyecatch_mode( $value( $image, 'eyecatch_mode', 'generate' ) ),
			'eyecatch_image' => $value( $image, 'eyecatch_image', '' ),
			'show_eyecatch' => $value( $image, 'show_eyecatch', '1' ),
			'top_hero'     => self::normalize_top_hero( $value( $image, 'top_hero', self::TOP_HERO_IMAGE_ONLY ) ),
			'ogp'          => $value( $image, 'ogp', '0' ),
			'font'         => $value( $image, 'font', Font_Catalog::DEFAULT_FONT ),
			'font_weight'  => $value( $image, 'font_weight', Font_Catalog::DEFAULT_WEIGHT ),
		);
	}
	public function disclosure(): array {
		$saved = $this->get( 'disclosure', array() );
		$saved = is_array( $saved ) ? $saved : array();
		$result = array();
		foreach ( self::DISCLOSURE_KEYS as $key ) {
			$enabled = array_key_exists( $key . '_enabled', $saved )
				? ( '1' === (string) $saved[ $key . '_enabled' ] ? '1' : '0' )
				: self::DISCLOSURE_DEFAULT_ENABLED[ $key ];
			$position = (string) ( $saved[ $key . '_position' ] ?? '' );
			$position = in_array( $position, array( self::DISCLOSURE_POSITION_TOP, self::DISCLOSURE_POSITION_BOTTOM ), true )
				? $position
				: self::DISCLOSURE_POSITION_TOP;
			$text = trim( (string) ( $saved[ $key . '_text' ] ?? '' ) );
			if ( '' === $text ) {
				$text = self::DISCLOSURE_DEFAULT_TEXTS[ $key ];
			}
			$result[ $key ] = array(
				'enabled'  => $enabled,
				'position' => $position,
				'text'     => $text,
			);
		}
		return $result;
	}
	private static function normalize_top_hero( string $mode ): string {
		return in_array(
			$mode,
			array( self::TOP_HERO_WITH_TITLE, self::TOP_HERO_IMAGE_ONLY, self::TOP_HERO_HIDDEN ),
			true
		)
			? $mode
			: self::TOP_HERO_IMAGE_ONLY;
	}
	private static function normalize_eyecatch_mode( string $mode ): string {
		return in_array( $mode, array( 'generate', 'top', 'select' ), true ) ? $mode : 'generate';
	}
	public function image_api_key( ?array $image = null ): string {
		$image = null === $image ? $this->image() : $image;
		$key   = isset( $image['api_key'] ) && is_scalar( $image['api_key'] ) ? trim( (string) $image['api_key'] ) : '';
		if ( '' !== $key ) {
			return $key;
		}
		$reuse = isset( $image['reuse_llm'] ) && is_scalar( $image['reuse_llm'] ) ? (string) $image['reuse_llm'] : '';
		$provider = isset( $image['provider'] ) && is_scalar( $image['provider'] ) ? (string) $image['provider'] : '';
		if ( '1' !== $reuse || '' === $provider ) {
			return '';
		}
		foreach ( $this->llm_entries() as $entry ) {
			if ( $provider !== (string) ( $entry['provider'] ?? '' ) ) {
				continue;
			}
			$entry_key = trim( (string) ( $entry['api_key'] ?? '' ) );
			if ( '' !== $entry_key ) {
				return $entry_key;
			}
		}
		return '';
	}
	public static function trim_secret( string $value ): string {
		$trimmed = preg_replace(
			'/^[\p{Z}\s\x{FEFF}\x{200B}\x{2060}]+|[\p{Z}\s\x{FEFF}\x{200B}\x{2060}]+$/u',
			'',
			$value
		);
		return null === $trimmed ? trim( $value ) : $trimmed;
	}
}

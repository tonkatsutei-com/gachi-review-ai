<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
final class Term_Assigner {
	public const TAXONOMY_CATEGORY = 'category';
	public const TAXONOMY_TAG = 'post_tag';
	public const TAG_LIMIT = 5;
	private const NAME_MAX = 50;
	private array $index = array();
	public function assign( int $post_id, string $category_name, array $tag_names ): array {
		$result = array(
			'category' => null,
			'tags'     => array(),
			'warnings' => array(),
		);
		if ( 0 >= $post_id ) {
			$result['warnings'][] = __( '記事の ID が不正なため、カテゴリーとタグを設定できませんでした。', GACHIREAI_TEXT_DOMAIN );
			return $result;
		}
		$category = $this->clamp( trim( $category_name ) );
		if ( '' !== $category ) {
			$resolved           = $this->resolve_term( self::TAXONOMY_CATEGORY, $category );
			$result['category'] = $resolved;
			$warning            = $this->failure_message( self::TAXONOMY_CATEGORY, $resolved );
			if ( '' !== $warning ) {
				$result['warnings'][] = $warning;
			}
		}
		$seen = array();
		foreach ( $tag_names as $raw_name ) {
			if ( count( $result['tags'] ) >= self::TAG_LIMIT ) {
				break;
			}
			if ( ! is_scalar( $raw_name ) ) {
				continue;
			}
			$name = $this->clamp( trim( (string) $raw_name ) );
			if ( '' === $name ) {
				continue;
			}
			$key = self::normalize_name( $name );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$resolved         = $this->resolve_term( self::TAXONOMY_TAG, $name );
			$result['tags'][] = $resolved;
			$warning          = $this->failure_message( self::TAXONOMY_TAG, $resolved );
			if ( '' !== $warning ) {
				$result['warnings'][] = $warning;
			}
		}
		$category_ids = array();
		if ( null !== $result['category'] && 0 < (int) $result['category']['term_id'] ) {
			$category_ids[] = (int) $result['category']['term_id'];
		}
		$tag_ids = array();
		foreach ( $result['tags'] as $tag ) {
			if ( 0 < (int) $tag['term_id'] ) {
				$tag_ids[] = (int) $tag['term_id'];
			}
		}
		$this->assign_ids( $post_id, self::TAXONOMY_CATEGORY, $category_ids, $result['warnings'] );
		$this->assign_ids( $post_id, self::TAXONOMY_TAG, $tag_ids, $result['warnings'] );
		return $result;
	}
	private function assign_ids( int $post_id, string $taxonomy, array $term_ids, array &$warnings ): void {
		if ( array() === $term_ids ) {
			return;
		}
		$assigned = wp_set_post_terms( $post_id, $term_ids, $taxonomy, false );
		if ( is_wp_error( $assigned ) ) {
			$warnings[] = sprintf(
				__( '%1$sを記事へ設定できませんでした（%2$s）。', GACHIREAI_TEXT_DOMAIN ),
				self::label( $taxonomy ),
				(string) $assigned->get_error_message()
			);
		}
	}
	private static function label( string $taxonomy ): string {
		return self::TAXONOMY_CATEGORY === $taxonomy
			? __( 'カテゴリー', GACHIREAI_TEXT_DOMAIN )
			: __( 'タグ', GACHIREAI_TEXT_DOMAIN );
	}
	private function resolve_term( string $taxonomy, string $name ): array {
		$map      = $this->index( $taxonomy );
		$name_key = self::normalize_name( $name );
		$slug_key = self::normalize_slug( (string) sanitize_title( $name ) );
		$entry = null;
		if ( '' !== $name_key && isset( $map['names'][ $name_key ] ) ) {
			$entry = $map['names'][ $name_key ];
		} elseif ( '' !== $slug_key && isset( $map['slugs'][ $slug_key ] ) ) {
			$entry = $map['slugs'][ $slug_key ];
		}
		$created = false;
		if ( null === $entry ) {
			$entry = $this->create_term( $taxonomy, $name );
			if ( '' !== $entry['error'] ) {
				return array(
					'name'    => $name,
					'slug'    => '',
					'term_id' => 0,
					'created' => false,
					'error'   => $entry['error'],
				);
			}
			$created = true;
		}
		return array(
			'name'    => (string) $entry['name'],
			'slug'    => (string) $entry['slug'],
			'term_id' => (int) $entry['term_id'],
			'created' => $created,
			'error'   => '',
		);
	}
	private function index( string $taxonomy ): array {
		if ( isset( $this->index[ $taxonomy ] ) ) {
			return $this->index[ $taxonomy ];
		}
		$map   = array(
			'names' => array(),
			'slugs' => array(),
		);
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! is_object( $term ) ) {
					continue;
				}
				$entry = array(
					'term_id' => (int) ( $term->term_id ?? 0 ),
					'name'    => (string) ( $term->name ?? '' ),
					'slug'    => (string) ( $term->slug ?? '' ),
				);
				if ( 0 >= $entry['term_id'] ) {
					continue;
				}
				$this->remember( $map, $entry );
			}
		}
		$this->index[ $taxonomy ] = $map;
		return $map;
	}
	private function create_term( string $taxonomy, string $name ): array {
		$slug = (string) sanitize_title( $name );
		$args = array(
			'slug' => '' !== $slug ? $slug : 'term-' . substr( md5( $name ), 0, 12 ),
		);
		$created = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $created ) ) {
			if ( 'term_exists' === $created->get_error_code() ) {
				$existing = $this->find_by_id( $taxonomy, (int) $created->get_error_data() );
				if ( null !== $existing ) {
					return $existing;
				}
			}
			return array(
				'term_id' => 0,
				'name'    => $name,
				'slug'    => '',
				'error'   => (string) $created->get_error_message(),
			);
		}
		$term_id = is_array( $created ) ? (int) ( $created['term_id'] ?? 0 ) : 0;
		$entry   = $this->find_by_id( $taxonomy, $term_id );
		if ( null !== $entry ) {
			return $entry;
		}
		return array(
			'term_id' => $term_id,
			'name'    => $name,
			'slug'    => $slug,
			'error'   => '',
		);
	}
	private function find_by_id( string $taxonomy, int $term_id ): ?array {
		if ( 0 >= $term_id ) {
			return null;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		$entry = array(
			'term_id' => (int) $term->term_id,
			'name'    => (string) $term->name,
			'slug'    => (string) $term->slug,
		);
		if ( ! isset( $this->index[ $taxonomy ] ) ) {
			$this->index[ $taxonomy ] = array(
				'names' => array(),
				'slugs' => array(),
			);
		}
		$this->remember( $this->index[ $taxonomy ], $entry );
		return $entry + array( 'error' => '' );
	}
	private function remember( array &$map, array $entry ): void {
		$name_key = self::normalize_name( (string) $entry['name'] );
		if ( '' !== $name_key && ! isset( $map['names'][ $name_key ] ) ) {
			$map['names'][ $name_key ] = $entry;
		}
		$slug_key = self::normalize_slug( (string) $entry['slug'] );
		if ( '' !== $slug_key && ! isset( $map['slugs'][ $slug_key ] ) ) {
			$map['slugs'][ $slug_key ] = $entry;
		}
	}
	private function failure_message( string $taxonomy, array $resolved ): string {
		if ( '' === (string) $resolved['error'] ) {
			return '';
		}
		$label = self::label( $taxonomy );
		return sprintf(
			__( '%1$s「%2$s」を設定できませんでした（%3$s）。', GACHIREAI_TEXT_DOMAIN ),
			$label,
			(string) $resolved['name'],
			(string) $resolved['error']
		);
	}
	public static function normalize_name( string $name ): string {
		$normalized = function_exists( 'mb_convert_kana' ) ? mb_convert_kana( $name, 'as', 'UTF-8' ) : $name;
		$normalized = str_replace( "\u{FF0D}", '-', $normalized );
		return (string) preg_replace( '/\s+/u', ' ', mb_strtolower( trim( $normalized ) ) );
	}
	private static function normalize_slug( string $slug ): string {
		return strtolower( trim( $slug ) );
	}
	private function clamp( string $name ): string {
		return mb_substr( $name, 0, self::NAME_MAX );
	}
}

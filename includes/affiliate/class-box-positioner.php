<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Affiliate;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Box_Positioner {
	private const META_BOXES = 'gachireai_affiliate_boxes';
	public static function shortcode_block( string $type ): string {
		$shortcode = sprintf( '[%s type="%s"]', Box_Shortcode::TAG, $type );
		return trim(
			( new Div_Renderer() )->render(
				'article/block-shortcode',
				array( 'block_shortcode' => $shortcode )
			)
		);
	}
	public static function position_shortcodes( string $content, array $positions ): string {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $content;
		}
		$blocks = self::remove_shortcode_blocks( (array) parse_blocks( $content ) );
		if ( array() === $positions ) {
			return (string) serialize_blocks( $blocks );
		}
		return (string) serialize_blocks( self::insert_blocks( $blocks, $positions ) );
	}
	public static function reposition_all( array $positions ): int {
		if (
			! function_exists( 'get_posts' )
			|| ! function_exists( 'get_post_field' )
			|| ! function_exists( 'wp_update_post' )
		) {
			return 0;
		}
		$post_ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => self::META_BOXES, 
				'suppress_filters' => false,
			)
		);
		$updated = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$content = (string) get_post_field( 'post_content', $post_id );
			if ( '' === trim( $content ) ) {
				continue;
			}
			try {
				$new_content = self::position_shortcodes( $content, $positions );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( $new_content === $content ) {
				continue;
			}
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $new_content,
					)
				),
				true
			);
			if ( ! is_wp_error( $result ) ) {
				++$updated;
			}
		}
		return $updated;
	}
	private static function remove_shortcode_blocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( self::is_affiliate_shortcode( $block ) ) {
				continue;
			}
			if ( self::is_empty_block( $block ) ) {
				continue;
			}
			if (
				isset( $block['innerBlocks'] )
				&& is_array( $block['innerBlocks'] )
				&& array() !== $block['innerBlocks']
			) {
				$block['innerBlocks'] = self::remove_shortcode_blocks( $block['innerBlocks'] );
			}
			$out[] = $block;
		}
		return $out;
	}
	private static function is_empty_block( array $block ): bool {
		$name = $block['blockName'] ?? null;
		if ( null !== $name && '' !== (string) $name ) {
			return false;
		}
		return '' === trim( (string) ( $block['innerHTML'] ?? '' ) );
	}
	private static function is_affiliate_shortcode( array $block ): bool {
		if ( 'core/shortcode' !== (string) ( $block['blockName'] ?? '' ) ) {
			return false;
		}
		return str_contains( (string) ( $block['innerHTML'] ?? '' ), '[' . Box_Shortcode::TAG );
	}
	private static function insert_blocks( array $blocks, array $positions ): array {
		$before      = in_array( Card_Settings::POSITION_BEFORE_CONTENT, $positions, true );
		$after_intro = in_array( Card_Settings::POSITION_AFTER_INTRO, $positions, true );
		$each_h2     = in_array( Card_Settings::POSITION_AFTER_EACH_H2, $positions, true );
		$middle      = in_array( Card_Settings::POSITION_MIDDLE, $positions, true );
		$after       = in_array( Card_Settings::POSITION_AFTER_CONTENT, $positions, true );
		$cache = array();
		$block = static function ( string $type ) use ( &$cache ): array {
			if ( ! isset( $cache[ $type ] ) ) {
				$cache[ $type ] = self::block( $type );
			}
			return $cache[ $type ];
		};
		$total = count( $blocks );
		$out   = array();
		if ( 0 === $total ) {
			if ( $before || $after_intro || $each_h2 || $middle || $after ) {
				$out[] = $block( 'cta' );
			}
			return $out;
		}
		$middle_index = (int) floor( $total / 2 );
		if ( $before ) {
			$out[] = $block( 'lead' );
		}
		foreach ( $blocks as $index => $current ) {
			if ( $middle && $index === $middle_index ) {
				$out[] = $block( 'lead' );
			}
			$out[] = $current;
			if ( $after_intro && 0 === $index ) {
				$out[] = $block( 'lead' );
			}
			if ( $each_h2 && self::is_h2( $current ) ) {
				$out[] = $block( 'lead' );
			}
		}
		if ( $after ) {
			$out[] = $block( 'cta' );
		}
		return $out;
	}
	private static function is_h2( array $block ): bool {
		if ( 'core/heading' !== (string) ( $block['blockName'] ?? '' ) ) {
			return false;
		}
		if ( isset( $block['attrs']['level'] ) ) {
			return 2 === (int) $block['attrs']['level'];
		}
		return 1 === preg_match( '/^\s*<h2\b/i', (string) ( $block['innerHTML'] ?? '' ) );
	}
	private static function block( string $type ): array {
		$parsed = (array) parse_blocks( self::shortcode_block( $type ) );
		if ( isset( $parsed[0] ) && is_array( $parsed[0] ) ) {
			return $parsed[0];
		}
		$shortcode = sprintf( '[%s type="%s"]', Box_Shortcode::TAG, $type );
		return array(
			'blockName'    => 'core/shortcode',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $shortcode,
			'innerContent' => array( $shortcode ),
		);
	}
}

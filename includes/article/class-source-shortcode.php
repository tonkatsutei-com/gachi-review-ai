<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Source_Shortcode {
	public const TAG = 'gachireai_source';
	public const META_CREDITS = 'gachireai_source_credits';
	public const KIND_CITE = 'cite';
	public const KIND_NOTE = 'note';
	public const KIND_NOTE_LIST = 'note-list';
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}
	public static function render( $atts, $content = null, $tag = '' ): string {
		$atts = shortcode_atts(
			array(
				'kind'    => self::KIND_NOTE,
				'review'  => '',
				'reviews' => '',
			),
			$atts,
			self::TAG
		);
		$kind = (string) ( $atts['kind'] ?? self::KIND_NOTE );
		if ( ! in_array( $kind, array( self::KIND_CITE, self::KIND_NOTE, self::KIND_NOTE_LIST ), true ) ) {
			return '';
		}
		$mode = Source_Settings::credit_mode();
		if ( self::KIND_CITE === $kind ) {
			if ( 'on' !== $mode && 'quote' !== $mode ) {
				return '';
			}
		} elseif ( 'on' !== $mode ) {
			return '';
		}
		$post_id = (int) get_the_ID();
		if ( 0 >= $post_id ) {
			return '';
		}
		$credits = get_post_meta( $post_id, self::META_CREDITS, true );
		if ( ! is_array( $credits ) || array() === $credits ) {
			return '';
		}
		$plain = current_user_can( 'manage_options' );
		if ( self::KIND_CITE === $kind ) {
			return self::render_cite( $credits, (int) ( $atts['review'] ?? 0 ), $plain );
		}
		return self::render_note(
			$credits,
			(string) ( $atts['reviews'] ?? '' ),
			self::KIND_NOTE_LIST === $kind,
			$plain
		);
	}
	private static function render_cite( array $credits, int $review, bool $plain = false ): string {
		$credit = $review > 0 && isset( $credits[ $review ] ) ? (array) $credits[ $review ] : array();
		if ( array() === $credit ) {
			return '';
		}
		$url   = self::credit_url( $credit, $plain );
		$icon  = (string) ( $credit['icon_url'] ?? '' );
		$label = (string) ( $credit['label'] ?? '' );
		if ( '' === $label ) {
			return '';
		}
		try {
			return trim(
				( new Div_Renderer() )->render(
					'article/source-attribution',
					array(
						'kind_cite'      => true,
						'kind_note'      => false,
						'kind_note_list' => false,
						'credits'        => array(),
						'cite_has_url'   => '' !== $url,
						'cite_url'       => esc_url( $url ),
						'cite_has_icon'  => '' !== $icon,
						'cite_icon_url'  => esc_url( $icon ),
						'cite_label'     => esc_html( $label ),
					)
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	private static function render_note( array $credits, string $reviews, bool $is_list, bool $plain = false ): string {
		$numbers = array_map( 'intval', explode( ',', $reviews ) );
		$items   = self::note_credits( $credits, $numbers );
		if ( array() === $items ) {
			return '';
		}
		$model_credits = array();
		foreach ( $items as $item ) {
			$icon = (string) ( $item['icon_url'] ?? '' );
			$model_credits[] = array(
				'credit_url'      => esc_url( self::credit_url( $item, $plain ) ),
				'credit_has_icon' => '' !== $icon,
				'credit_icon_url' => esc_url( $icon ),
				'credit_label'    => esc_html( (string) ( $item['label'] ?? '' ) ),
			);
		}
		try {
			return trim(
				( new Div_Renderer() )->render(
					'article/source-attribution',
					array(
						'kind_cite'      => false,
						'kind_note'      => ! $is_list,
						'kind_note_list' => $is_list,
						'credits'        => $model_credits,
						'cite_has_url'   => false,
						'cite_url'       => '',
						'cite_has_icon'  => false,
						'cite_icon_url'  => '',
						'cite_label'     => '',
					)
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
	private static function credit_url( array $credit, bool $plain ): string {
		if ( $plain ) {
			$original = trim( (string) ( $credit['original_url'] ?? '' ) );
			if ( '' !== $original ) {
				return $original;
			}
		}
		return trim( (string) ( $credit['url'] ?? '' ) );
	}
	private static function note_credits( array $credits, array $numbers ): array {
		$items = array();
		$seen  = array();
		foreach ( $numbers as $number ) {
			if ( $number <= 0 || ! isset( $credits[ $number ] ) ) {
				continue;
			}
			$credit = (array) $credits[ $number ];
			$label  = (string) ( $credit['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$url = (string) ( $credit['url'] ?? '' );
			$key = '' !== $url ? $url : ( (string) ( $credit['source'] ?? '' ) ) . ':' . $label;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$items[]      = $credit;
		}
		return $items;
	}
}

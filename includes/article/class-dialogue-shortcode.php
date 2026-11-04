<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Template\Div_Renderer;
final class Dialogue_Shortcode {
	public const TAG = 'gachireai_dialogue';
	public const SPEAKER_MENTOR = 'mentor';
	public const SPEAKER_MENTEE = 'mentee';
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}
	public static function render( $atts, $content = null, $tag = '' ): string {
		$atts = shortcode_atts(
			array( 'speaker' => '' ),
			$atts,
			self::TAG
		);
		$speaker = strtolower( trim( (string) ( $atts['speaker'] ?? '' ) ) );
		if ( ! in_array( $speaker, array( self::SPEAKER_MENTOR, self::SPEAKER_MENTEE ), true ) ) {
			return '';
		}
		$post_id = (int) get_the_ID();
		if ( 0 >= $post_id ) {
			return '';
		}
		$settings = new Settings();
		$article  = (array) $settings->get( 'article', array() );
		$dialogue = is_array( $article['dialogue'] ?? null ) ? $article['dialogue'] : array();
		$mentee   = is_array( $dialogue['mentee'] ?? null ) ? $dialogue['mentee'] : array();
		$my       = ( new Site_Profile() )->get_my_persona();
		if ( self::SPEAKER_MENTOR === $speaker ) {
			$name     = trim( (string) ( $my['name'] ?? '' ) );
			$image_id = absint( $my['photo'] ?? 0 );
			$fallback = __( 'メンター', GACHIREAI_TEXT_DOMAIN );
		} else {
			$name     = trim( (string) ( $mentee['name'] ?? '' ) );
			$image_id = absint( $mentee['image'] ?? 0 );
			$fallback = __( 'メンティー', GACHIREAI_TEXT_DOMAIN );
		}
		$url = ( $image_id > 0 && function_exists( 'wp_get_attachment_image_url' ) )
			? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' )
			: '';
		try {
			return trim(
				( new Div_Renderer() )->render(
					'article/dialogue-persona',
					array(
						'persona_speaker'   => esc_attr( $speaker ),
						'persona_has_image' => '' !== $url,
						'persona_image_url' => esc_url( $url ),
						'persona_name'      => esc_html( '' !== $name ? $name : $fallback ),
					)
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}

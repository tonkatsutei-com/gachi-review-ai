<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\License;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Box_Renderer;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Capabilities {
	private const ARTICLE_TYPES = array( 'summary', 'quote', 'experience' );
	private const PRO_ARTICLE_TYPES = array( 'dialogue' );
	public const IMAGE_NONE = 'none';
	public const IMAGE_GENERATE = 'generate';
	private string $tier;
	public function __construct( string $tier ) {
		$this->tier = Status::normalize_tier( $tier );
	}
	public function tier(): string {
		return $this->tier;
	}
	public function is_pro(): bool {
		return Status::TIER_PRO === $this->tier;
	}
	public function is_basic_or_higher(): bool {
		return in_array( $this->tier, array( Status::TIER_PRO, Status::TIER_BASIC ), true );
	}
	public function is_free(): bool {
		return Status::TIER_FREE === $this->tier;
	}
	public function allowed_ecs(): array {
		if ( $this->is_free() ) {
			return array( Product_Selector::EC_RAKUTEN );
		}
		return array( Product_Selector::EC_RAKUTEN, Product_Selector::EC_YAHOO, Product_Selector::EC_AMAZON );
	}
	public function can_use_ec( string $ec ): bool {
		return in_array( $ec, $this->allowed_ecs(), true );
	}
	public function can_collect_source( string $source ): bool {
		$source = trim( $source );
		if ( '' === $source || 'ai' === $source ) {
			return true;
		}
		if ( ! $this->is_free() ) {
			return true;
		}
		return false;
	}
	public function allowed_article_types(): array {
		if ( $this->is_free() ) {
			return array( 'summary' );
		}
		if ( $this->is_pro() ) {
			return array_merge( self::ARTICLE_TYPES, self::PRO_ARTICLE_TYPES );
		}
		return self::ARTICLE_TYPES;
	}
	public function can_use_article_type( string $type ): bool {
		return in_array( $type, $this->allowed_article_types(), true );
	}
	public function can_use_dialogue(): bool {
		return $this->is_pro();
	}
	public function allowed_card_patterns(): array {
		$patterns = array_keys( Box_Renderer::patterns() );
		return $this->is_free() ? array( Box_Renderer::DEFAULT_PATTERN ) : $patterns;
	}
	public function can_use_card_pattern( string $pattern ): bool {
		return in_array( $pattern, $this->allowed_card_patterns(), true );
	}
	public function can_auto_post(): bool {
		return $this->is_pro();
	}
	public function can_use_cron(): bool {
		return $this->is_pro();
	}
	public function can_judge_with_jev(): bool {
		return $this->is_basic_or_higher();
	}
	public function can_generate_image(): bool {
		return $this->is_basic_or_higher();
	}
	public function image_background_mode(): string {
		return $this->is_free() ? self::IMAGE_NONE : self::IMAGE_GENERATE;
	}
	public function can_use_multisite(): bool {
		return $this->is_pro();
	}
	public function can_fetch_prompts(): bool {
		return $this->is_basic_or_higher();
	}
	public function bundles_prompts(): bool {
		return $this->is_free();
	}
	public function allowed_sources(): array {
		$all = array_merge(
			array( 'ec', 'ai' ),
			Source_Settings::PARTNER_SNS,
			Source_Settings::DIRECT_SOURCES
		);
		if ( ! $this->is_free() ) {
			return $all;
		}
		return array_values( array_filter( $all, array( $this, 'can_collect_source' ) ) );
	}
}

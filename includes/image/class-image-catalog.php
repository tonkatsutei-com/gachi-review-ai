<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
final class Image_Catalog {
	public const CLOUDFLARE = 'cloudflare';
	public const GOOGLE     = 'google';
	public const OPENAI     = 'openai';
	public const STYLE_CINEMATIC = 'cinematic';
	public const STYLE_EDITORIAL = 'editorial';
	public const STYLE_3D        = 'minimal3d';
	public const STYLE_FLAT      = 'flat';
	private const PROVIDERS = array(
		self::CLOUDFLARE => array(
			'label'     => 'Cloudflare Workers AI',
			'models'    => array(
				'@cf/black-forest-labs/flux-1-schnell',
			),
			'cost_hint' => '約0.1円/枚・無料枠あり',
		),
		self::GOOGLE     => array(
			'label'     => 'Google AI Studio',
			'models'    => array(
				'gemini-3.1-flash-lite-image',
				'gemini-3.1-flash-image',
				'gemini-nano-banana-2.1',
			),
			'cost_hint' => '約5円/枚',
		),
		self::OPENAI     => array(
			'label'     => 'OpenAI',
			'models'    => array(
				'gpt-image-2.5-flare',
				'gpt-image-2.5-sunburst',
				'gpt-image-2',
			),
			'cost_hint' => 'トークン課金（高め）',
		),
	);
	private const STYLES = array(
		self::STYLE_CINEMATIC => array(
			'label'  => '実写風（シネマティック）',
			'prompt' => 'Cinematic editorial lifestyle photography, natural window light, shallow depth of field, realistic textures, warm and calm mood, candid magazine composition.',
		),
		self::STYLE_EDITORIAL => array(
			'label'  => '雑誌風（エディトリアル）',
			'prompt' => 'Minimal editorial magazine photography, clean composition, soft neutral palette, calm and sophisticated, generous negative space.',
		),
		self::STYLE_3D        => array(
			'label'  => 'ミニマル3Dイラスト',
			'prompt' => 'Minimal 3D illustration, soft clay render, pastel colors, gentle shadows, clean geometric shapes, modern and friendly.',
		),
		self::STYLE_FLAT      => array(
			'label'  => 'フラットイラスト',
			'prompt' => 'Modern flat vector illustration, bold simple shapes, limited palette, clean background, friendly and clear.',
		),
	);
	public static function providers(): array {
		$providers = array();
		foreach ( self::PROVIDERS as $slug => $entry ) {
			$providers[ (string) $slug ] = (string) $entry['label'];
		}
		return $providers;
	}
	public static function provider_choices(): array {
		$choices = array();
		foreach ( self::PROVIDERS as $slug => $entry ) {
			$choices[ (string) $slug ] = sprintf(
				'%1$s（%2$s）',
				(string) $entry['label'],
				(string) $entry['cost_hint']
			);
		}
		return $choices;
	}
	public static function has( string $provider ): bool {
		return isset( self::PROVIDERS[ $provider ] );
	}
	public static function label( string $provider ): string {
		return (string) ( self::PROVIDERS[ $provider ]['label'] ?? $provider );
	}
	public static function models( string $provider ): array {
		return array_values( (array) ( self::PROVIDERS[ $provider ]['models'] ?? array() ) );
	}
	public static function all_models(): array {
		$models = array();
		foreach ( array_keys( self::PROVIDERS ) as $provider ) {
			foreach ( self::models( (string) $provider ) as $model ) {
				$models[] = $model;
			}
		}
		return array_values( array_unique( $models ) );
	}
	public static function default_model( string $provider ): string {
		$models = self::models( $provider );
		return (string) ( $models[0] ?? '' );
	}
	public static function normalize_model( string $provider, string $model ): string {
		$model = trim( $model );
		if ( '' === $model ) {
			return self::default_model( $provider );
		}
		if ( in_array( $model, self::models( $provider ), true ) ) {
			return $model;
		}
		if ( self::CLOUDFLARE === $provider ) {
			return 1 === preg_match( '#^@cf/[a-z0-9._-]+/[a-z0-9._-]+$#i', $model )
				? $model
				: self::default_model( $provider );
		}
		if ( self::GOOGLE === $provider ) {
			if ( str_starts_with( $model, 'gpt-image-' ) || str_starts_with( $model, '@cf/' ) ) {
				return self::default_model( $provider );
			}
			return 1 === preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $model )
				? $model
				: self::default_model( $provider );
		}
		if ( self::OPENAI === $provider ) {
			return 1 === preg_match( '/^gpt-image-[a-z0-9._-]+$/i', $model )
				? $model
				: self::default_model( $provider );
		}
		return '';
	}
	public static function styles(): array {
		$styles = array();
		foreach ( self::STYLES as $slug => $entry ) {
			$styles[ (string) $slug ] = (string) $entry['label'];
		}
		return $styles;
	}
	public static function normalize_style( string $style ): string {
		return isset( self::STYLES[ $style ] ) ? $style : self::STYLE_CINEMATIC;
	}
	public static function style_prompt( string $style ): string {
		$style = self::normalize_style( $style );
		return (string) ( self::STYLES[ $style ]['prompt'] ?? '' );
	}
}

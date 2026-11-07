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
	public const STYLE_CUSTOM    = 'custom';
	public const FAMILY_PHOTOREAL    = 'photoreal';
	public const FAMILY_ILLUSTRATION = 'illustration';
	public const FAMILY_3DCG         = '3dcg';
	public const FAMILY_ANIME        = 'anime';
	public const FAMILY_PAINT        = 'paint';
	private const FAMILIES = array(
		self::FAMILY_PHOTOREAL    => array(
			'label'  => '実写・フォトリアル',
			'styles' => array( self::STYLE_CINEMATIC, self::STYLE_EDITORIAL, 'lifestyle', 'cozy', 'minimal', 'luxury', 'vintage-film', 'bright', 'moody', 'candid' ),
		),
		self::FAMILY_ILLUSTRATION => array(
			'label'  => 'イラスト・フラット',
			'styles' => array( self::STYLE_FLAT, 'line-art', 'doodle', 'sticker', 'isometric', 'geometric', 'retro-pop', 'hand-drawn', 'minimal-icon', 'storybook' ),
		),
		self::FAMILY_3DCG         => array(
			'label'  => '3D・CG',
			'styles' => array( self::STYLE_3D, 'clay', 'isometric3d', 'low-poly', 'voxel', 'glossy', 'toy', 'abstract3d', 'studio', 'holographic' ),
		),
		self::FAMILY_ANIME        => array(
			'label'  => 'アニメ・マンガ',
			'styles' => array( 'anime', 'manga', 'watercolor-anime', 'chibi', 'cel-shaded', 'retro-anime', 'pastel-anime', 'shonen', 'mecha', 'ghibli-like' ),
		),
		self::FAMILY_PAINT        => array(
			'label'  => '絵画・アート',
			'styles' => array( 'watercolor', 'oil', 'gouache', 'pastel', 'pencil', 'ink', 'acrylic', 'ukiyo-e', 'impressionist', 'digital-paint' ),
		),
	);
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
			'family' => self::FAMILY_PHOTOREAL,
		),
		self::STYLE_EDITORIAL => array(
			'label'  => '雑誌風（エディトリアル）',
			'prompt' => 'Minimal editorial magazine photography, clean composition, soft neutral palette, calm and sophisticated, generous negative space.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		self::STYLE_3D        => array(
			'label'  => 'ミニマル3Dイラスト',
			'prompt' => 'Minimal 3D illustration, soft clay render, pastel colors, gentle shadows, clean geometric shapes, modern and friendly.',
			'family' => self::FAMILY_3DCG,
		),
		self::STYLE_FLAT      => array(
			'label'  => 'フラットイラスト',
			'prompt' => 'Modern flat vector illustration, bold simple shapes, limited palette, clean background, friendly and clear.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'lifestyle'    => array(
			'label'  => '実写（ライフスタイル）',
			'prompt' => 'Candid lifestyle photography of an everyday moment at home, natural light, authentic and warm, shallow depth of field, editorial magazine feel.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		'cozy'         => array(
			'label'  => '実写（コージー）',
			'prompt' => 'Cozy home lifestyle photography, warm soft light, blankets and natural textures, calm and inviting mood, natural composition.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		'minimal'      => array(
			'label'  => '実写（ミニマル）',
			'prompt' => 'Minimalist photography, clean neutral background, soft diffused light, generous negative space, refined and calm.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		'luxury'       => array(
			'label'  => '実写（高級感）',
			'prompt' => 'Luxury lifestyle photography, elegant dark tones, soft dramatic lighting, premium textures, sophisticated and calm.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		'vintage-film' => array(
			'label'  => '実写（ヴィンテージフィルム）',
			'prompt' => 'Vintage film photography, warm muted colors, subtle grain, soft light leaks, nostalgic and calm mood.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		'bright'       => array(
			'label'  => '実写（明るい）',
			'prompt' => 'Bright airy lifestyle photography, abundant natural light, white tones, clean and fresh, optimistic mood.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		'moody'        => array(
			'label'  => '実写（ムーディー）',
			'prompt' => 'Moody atmospheric photography, low-key lighting, deep shadows, rich colors, cinematic and calm.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		'candid'       => array(
			'label'  => '実写（スナップ）',
			'prompt' => 'Candid snapshot photography, spontaneous everyday moment, natural light, slightly imperfect and authentic, editorial feel.',
			'family' => self::FAMILY_PHOTOREAL,
		),
		'line-art'      => array(
			'label'  => 'ラインドロー（線画）',
			'prompt' => 'Clean line art illustration, monochrome outline, simple shapes, minimal background, elegant and clear.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'doodle'        => array(
			'label'  => 'ドローイング（手描き風）',
			'prompt' => 'Playful hand-drawn doodle illustration, loose sketchy lines, light colors, friendly and casual.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'sticker'       => array(
			'label'  => 'ステッカー',
			'prompt' => 'Cute sticker-style illustration, bold outline, flat bright colors, white border, playful and pop.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'isometric'     => array(
			'label'  => 'アイソメトリック',
			'prompt' => 'Isometric flat illustration, geometric perspective, clean shapes, soft palette, modern and tidy.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'geometric'     => array(
			'label'  => 'ジオメトリック',
			'prompt' => 'Geometric abstract illustration, bold simple forms, limited palette, balanced composition, modern and calm.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'retro-pop'     => array(
			'label'  => 'レトロポップ',
			'prompt' => 'Retro pop illustration, bold playful colors, vintage shapes, halftone texture, energetic and fun.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'hand-drawn'    => array(
			'label'  => '手描き（水彩ペン）',
			'prompt' => 'Hand-drawn illustration with watercolor and pen, soft washes, visible strokes, warm and personal.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'minimal-icon'  => array(
			'label'  => 'ミニマルアイコン',
			'prompt' => 'Minimal flat icon style illustration, simple symbol, two-color palette, clean background, clear and modern.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'storybook'     => array(
			'label'  => '絵本',
			'prompt' => 'Storybook illustration, soft warm colors, gentle textures, whimsical and cozy, hand-painted feel.',
			'family' => self::FAMILY_ILLUSTRATION,
		),
		'clay'          => array(
			'label'  => 'クレイ（ねんど調）',
			'prompt' => 'Soft clay render 3D illustration, rounded shapes, pastel colors, soft shadows, playful and friendly.',
			'family' => self::FAMILY_3DCG,
		),
		'isometric3d'   => array(
			'label'  => 'アイソメ3D',
			'prompt' => 'Isometric 3D render, clean geometric diorama, soft studio lighting, pastel palette, modern and tidy.',
			'family' => self::FAMILY_3DCG,
		),
		'low-poly'      => array(
			'label'  => 'ローポリ',
			'prompt' => 'Low poly 3D render, faceted geometric surfaces, simple palette, soft lighting, modern and calm.',
			'family' => self::FAMILY_3DCG,
		),
		'voxel'         => array(
			'label'  => 'ボクセル',
			'prompt' => 'Voxel 3D render, blocky cube-based shapes, bright colors, playful and retro-game feel.',
			'family' => self::FAMILY_3DCG,
		),
		'glossy'        => array(
			'label'  => 'グロッシー',
			'prompt' => 'Glossy 3D render, smooth reflective surfaces, vibrant colors, soft studio light, premium and modern.',
			'family' => self::FAMILY_3DCG,
		),
		'toy'           => array(
			'label'  => 'トイ（おもちゃ風）',
			'prompt' => 'Toy-like 3D render, plastic miniatures, soft shadows, bright colors, cute and friendly.',
			'family' => self::FAMILY_3DCG,
		),
		'abstract3d'    => array(
			'label'  => '抽象3D',
			'prompt' => 'Abstract 3D render, organic flowing shapes, gradient colors, soft light, modern and artistic.',
			'family' => self::FAMILY_3DCG,
		),
		'studio'        => array(
			'label'  => 'スタジオ3D',
			'prompt' => 'Studio 3D render, clean seamless background, controlled soft lighting, realistic materials, refined and calm.',
			'family' => self::FAMILY_3DCG,
		),
		'holographic'   => array(
			'label'  => 'ホログラフィック',
			'prompt' => 'Holographic 3D render, iridescent pastel gradients, smooth surfaces, futuristic and calm.',
			'family' => self::FAMILY_3DCG,
		),
		'anime'           => array(
			'label'  => 'アニメ',
			'prompt' => 'Modern anime illustration, clean cel shading, vivid colors, expressive characters, bright and friendly.',
			'family' => self::FAMILY_ANIME,
		),
		'manga'           => array(
			'label'  => 'マンガ',
			'prompt' => 'Manga style illustration, black and white line work, screentone shading, dynamic composition, expressive.',
			'family' => self::FAMILY_ANIME,
		),
		'watercolor-anime' => array(
			'label'  => '水彩アニメ',
			'prompt' => 'Watercolor anime illustration, soft washes, gentle colors, dreamy light, warm and gentle.',
			'family' => self::FAMILY_ANIME,
		),
		'chibi'           => array(
			'label'  => 'ちびキャラ',
			'prompt' => 'Chibi anime style, cute deformed characters, big heads, pastel colors, playful and adorable.',
			'family' => self::FAMILY_ANIME,
		),
		'cel-shaded'      => array(
			'label'  => 'セル調',
			'prompt' => 'Cel-shaded anime illustration, flat color blocks, crisp outlines, soft shadows, clean and modern.',
			'family' => self::FAMILY_ANIME,
		),
		'retro-anime'     => array(
			'label'  => 'レトロアニメ',
			'prompt' => 'Retro 1980s anime style, warm faded colors, soft grain, nostalgic character design, vintage mood.',
			'family' => self::FAMILY_ANIME,
		),
		'pastel-anime'    => array(
			'label'  => 'パステルアニメ',
			'prompt' => 'Pastel anime illustration, soft light colors, gentle shading, dreamy atmosphere, cute and calm.',
			'family' => self::FAMILY_ANIME,
		),
		'shonen'          => array(
			'label'  => '少年マンガ',
			'prompt' => 'Shonen manga style, dynamic action lines, strong contrasts, energetic composition, bold and expressive.',
			'family' => self::FAMILY_ANIME,
		),
		'mecha'           => array(
			'label'  => 'メカ・SF',
			'prompt' => 'Mecha anime style, detailed mechanical shapes, futuristic sci-fi mood, cool metallic colors, dynamic.',
			'family' => self::FAMILY_ANIME,
		),
		'ghibli-like'     => array(
			'label'  => 'ジブリ調',
			'prompt' => 'Ghibli-inspired anime illustration, lush natural scenery, soft warm colors, gentle detailed backgrounds, nostalgic and calm.',
			'family' => self::FAMILY_ANIME,
		),
		'watercolor'       => array(
			'label'  => '水彩画',
			'prompt' => 'Soft watercolor painting, translucent washes, gentle bleeding edges, light warm palette, calm and artistic.',
			'family' => self::FAMILY_PAINT,
		),
		'oil'              => array(
			'label'  => '油彩画',
			'prompt' => 'Oil painting style, rich textured brushstrokes, deep colors, classic and refined.',
			'family' => self::FAMILY_PAINT,
		),
		'gouache'          => array(
			'label'  => 'ガッシュ',
			'prompt' => 'Gouache painting, opaque matte colors, flat painterly shapes, soft palette, warm and modern.',
			'family' => self::FAMILY_PAINT,
		),
		'pastel'           => array(
			'label'  => 'パステル画',
			'prompt' => 'Pastel drawing, soft powdery texture, light colors, gentle shading, dreamy and calm.',
			'family' => self::FAMILY_PAINT,
		),
		'pencil'           => array(
			'label'  => '鉛筆画',
			'prompt' => 'Pencil sketch drawing, fine graphite shading, monochrome, delicate and expressive.',
			'family' => self::FAMILY_PAINT,
		),
		'ink'              => array(
			'label'  => 'ペン画・墨',
			'prompt' => 'Ink drawing, bold black strokes, sumi-e influence, high contrast, elegant and minimal.',
			'family' => self::FAMILY_PAINT,
		),
		'acrylic'          => array(
			'label'  => 'アクリル画',
			'prompt' => 'Acrylic painting, vivid saturated colors, bold brush texture, modern and energetic.',
			'family' => self::FAMILY_PAINT,
		),
		'ukiyo-e'          => array(
			'label'  => '浮世絵',
			'prompt' => 'Ukiyo-e woodblock print style, flat colors, bold outlines, traditional Japanese patterns, elegant.',
			'family' => self::FAMILY_PAINT,
		),
		'impressionist'    => array(
			'label'  => '印象派',
			'prompt' => 'Impressionist painting, loose visible brushstrokes, luminous natural light, soft color harmony, atmospheric.',
			'family' => self::FAMILY_PAINT,
		),
		'digital-paint'    => array(
			'label'  => 'デジタルペイント',
			'prompt' => 'Digital painting, smooth blended colors, soft light, detailed painterly rendering, modern and polished.',
			'family' => self::FAMILY_PAINT,
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
	public static function families(): array {
		$families = array();
		foreach ( self::FAMILIES as $slug => $entry ) {
			$families[ (string) $slug ] = (string) $entry['label'];
		}
		return $families;
	}
	public static function styles_in_family( string $family ): array {
		if ( ! isset( self::FAMILIES[ $family ] ) ) {
			return array();
		}
		$styles = array();
		foreach ( (array) self::FAMILIES[ $family ]['styles'] as $slug ) {
			$slug = (string) $slug;
			if ( isset( self::STYLES[ $slug ] ) ) {
				$styles[ $slug ] = (string) self::STYLES[ $slug ]['label'];
			}
		}
		return $styles;
	}
	public static function family_of( string $style ): string {
		if ( self::STYLE_CUSTOM === $style ) {
			return self::STYLE_CUSTOM;
		}
		$style = self::normalize_style( $style );
		return (string) ( self::STYLES[ $style ]['family'] ?? self::FAMILY_PHOTOREAL );
	}
	public static function random_style( string $family ): string {
		$styles = array_keys( self::styles_in_family( $family ) );
		if ( array() === $styles ) {
			return self::STYLE_CINEMATIC;
		}
		return (string) $styles[ array_rand( $styles ) ];
	}
	public static function normalize_style( string $style ): string {
		if ( self::STYLE_CUSTOM === $style ) {
			return self::STYLE_CUSTOM;
		}
		return isset( self::STYLES[ $style ] ) ? $style : self::STYLE_CINEMATIC;
	}
	public static function style_prompt( string $style ): string {
		if ( self::STYLE_CUSTOM === $style ) {
			return '';
		}
		$style = self::normalize_style( $style );
		return (string) ( self::STYLES[ $style ]['prompt'] ?? '' );
	}
}

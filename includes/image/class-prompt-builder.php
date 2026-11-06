<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager as Llm_Manager;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Prompt_Builder {
	private const NEGATIVE = 'No text, no letters, no numbers, no logos, no brand marks, no watermarks, no price tags, no discount badges, no banners, no call-to-action buttons, no product packshots, no e-commerce white background, no identifiable faces.';
	private Settings $settings;
	private Site_Profile $profile;
	public function __construct( Settings $settings, ?Site_Profile $profile = null ) {
		$this->settings = $settings;
		$this->profile  = $profile ?? new Site_Profile();
	}
	public function favicon_prompt(): string {
		$image   = $this->settings->image();
		$context = $this->site_context();
		$fallback = 'A simple flat vector app icon symbolizing a Japanese product review website, bold geometric shapes, high contrast, centered symbol, solid background, no text, no letters, no logo, no watermark.';
		$prompt = '1' === $image['prompt_llm']
			? $this->ask_llm(
				'You are an art director. Write ONE English image prompt for a favicon (app icon). Output only the prompt, max 45 words.',
				"Website context:\n" . $context . "\n\nRules:\n- A simple, flat vector symbol (not a photo, not a scene).\n- Bold geometric shapes, high contrast, centered, readable at small size.\n- No text, letters, numbers, logos, or watermarks.\n- Solid background."
			)
			: '';
		if ( '' === $prompt ) {
			$prompt = $fallback;
		}
		return $this->finish( $prompt, false );
	}
	public function eyecatch_prompt( string $title, array $product = array() ): string {
		$image    = $this->settings->image();
		$style    = Image_Catalog::normalize_style( $image['style'] );
		$style_en = Image_Catalog::style_prompt( $style );
		$side     = 'right' === $image['text_side'] ? 'right' : 'left';
		$copy     = '1' === $image['text_overlay']
			? sprintf( 'Leave the %s 50%% of the frame as clean, uncluttered negative space for a headline.', $side )
			: 'Use a clean, uncluttered composition with generous negative space.';
		$fallback = 'A calm, sunlit Japanese home interior showing the benefit of a better daily routine, a person enjoying the result in the background (hands or back view only, no face), natural window light, soft neutral tones, clean copy space on the ' . $side . ', editorial lifestyle photography, authentic and not advertising-like. ' . $style_en;
		$product_name = self::clean( (string) ( $product['product_name'] ?? '' ), 80 );
		$title        = self::clean( $title, 120 );
		$context      = $this->site_context();
		$user = "Article title: {$title}\n"
			. "Product: {$product_name}\n"
			. "Website context:\n{$context}\n\n"
			. "Requirements:\n"
			. "- Describe the BENEFIT / after-state the reader wants, not the product itself.\n"
			. "- Do not depict the product precisely. No packaging, no brand logos, no packshots.\n"
			. "- NEVER include brand names, product names, model numbers, or trademarks in the prompt (they can trigger the image AI's safety filter and force a generic fallback).\n"
			. "- If a person appears, show only hands, a back view, or a silhouette of a grown-up person. No identifiable face.\n"
			. "- {$copy}\n"
			. "- Natural, candid, editorial lifestyle mood (Instagram / Pinterest / magazine), not a sales banner.\n"
			. "- Style: {$style_en}\n"
			. '- Output one English prompt only, max 70 words.';
		$prompt = '1' === $image['prompt_llm']
			? $this->ask_llm(
				'You are an art director for a Japanese product review site. Write ONE English image prompt. Output only the prompt.',
				$user
			)
			: '';
		if ( '' === $prompt ) {
			$prompt = $fallback;
		}
		return $this->finish( $prompt );
	}
	public function safe_eyecatch_prompt(): string {
		$image = $this->settings->image();
		$side  = 'right' === $image['text_side'] ? 'right' : 'left';
		return $this->finish(
			self::safe_scene( (string) $image['style'] ) . ', clean copy space on the ' . $side . '.'
		);
	}
	public function safe_favicon_prompt(): string {
		return $this->finish(
			'A simple flat vector app icon with bold geometric shapes, high contrast, centered symbol, solid background, no text, no letters, no logo, no watermark.',
			false
		);
	}
	public function top_eyecatch_prompt(): string {
		$image    = $this->settings->image();
		$style    = Image_Catalog::normalize_style( $image['style'] );
		$style_en = Image_Catalog::style_prompt( $style );
		$context  = $this->site_context();
		$fallback = 'A calm, inviting editorial hero image for a Japanese product review website, a tidy modern lifestyle scene with natural window light, soft neutral tones, generous negative space, authentic magazine mood, not advertising-like. ' . $style_en;
		$user = "Website context:\n{$context}\n\n"
			. "Requirements:\n"
			. "- A hero image that represents the WHOLE website (a lifestyle scene, not a product).\n"
			. "- Do not depict products precisely. No packaging, no brand logos, no packshots, no price tags, no banners.\n"
			. "- NEVER include brand names, product names, model numbers, or trademarks in the prompt (they can trigger the image AI's safety filter and force a generic fallback).\n"
			. "- If a person appears, show only hands, a back view, or a silhouette of a grown-up person. No identifiable face.\n"
			. "- Natural, candid, editorial lifestyle mood (Instagram / Pinterest / magazine), not a sales banner.\n"
			. "- Leave generous clean space so the image works as a wide hero image.\n"
			. "- Style: {$style_en}\n"
			. '- Output one English prompt only, max 60 words.';
		$prompt = '1' === $image['prompt_llm']
			? $this->ask_llm(
				'You are an art director for a Japanese product review site. Write ONE English image prompt for the website hero image. Output only the prompt.',
				$user
			)
			: '';
		if ( '' === $prompt ) {
			$prompt = $fallback;
		}
		return $this->finish( $prompt );
	}
	public function safe_top_eyecatch_prompt(): string {
		return $this->finish(
			self::safe_scene( (string) $this->settings->image()['style'] ) . ', generous negative space for a wide hero image.'
		);
	}
	public function profile_prompt(): string {
		$image    = $this->settings->image();
		$style_en = Image_Catalog::style_prompt( $image['style'] );
		$my       = $this->profile->get_my_persona();
		$elements = $this->profile->get_site_elements();
		$gender = 'female' === (string) ( $my['gender'] ?? '' ) ? 'woman' : 'man';
		$name   = self::clean( (string) ( $my['name'] ?? '' ), 80 );
		$fallback = 'A friendly head-and-shoulders portrait of a fictional Japanese product reviewer, natural window light, soft neutral background, warm and trustworthy expression, looking at the camera, no text, no logos, no watermark. ' . $style_en;
		$user = "Fictional persona: {$name}\n"
			. "Gender: {$gender}\n"
			. 'Attributes: ' . self::clean( (string) ( $my['attributes'] ?? '' ), 200 ) . "\n"
			. 'Background: ' . self::clean( (string) ( $my['background'] ?? '' ), 300 ) . "\n"
			. 'Knowledge level: ' . self::clean( (string) ( $my['knowledge_level'] ?? '' ), 120 ) . "\n"
			. 'Writing style: ' . self::clean( (string) ( $my['writing_style'] ?? '' ), 160 ) . "\n"
			. 'Stance: ' . self::clean( (string) ( $my['stance'] ?? '' ), 200 ) . "\n"
			. 'Site: ' . self::clean( (string) ( $elements['description'] ?? '' ), 300 ) . "\n\n"
			. "Requirements:\n"
			. "- A fictional person; do not resemble a real person or celebrity.\n"
			. "- Head-and-shoulders portrait, friendly and trustworthy, natural light, neutral background.\n"
			. "- No text, letters, numbers, logos, watermarks, or product packshots.\n"
			. "- NEVER include brand names, product names, or trademarks in the prompt.\n"
			. "- Apply this art style: {$style_en}\n"
			. '- Output one English prompt only, max 60 words.';
		$prompt = '1' === $image['prompt_llm']
			? $this->ask_llm(
				'You are an art director for a Japanese product review site. Write ONE English image prompt for a fictional persona portrait. Output only the prompt.',
				$user
			)
			: '';
		if ( '' === $prompt ) {
			$prompt = $fallback;
		}
		return $this->finish_portrait( $prompt );
	}
	public function safe_profile_prompt(): string {
		return $this->finish_portrait(
			'A friendly head-and-shoulders portrait of a fictional person, warm and trustworthy expression, natural light, neutral background, no text, no letters, no logos, no watermark.'
		);
	}
	public function dialogue_mentee_prompt( array $character ): string {
		$image    = $this->settings->image();
		$style_en = Image_Catalog::style_prompt( $image['style'] );
		$gender  = self::gender_key( (string) ( $character['gender'] ?? '' ) );
		$noun    = 'female' === $gender ? 'woman' : 'man';
		$pronoun = 'female' === $gender ? 'her' : 'his';
		$decade  = self::age_decade( (string) ( $character['age'] ?? '' ) );
		$age     = '' !== $decade ? ' in ' . $pronoun . ' ' . $decade : '';
		$fallback = 'A friendly head-and-shoulders portrait of a fictional Japanese ' . $noun . $age . ', natural window light, soft neutral background, warm and trustworthy expression, looking at the camera, no text, no logos, no watermark. ' . $style_en;
		$user = "Fictional mentee (a reader of the site):\n"
			. "Gender: {$noun}\n"
			. ( '' !== $decade ? "Age: {$decade}\n" : '' )
			. "\nRequirements:\n"
			. "- A fictional person; do not resemble a real person or celebrity.\n"
			. "- Head-and-shoulders portrait, friendly and trustworthy, natural light, neutral background.\n"
			. "- No text, letters, numbers, logos, watermarks, or product packshots.\n"
			. "- NEVER include brand names, product names, or trademarks in the prompt.\n"
			. "- Apply this art style: {$style_en}\n"
			. '- Output one English prompt only, max 60 words.';
		$prompt = '1' === $image['prompt_llm']
			? $this->ask_llm(
				'You are an art director for a Japanese product review site. Write ONE English image prompt for a fictional reader portrait. Output only the prompt.',
				$user
			)
			: '';
		if ( '' === $prompt ) {
			$prompt = $fallback;
		}
		return $this->finish_portrait( $prompt );
	}
	public function safe_dialogue_mentee_prompt(): string {
		return $this->finish_portrait(
			'A friendly head-and-shoulders portrait of a fictional Japanese person, natural window light, soft neutral background, warm and trustworthy expression, looking at the camera, no text, no logos, no watermark.'
		);
	}
	private static function safe_scene( string $style ): string {
		switch ( Image_Catalog::normalize_style( $style ) ) {
			case Image_Catalog::STYLE_3D:
				return 'A minimal 3D rendered lifestyle scene of a calm, tidy modern room with soft clay textures, pastel colors, gentle shadows, and a sense of everyday comfort';
			case Image_Catalog::STYLE_FLAT:
				return 'A flat vector illustration of a calm, tidy modern lifestyle scene with simple bold shapes, a limited palette, and a friendly, clear mood';
			case Image_Catalog::STYLE_EDITORIAL:
				return 'A minimal editorial lifestyle scene with soft neutral tones, clean composition, and a calm, sophisticated sense of everyday comfort';
			case Image_Catalog::STYLE_CINEMATIC:
			default:
				return 'A cinematic lifestyle scene of a calm, tidy modern room with natural window light, warm tones, and a sense of everyday comfort';
		}
	}
	private static function gender_key( string $gender ): string {
		$gender = mb_strtolower( trim( $gender ) );
		if ( in_array( $gender, array( '女性', '女', 'female', 'woman' ), true ) ) {
			return 'female';
		}
		if ( in_array( $gender, array( '男性', '男', 'male', 'man' ), true ) ) {
			return 'male';
		}
		return '';
	}
	private static function age_decade( string $age ): string {
		if ( 1 === preg_match( '/(\d{1,2})\s*代/u', $age, $m ) ) {
			return $m[1] . 's';
		}
		if ( 1 === preg_match( '/(\d{1,2})\s*(才|歳)/u', $age, $m ) ) {
			$decade = (int) floor( (int) $m[1] / 10 ) * 10;
			return $decade > 0 ? $decade . 's' : '';
		}
		if ( 1 === preg_match( '/(\d{2})/u', $age, $m ) ) {
			$decade = (int) floor( (int) $m[1] / 10 ) * 10;
			return $decade > 0 ? $decade . 's' : '';
		}
		return '';
	}
	private function site_context(): string {
		$elements = $this->profile->get_site_elements();
		$scope    = $this->profile->get_scope();
		$target   = $this->profile->get_target_persona();
		$my       = $this->profile->get_my_persona();
		$parts = array(
			'Site name: ' . self::clean( (string) ( $elements['site_name'] ?? '' ), 80 ),
			'Site description: ' . self::clean( (string) ( $elements['description'] ?? '' ), 300 ),
			'Categories: ' . self::clean( (string) ( $scope['category'] ?? '' ), 200 ),
			'Keywords: ' . self::clean( (string) ( $scope['products'] ?? '' ), 200 ),
			'Reader worries: ' . self::clean( (string) ( $target['worries'] ?? '' ), 200 ),
			'Reader wants: ' . self::clean( (string) ( $target['wants'] ?? '' ), 200 ),
			'Writer tone: ' . self::clean( (string) ( $my['writing_style'] ?? '' ), 120 ),
		);
		return implode( "\n", array_filter( $parts, static fn( string $line ): bool => ! str_ends_with( $line, ': ' ) ) );
	}
	private function ask_llm( string $system, string $user ): string {
		$llm = $this->settings->active_llm();
		if ( '' === trim( (string) ( $llm['api_key'] ?? '' ) ) ) {
			return '';
		}
		try {
			$provider = Llm_Manager::provider( $llm );
			$result   = Llm_Manager::generate(
				$provider,
				$system,
				$user,
				array(
					'temperature' => 0.7,
					'max_tokens'  => 300,
				)
			);
		} catch ( \Throwable $e ) {
			return '';
		}
		$text = trim( (string) ( $result['content'] ?? '' ) );
		$text = (string) preg_replace( '/^```[a-zA-Z]*\s*(.*?)\s*```$/s', '$1', $text );
		$text = trim( $text, " \t\n\r\0\x0B\"'“”‘’" );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		if ( mb_strlen( $text ) < 10 ) {
			return '';
		}
		return $text;
	}
	private function finish( string $prompt, bool $with_style = true ): string {
		$image = $this->settings->image();
		$style = Image_Catalog::style_prompt( $image['style'] );
		$text  = trim( $prompt );
		if ( $with_style && ! str_contains( $text, $style ) ) {
			$text .= ' ' . $style;
		}
		$text .= ' ' . self::NEGATIVE;
		return mb_substr( $text, 0, 1800 );
	}
	private function finish_portrait( string $prompt ): string {
		$image = $this->settings->image();
		$style = Image_Catalog::style_prompt( $image['style'] );
		$text  = trim( $prompt );
		if ( ! str_contains( $text, $style ) ) {
			$text .= ' ' . $style;
		}
		$text .= ' No text, no letters, no numbers, no logos, no brand marks, no watermarks.';
		return mb_substr( $text, 0, 1800 );
	}
	private static function clean( string $text, int $limit ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );
		$text = (string) preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_substr( $text, 0, $limit );
	}
}

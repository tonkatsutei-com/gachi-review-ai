<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Image\Manager as Image_Manager;
use Tonkatsutei\Gachi_Review_AI\Image\Media;
use Tonkatsutei\Gachi_Review_AI\Image\Processor;
use Tonkatsutei\Gachi_Review_AI\Image\Prompt_Builder;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager as Llm_Manager;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Dialogue_Character {
	private const SIZE = 512;
	private const KEYS = array( 'name', 'gender', 'age', 'tone' );
	private Settings $settings;
	private Site_Profile $profile;
	public function __construct( ?Settings $settings = null, ?Site_Profile $profile = null ) {
		$this->settings = $settings ?? new Settings();
		$this->profile  = $profile ?? new Site_Profile();
	}
	public function generate_mentee(): array {
		$character = $this->mentee_character();
		$image     = $this->settings->image();
		$api_key   = $this->settings->image_api_key( $image );
		$result = array(
			'name'          => $character['name'],
			'gender'        => $character['gender'],
			'age'           => $character['age'],
			'tone'          => $character['tone'],
			'attachment_id' => 0,
			'url'           => '',
			'message'       => '',
			'image_error'   => '',
		);
		$fail_behavior = (string) $image['fail_behavior'];
		if ( '' === $api_key ) {
			$image_error = __( '画像生成AIの API キーが未設定です。', GACHIREAI_TEXT_DOMAIN );
			if ( Settings::IMAGE_FAIL_ABORT === $fail_behavior ) {
				throw new \RuntimeException( $image_error );
			}
			$result['image_error'] = $image_error;
			$result['message']     = __( 'メンティーのキャラ設定を生成しました（画像は未生成）。', GACHIREAI_TEXT_DOMAIN );
			return $result;
		}
		$builder  = new Prompt_Builder( $this->settings, $this->profile );
		$prompt   = $builder->dialogue_mentee_prompt( $character );
		$provider = Image_Manager::provider_for( $image, $api_key );
		$started  = microtime( true );
		try {
			$generated = Image_Manager::generate(
				$provider,
				$prompt,
				array(
					'steps'           => (int) $image['steps'],
					'aspect_ratio'    => '1:1',
					'size'            => '1024x1024',
					'quality'         => 'low',
					'output_format'   => 'png',
					'fallback_prompt' => $builder->safe_dialogue_mentee_prompt(),
				)
			);
			$processed = Processor::square( $generated['data'], self::SIZE );
			$name  = $character['name'];
			$title = '' !== $name
				? sprintf(
					__( '%s（メンティー）の画像', GACHIREAI_TEXT_DOMAIN ),
					$name
				)
				: __( 'メンティーの画像', GACHIREAI_TEXT_DOMAIN );
			$attachment_id = Media::store(
				$processed,
				'gachireai-dialogue-mentee-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.png',
				$title,
				$title,
				array(
					'_gachireai_image_kind'         => 'dialogue_mentee',
					'_gachireai_image_prompt'       => $prompt,
					'_gachireai_image_provider'     => (string) ( $image['provider'] ?? '' ),
					'_gachireai_image_model'        => (string) ( $generated['model'] ?? '' ),
					'_gachireai_image_generated_at' => time(),
				)
			);
			$result['attachment_id'] = $attachment_id;
			$result['url']           = (string) wp_get_attachment_image_url( $attachment_id, 'full' );
			$result['message']       = __( 'メンティーを生成しました。', GACHIREAI_TEXT_DOMAIN );
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_INFO,
				__( '対話形式のメンティーを生成しました。', GACHIREAI_TEXT_DOMAIN ),
				array(
					'provider' => (string) ( $image['provider'] ?? '' ),
					'model'    => (string) ( $generated['model'] ?? '' ),
					'kind'     => 'dialogue_mentee',
				),
				0,
				(int) round( ( microtime( true ) - $started ) * 1000 )
			);
		} catch ( \Throwable $e ) {
			$error_message = sprintf(
				__( 'メンティー画像の生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
				mb_substr( $e->getMessage(), 0, 300 )
			);
			if ( Settings::IMAGE_FAIL_ABORT === $fail_behavior ) {
				Event_Logger::record(
					Event_Logger::TYPE_SYSTEM,
					Event_Logger::LEVEL_ERROR,
					$error_message,
					array( 'kind' => 'dialogue_mentee' )
				);
				throw new \RuntimeException( $error_message, 0, $e );
			}
			$result['image_error'] = $e->getMessage();
			$result['message']     = __( 'メンティーのキャラ設定を生成しました（画像の生成に失敗しました）。', GACHIREAI_TEXT_DOMAIN );
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_WARN,
				$error_message,
				array( 'kind' => 'dialogue_mentee' )
			);
		}
		return $result;
	}
	private function mentee_character(): array {
		$llm = $this->settings->active_llm();
		if ( '' === trim( (string) ( $llm['api_key'] ?? '' ) ) ) {
			throw new \RuntimeException( __( 'API キーが未設定のため生成できません。「AI設定」タブで LLM の API キーを保存してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$target      = $this->profile->get_target_persona();
		$my          = $this->profile->get_my_persona();
		$mentor_name = trim( (string) ( $my['name'] ?? '' ) );
		$provider    = Llm_Manager::provider( $llm );
		$data        = Llm_Manager::generate_json(
			$provider,
			self::system_prompt(),
			self::user_prompt( $target, $mentor_name, Site_Generator::random_name_style() ),
			array(
				'temperature' => 0.9,
				'max_tokens'  => 500,
			)
		);
		$character = array(
			'name'   => self::clean( $data['name'] ?? '', 30 ),
			'gender' => self::clean( $data['gender'] ?? '', 20 ),
			'age'    => self::clean( $data['age'] ?? '', 20 ),
			'tone'   => self::clean( $data['tone'] ?? '', 60 ),
		);
		if ( '' === $character['name'] ) {
			throw new \RuntimeException( __( 'AI の応答からメンティーのキャラ設定を取り出せませんでした。もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $character;
	}
	private static function system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトの編集者です。',
				'与えられた「読者（ターゲットペルソナ）」の情報から、記事の対話に登場する「メンティー」（読者側のキャラクター）を作ります。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: ' . implode( ', ', self::KEYS ),
				'name は日本語の自然な呼び名にしてください（20 文字以内）。ありふれた名前（タカシ・ミサキ・ケンタ・タロウ・ハナコなど）は使わず、読者像に合う個性的な呼び名にしてください。',
				'記事の語り手（メンター）とは別の人物にし、メンターの名前は使わないでください。',
				'gender は「女性」「男性」「その他」のいずれかにしてください。',
				'age は「20代」「30代」のような年代表記にしてください（10 文字以内）。特定の年代に偏らないようにしてください。',
				'tone は話し方・口調を 40 文字以内で書いてください（丁寧・砕けた・フランクなど、読者像に合うものを選ぶ）。',
				'ターゲットペルソナの属性・悩み・求める情報に矛盾しない人物にしてください。',
				'情報が足りない部分は、自然に補完してください（誇張・虚偽は書かない）。',
			)
		);
	}
	private static function user_prompt( array $target, string $avoid_name = '', string $name_style = '' ): string {
		$keyed = array(
			'attributes'        => '属性',
			'worries'           => '悩み・課題',
			'wants'             => '求める情報',
			'knowledge_level'   => '知識レベル',
			'search_flow'       => '検索動線',
			'purchase_behavior' => '購買行動',
		);
		$lines = array( '【読者（ターゲットペルソナ）】' );
		foreach ( $keyed as $key => $label ) {
			$value = $target[ $key ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . trim( (string) $value );
			}
		}
		if ( 1 === count( $lines ) ) {
			$lines[] = '（読者の情報がありません。サイトの内容に合う、商品選びに迷っている人物を自然に想定してください。）';
		}
		if ( '' !== $avoid_name ) {
			$lines[] = '※ 記事の語り手（メンター）の名前「' . $avoid_name . '」は使わず、必ず別の名前を 1 つ作ってください。';
		}
		if ( '' !== trim( $name_style ) ) {
			$lines[] = '今回の name は「' . $name_style . '」の系統で、読者像に合う個性的な呼び名を 1 つ作ってください。';
		}
		$lines[] = '上の読者像から、メンティー（読者のキャラクター）を JSON で作成してください。';
		return implode( "\n", $lines );
	}
	private static function clean( $value, int $limit ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = wp_strip_all_tags( (string) $value );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_substr( $text, 0, $limit );
	}
}

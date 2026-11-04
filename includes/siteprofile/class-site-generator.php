<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\SiteProfile;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Site_Generator {
	private const KEYS = array( 'name', 'author_slug', 'attributes', 'background', 'knowledge_level', 'writing_style', 'position', 'stance' );
	private const GENDER_LABELS = array(
		'male'   => '男性',
		'female' => '女性',
	);
	private const TONE_LABELS = array(
		'academic' => '論文調',
		'polite'   => 'ですます調',
		'casual'   => '口語調',
		'playful'  => 'ふざけ調',
	);
	private const POSITIONS = array(
		'experiencer' => array( 'experiencer', '体験者', '体験', '実体験' ),
		'critic'      => array( 'critic', '評論家', '批評家', '評論' ),
		'researcher'  => array( 'researcher', '研究者', '調査', 'リサーチャー' ),
		'curator'     => array( 'curator', 'キュレーター', '紹介者', '案内人' ),
	);
	private const TARGET_KEYS = array( 'attributes', 'worries', 'wants', 'knowledge_level', 'search_flow', 'purchase_behavior' );
	private const POSITION_LABELS = array(
		'experiencer' => '体験者',
		'critic'      => '評論家',
		'researcher'  => '研究者',
		'curator'     => 'キュレーター',
	);
	private const KNOWLEDGE_LEVELS = array(
		'beginner'     => array( 'beginner', '初心者', '初級', '入門', '初心者向け' ),
		'intermediate' => array( 'intermediate', '中級者', '中級', '中級者向け' ),
		'advanced'     => array( 'advanced', '上級者', '上級', '上級者向け', '玄人', 'マニア' ),
	);
	private const ELEMENT_KEYS = array( 'site_name', 'description', 'profile' );
	private const ELEMENT_CANDIDATE_COUNTS = array(
		'site_name'   => 5,
		'description' => 3,
		'profile'     => 3,
	);
	private const ELEMENTS_MAX_TOKENS = 4000;
	private const SAMPLE_MAX_TOKENS = 2000;
	private const THEME_MAX_TOKENS = 1200;
	public const THEME_COUNT = 5;
	private const KNOWLEDGE_LEVEL_LABELS = array(
		'beginner'     => '初心者',
		'intermediate' => '中級者',
		'advanced'     => '上級者',
	);
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== $this->api_key();
	}
	public static function generated_keys(): array {
		return self::KEYS;
	}
	public static function target_generated_keys(): array {
		return self::TARGET_KEYS;
	}
	public static function unavailable_message(): string {
		return __( "API キーが未設定のため AI 自動生成は使えません。\n「AI設定」タブでプロバイダーと API キーを保存すると有効になります。\n手動入力での保存は可能です。", GACHIREAI_TEXT_DOMAIN );
	}
	public function structure_from_free_text( string $free_text, array $scope = array(), array $persona = array() ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider_for( $this->llm, 'site.persona' );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt(),
			self::user_prompt( $free_text, $scope, $persona ),
			array( 'temperature' => 0.7 )
		);
		$persona = array();
		foreach ( self::KEYS as $key ) {
			$value = $data[ $key ] ?? '';
			if ( 'position' === $key ) {
				$persona[ $key ] = self::normalize_position( is_scalar( $value ) ? (string) $value : '' );
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( "\n", array_filter( array_map( 'strval', $value ), 'strlen' ) );
			}
			$persona[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		if ( '' === implode( '', $persona ) ) {
			throw new \RuntimeException( __( 'AI の応答からペルソナの項目を取り出せませんでした。メモを具体的にして再実行してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $persona;
	}
	private static function normalize_position( string $value ): string {
		$value = trim( $value );
		foreach ( self::POSITIONS as $slug => $aliases ) {
			if ( in_array( $value, $aliases, true ) ) {
				return $slug;
			}
		}
		return '';
	}
	private static function user_prompt( string $free_text, array $scope, array $persona ): string {
		$parts   = array();
		$context = self::scope_context( $scope );
		if ( '' !== $context ) {
			$parts[] = $context;
		}
		$person = self::persona_context( $persona, $free_text );
		if ( '' !== $person ) {
			$parts[] = $person;
		}
		$parts[] = '「このサイトを作る人はこんな人だろう」という観点で、上の情報からマイペルソナを JSON で作成してください。';
		return implode( "\n\n", $parts );
	}
	public static function persona_context( array $persona, string $free_text = '' ): string {
		$lines = array();
		$gender = self::GENDER_LABELS[ (string) ( $persona['gender'] ?? '' ) ] ?? '';
		if ( '' !== $gender ) {
			$lines[] = '性別: ' . $gender;
		}
		$tone = self::TONE_LABELS[ (string) ( $persona['tone'] ?? '' ) ] ?? '';
		if ( '' !== $tone ) {
			$lines[] = '口調: ' . $tone;
		}
		$notes = trim( $free_text );
		if ( '' !== $notes ) {
			$lines[] = '特記事項: ' . $notes;
		}
		if ( array() === $lines ) {
			return '';
		}
		return "【サイトを作る人】\n" . implode( "\n", $lines );
	}
	public static function scope_context( array $scope ): string {
		$types = array(
			'general'  => '総合（ジャンルを問わず扱う）',
			'category' => 'カテゴリー限定（扱う分野を絞る）',
			'niche'    => 'ニッチ（単一〜数品種を深く扱う）',
		);
		$type     = (string) ( $scope['site_type'] ?? Site_Profile::DEFAULT_SITE_TYPE );
		$category = (string) ( $scope['category'] ?? '' );
		$products = (string) ( $scope['products'] ?? '' );
		$lines = array( '【サイトの情報】' );
		if ( isset( $types[ $type ] ) ) {
			$lines[] = 'タイプ: ' . $types[ $type ];
		}
		if ( 'general' !== $type ) {
			if ( '' !== $category ) {
				$lines[] = '扱うカテゴリー: ' . $category;
			}
			if ( 'niche' === $type && '' !== $products ) {
				$lines[] = 'キーワード: ' . $products;
			}
		}
		if ( 1 === count( $lines ) ) {
			return '';
		}
		return implode( "\n", $lines );
	}
	private static function system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトの編集者です。',
				'与えられたメモから、記事を書く人物（マイペルソナ）の設定を作ります。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: '
					. implode( ', ', self::KEYS ),
				'position は experiencer（体験者）/ critic（評論家）/ researcher（研究者）/ curator（キュレーター）のいずれかの英単語にしてください。',
				'writing_style は指定された「口調」（論文調 / ですます調 / 口語調 / ふざけ調）に合わせてください（例: ですます調なら「です・ます」で書く前提の文体にする）。',
				'「性別」は記事の語り手の性別として扱ってください。',
				'特記事項に性格・職業・居住地・経験などがあれば、attributes・background・knowledge_level・stance に反映してください。',
				'特記事項が空でも、性別・口調とサイトの情報から自然に補完してください。',
				'name は記事の語り手の呼び名です（例: タカシ、ミサキ、オーディオ好きのタカシ）。',
				'属性の説明（例: 「オーディオ好き会社員」）を name に入れず、必ず人名らしい呼び名にしてください。メモから人名が読み取れない場合は、自然な呼び名を 1 つ作ってください。',
				'name は 20 文字以内、attributes・knowledge_level・writing_style は 200 文字以内、background・stance は 400 文字以内の日本語にしてください。',
				'author_slug は投稿者 URL（/author/〇〇/）に使う識別子です。name をローマ字にした英小文字（例: タカシ → takashi、オーディオ好きのタカシ → audio-takashi）にし、英数字とハイフンだけ・20 文字以内にしてください。',
				'メモに書かれていない部分は、メモの内容から自然に補完してください（誇張・虚偽の経歴は作らない）。',
			)
		);
	}
	public function structure_target_persona( string $free_text, array $scope = array(), array $my_persona = array() ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider_for( $this->llm, 'site.target' );
		$data     = Manager::generate_json(
			$provider,
			self::target_system_prompt(),
			self::target_user_prompt( $free_text, $scope, $my_persona ),
			array( 'temperature' => 0.7 )
		);
		$target = array();
		foreach ( self::TARGET_KEYS as $key ) {
			$value = $data[ $key ] ?? '';
			if ( 'knowledge_level' === $key ) {
				$target[ $key ] = self::normalize_knowledge_level( is_scalar( $value ) ? (string) $value : '' );
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( "\n", array_filter( array_map( 'strval', $value ), 'strlen' ) );
			}
			$target[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		if ( '' === implode( '', $target ) ) {
			throw new \RuntimeException( __( 'AI の応答からターゲットペルソナの項目を取り出せませんでした。メモを具体的にして再実行してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $target;
	}
	private static function normalize_knowledge_level( string $value ): string {
		$value = trim( $value );
		foreach ( self::KNOWLEDGE_LEVELS as $slug => $aliases ) {
			if ( in_array( $value, $aliases, true ) ) {
				return $slug;
			}
		}
		return '';
	}
	private static function target_user_prompt( string $free_text, array $scope, array $my_persona ): string {
		$parts  = array();
		$site   = self::scope_context( $scope );
		$writer = self::writer_context( $my_persona );
		if ( '' !== $site ) {
			$parts[] = $site;
		}
		if ( '' !== $writer ) {
			$parts[] = $writer;
		}
		$notes = trim( $free_text );
		if ( '' !== $notes ) {
			$parts[] = "【読者についてのメモ】\n" . $notes;
		}
		$parts[] = '「このサイトの記事を読むのはどんな人だろう」という観点で、上の情報から、記事を読む側の人物（ターゲットペルソナ）を JSON で作成してください。';
		return implode( "\n\n", $parts );
	}
	public static function writer_context( array $my_persona ): string {
		$lines = array();
		$keyed = array(
			'name'            => '名前',
			'attributes'      => '属性',
			'knowledge_level' => '知識レベル',
			'writing_style'   => '文体・口調',
		);
		foreach ( $keyed as $key => $label ) {
			$value = $my_persona[ $key ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . trim( (string) $value );
			}
		}
		$position = self::POSITION_LABELS[ (string) ( $my_persona['position'] ?? '' ) ] ?? '';
		if ( '' !== $position ) {
			$lines[] = 'ポジション: ' . $position;
		}
		$gender = self::GENDER_LABELS[ (string) ( $my_persona['gender'] ?? '' ) ] ?? '';
		if ( '' !== $gender ) {
			$lines[] = '性別: ' . $gender;
		}
		$tone = self::TONE_LABELS[ (string) ( $my_persona['tone'] ?? '' ) ] ?? '';
		if ( '' !== $tone ) {
			$lines[] = '口調: ' . $tone;
		}
		$stance = $my_persona['stance'] ?? '';
		if ( is_scalar( $stance ) && '' !== trim( (string) $stance ) ) {
			$lines[] = 'スタンス: ' . trim( (string) $stance );
		}
		if ( array() === $lines ) {
			return '';
		}
		return "【サイトを作る人（記事の語り手）】\n" . implode( "\n", $lines );
	}
	private static function target_system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトの編集者です。',
				'与えられた情報から、このサイトの記事を読む人物（ターゲットペルソナ＝読者）の設定を作ります。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: '
					. implode( ', ', self::TARGET_KEYS ),
				'knowledge_level は beginner（初心者）/ intermediate（中級者）/ advanced（上級者）のいずれかの英単語にしてください。',
				'attributes は読者の属性（年代・性別・職業・生活状況など）を簡潔にしてください（例: 30代・会社員・ひとり暮らし）。',
				'worries は読者が今抱えている悩み・課題、wants は読者が求めている情報・解決したいことを書いてください。',
				'search_flow は読者が検索から記事にたどり着く流れ（どんな言葉で検索し、何を比較・確認したいか）を書いてください。',
				'purchase_behavior は読者の購買行動（比較の仕方・決め手・購入前に確認する点）を書いてください。',
				'記事を書く人（マイペルソナ）の情報がある場合は、その人が届けたい読者像として矛盾しないようにしてください。',
				'attributes・search_flow は 200 文字以内、worries・wants・purchase_behavior は 400 文字以内の日本語にしてください。',
				'情報が足りない部分は、サイトの情報と書く人から自然に補完してください（誇張・虚偽は書かない）。',
			)
		);
	}
	public static function element_keys(): array {
		return self::ELEMENT_KEYS;
	}
	public function generate_site_elements( array $scope = array(), array $my_persona = array(), array $target_persona = array() ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider_for( $this->llm, 'site.elements' );
		$data     = Manager::generate_json(
			$provider,
			self::elements_system_prompt(),
			self::elements_user_prompt( $scope, $my_persona, $target_persona ),
			array(
				'temperature' => 0.9,
				'max_tokens'  => self::ELEMENTS_MAX_TOKENS,
			)
		);
		$elements = array();
		$total    = 0;
		foreach ( self::ELEMENT_KEYS as $key ) {
			$elements[ $key ] = self::normalize_candidates(
				$data[ $key ] ?? array(),
				self::ELEMENT_CANDIDATE_COUNTS[ $key ]
			);
			$total           += count( $elements[ $key ] );
		}
		if ( 0 === $total ) {
			throw new \RuntimeException( __( 'AI の応答からサイト要素の候補を取り出せませんでした。サイトのタイプやペルソナを具体的にして再実行してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $elements;
	}
	public function generate_writing_sample( array $my_persona = array(), array $site_elements = array() ): string {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider_for( $this->llm, 'site.sample' );
		$data     = Manager::generate_json(
			$provider,
			self::sample_system_prompt(),
			self::sample_user_prompt( $my_persona, $site_elements ),
			array(
				'temperature' => 0.8,
				'max_tokens'  => self::SAMPLE_MAX_TOKENS,
			)
		);
		$sample = $data['sample'] ?? '';
		if ( is_array( $sample ) ) {
			$sample = implode( "\n", array_filter( array_map( 'strval', $sample ), 'strlen' ) );
		}
		$sample = is_scalar( $sample ) ? trim( (string) $sample ) : '';
		if ( '' === $sample ) {
			throw new \RuntimeException( __( 'AI の応答から文体サンプルを取り出せませんでした。ペルソナを具体的にして再実行してください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $sample;
	}
	public function propose_themes( int $count = self::THEME_COUNT ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider_for( $this->llm, 'site.theme' );
		$data     = Manager::generate_json(
			$provider,
			self::theme_system_prompt(),
			self::theme_user_prompt(),
			array(
				'temperature' => 1.0,
				'max_tokens'  => self::THEME_MAX_TOKENS,
			)
		);
		$themes = self::normalize_candidates( $data['themes'] ?? array(), max( 1, min( 10, $count ) ) );
		if ( array() === $themes ) {
			throw new \RuntimeException( __( 'AI の応答からコンセプト案を取り出せませんでした。もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $themes;
	}
	private static function theme_system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトの企画者です。',
				'EC の商品レビュー記事を量産する新しいサイトの「コンセプト」を提案します。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: themes',
				'themes は 5 件の文字列の配列にしてください。',
				'各コンセプトは「何を扱うサイトか」が分かる 15〜40 文字程度の日本語にしてください。',
				'例: 「空気清浄機の比較レビューサイト」「キャンプ初心者向けギアのレビューサイト」「コスパ重視の家電まとめサイト」',
				'5 件は互いに分野を変え、需要があり・商品数が多く・レビュー記事に向くコンセプトにしてください。',
				'HTML タグ・見出し・箇条書きは使わず、コンセプトの文言だけを返してください。',
			)
		);
	}
	private static function theme_user_prompt(): string {
		return 'EC の商品レビューを扱う新しいサイトのコンセプト案を 5 件、JSON で提案してください。';
	}
	public function generate_slug( string $site_name, string $theme = '' ): string {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider_for( $this->llm, 'site.slug' );
		$data     = Manager::generate_json(
			$provider,
			self::slug_system_prompt(),
			self::slug_user_prompt( $site_name, $theme ),
			array(
				'temperature' => 0.3,
				'max_tokens'  => 200,
			)
		);
		$slug = isset( $data['slug'] ) && is_scalar( $data['slug'] ) ? (string) $data['slug'] : '';
		$slug = strtolower( (string) sanitize_title( $slug ) );
		$slug = (string) preg_replace( '/[^a-z0-9\-]+/', '', $slug );
		$slug = trim( $slug, '-' );
		return substr( $slug, 0, 40 );
	}
	private static function slug_system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトの編集者です。',
				'与えられたサイト名とコンセプトから、サイトの URL（サブドメイン／サブディレクトリ）に使う英語のスラッグを 1 つ作ります。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: slug',
				'slug は小文字の英数字とハイフンのみ・40 文字以内にしてください（例: air-purifier-review）。',
				'サイト名が日本語なら意味を英訳し、短く分かりやすい語にしてください。',
				'HTML タグ・記号・空白は使わないでください。',
			)
		);
	}
	private static function slug_user_prompt( string $site_name, string $theme ): string {
		$parts = array( '【サイト名】' . trim( $site_name ) );
		if ( '' !== trim( $theme ) ) {
			$parts[] = '【コンセプト】' . trim( $theme );
		}
		$parts[] = '上のサイトの URL スラッグ（英語）を JSON で作成してください。';
		return implode( "\n", $parts );
	}
	private static function normalize_candidates( $value, int $max ): array {
		if ( is_string( $value ) ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$candidates = array();
		foreach ( $value as $candidate ) {
			if ( is_array( $candidate ) ) {
				$candidate = implode( ' ', array_filter( array_map( 'strval', $candidate ), 'strlen' ) );
			}
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate ) {
				$candidates[] = $candidate;
			}
			if ( count( $candidates ) >= $max ) {
				break;
			}
		}
		return $candidates;
	}
	private static function elements_system_prompt(): string {
		$counts = array();
		foreach ( self::ELEMENT_CANDIDATE_COUNTS as $key => $count ) {
			$counts[] = $key . ' は ' . $count . ' 案';
		}
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトの編集者です。',
				'与えられたサイトの情報・書く人・読者から、サイトの要素の案を複数作ります。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: ' . implode( ', ', self::ELEMENT_KEYS ),
				'各キーの値は日本語の文字列の配列にしてください（件数: ' . implode( '、', $counts ) . '）。',
				'site_name はサイト名です（10〜20 文字程度）。',
				'description はサイト説明文です（80〜160 文字程度）。誰に向けて何を提供するサイトかを書いてください。',
				'profile は自己紹介・プロフィールです（150〜300 文字程度）。書く人（マイペルソナ）の一人称で書いてください。',
				'同じキーの中では切り口や雰囲気を変え、似た案を並べないでください。',
				'書く人の口調（論文調 / ですます調 / 口語調 / ふざけ調）とスタンスに合う表現にしてください。',
			)
		);
	}
	private static function elements_user_prompt( array $scope, array $my_persona, array $target_persona ): string {
		$parts  = array();
		$site   = self::scope_context( $scope );
		$writer = self::writer_context( $my_persona );
		$reader = self::target_context( $target_persona );
		if ( '' !== $site ) {
			$parts[] = $site;
		}
		if ( '' !== $writer ) {
			$parts[] = $writer;
		}
		if ( '' !== $reader ) {
			$parts[] = $reader;
		}
		$parts[] = '上の情報から、このサイトの「サイト名」「サイト説明文」「自己紹介・プロフィール」の候補を JSON で作成してください。';
		return implode( "\n\n", $parts );
	}
	private static function target_context( array $target ): string {
		$keyed = array(
			'attributes'        => '属性',
			'worries'           => '悩み・課題',
			'wants'             => '求める情報',
			'search_flow'       => '検索動線',
			'purchase_behavior' => '購買行動',
		);
		$lines = array();
		foreach ( $keyed as $key => $label ) {
			$value = $target[ $key ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . trim( (string) $value );
			}
		}
		$level = self::KNOWLEDGE_LEVEL_LABELS[ (string) ( $target['knowledge_level'] ?? '' ) ] ?? '';
		if ( '' !== $level ) {
			$lines[] = '知識レベル: ' . $level;
		}
		if ( array() === $lines ) {
			return '';
		}
		return "【読者（ターゲットペルソナ）】\n" . implode( "\n", $lines );
	}
	private static function sample_system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトの編集者です。',
				'与えられた「書く人（マイペルソナ）」の文体で、商品レビュー記事の書き出し（文体サンプル）を 1 つ書きます。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: sample',
				'sample は 300〜600 文字程度の日本語の本文にしてください。',
				'指定された口調（論文調 / ですます調 / 口語調 / ふざけ調）と文体・口調を厳密に守ってください。',
				'見出し・箇条書き・HTML タグは使わず、地の文だけで書いてください。',
				'記事生成の見本として使うため、具体的な商品名は出さず一般化した書き方にしてください。',
			)
		);
	}
	private static function sample_user_prompt( array $my_persona, array $site_elements ): string {
		$parts  = array();
		$writer = self::writer_context( $my_persona );
		if ( '' !== $writer ) {
			$parts[] = $writer;
		}
		$context   = array();
		$site_name = $site_elements['site_name'] ?? '';
		if ( is_scalar( $site_name ) && '' !== trim( (string) $site_name ) ) {
			$context[] = 'サイト名: ' . trim( (string) $site_name );
		}
		if ( array() !== $context ) {
			$parts[] = "【サイト】\n" . implode( "\n", $context );
		}
		$parts[] = '上の「書く人」の文体で、サイトに載せる商品レビュー記事の書き出し（見本）を JSON で作成してください。';
		return implode( "\n\n", $parts );
	}
	private function api_key(): string {
		return (string) ( $this->llm['api_key'] ?? '' );
	}
}

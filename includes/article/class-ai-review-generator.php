<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Article;
use Tonkatsutei\Gachi_Review_AI\Llm\Manager;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
final class Ai_Review_Generator {
	public const SOURCE = 'ai';
	private const MAX_TITLE = 40;
	private const MAX_TEXT = 600;
	private const MAX_TOKENS = 3000;
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== (string) ( $this->llm['api_key'] ?? '' );
	}
	public static function unavailable_message(): string {
		return __( 'API キーが未設定のため AI によるレビュー生成を行えません。「AI設定」タブで LLM の API キーを保存してください。', GACHIREAI_TEXT_DOMAIN );
	}
	public function generate( Site_Profile $profile, array $product, int $count ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		if ( '' === trim( (string) ( $product['product_name'] ?? '' ) ) ) {
			throw new \RuntimeException( __( '商品名が無いため AI によるレビュー生成を行えません。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$count    = max( 1, min( Ai_Review::MAX_COUNT, $count ) );
		$provider = Manager::provider_for( $this->llm, 'ai_review.system' );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt( $count ),
			self::user_prompt( $profile, $product, $count ),
			array(
				'temperature' => 1.0,
				'max_tokens'  => self::MAX_TOKENS,
			)
		);
		$reviews = self::normalize( $data, $count );
		if ( array() === $reviews ) {
			throw new \RuntimeException( __( 'AI の応答からレビューを取り出せませんでした。もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $reviews;
	}
	private static function system_prompt( int $count ): string {
		unset( $count );
		return implode(
			"\n",
			array(
				'あなたは商品レビューの取材を代行するアシスタントです。',
				'与えられた商品について、実際に購入・使用した人が書いたような、自然な口コミレビューを指定件数つくります（件数はユーザープロンプトに書かれています）。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: reviews',
				'reviews はレビューの配列です。各要素は {"title": "...", "text": "..."} の形式にしてください。',
				'title: そのレビューの切り口（例: 毎日使う人の感想 / 価格重視 / 半年使ってみて / 家族と使う）。20 文字以内。title も件数分を互いに変えてください。',
				'text: レビュー本文（120〜250 文字）。実際に使った人の具体的な体験として書く。',
				'レビューの書き手は毎回かえてください（年代・性別・職業・家族構成・用途・使用期間などが異なる人物にする）。',
				'長所だけでなく、気になった点・短所も必ず入れてください（本物らしさのため）。',
				'短所は「ただ」「ただし」「しかし」「でも」のような逆接の接続詞で毎回始めず、レビューごとに違う言い回しにしてください（例:「〜が気になる」「〜に不満がある」「〜が惜しい」「〜で困った」「〜だけは合わなかった」）。同じ接続詞・同じ言い回しを複数のレビューで使わないでください。',
				'各レビューの書き出し（最初の一文）の型を件数分で重複させないでください。次の型から選んでください（結論から言う / 買ったきっかけから / 使い始めた状況から / 正直な感想から / 期待とのギャップから / 日常の一場面から / 比較した結果から / 家族や周囲の反応から）。',
				'レビューごとに口調・文体を変えてください（「です・ます調」／「だ・である調」／体言止め／話し言葉／そっけない言い方／丁寧すぎる言い方）。同じ語尾・同じ文末表現を続けないでください。',
				'文の長さもそろえず、短い一文だけのレビューと、説明の長いレビューを混ぜてください。',
				'レビューごとに注目する観点を変えてください（例: 使い勝手 / 音 / 重さ・大きさ / デザイン / 価格に見合うか / 耐久性 / 手入れのしやすさ / 操作性 / 設置や収納 / 家族の反応）。全レビューで同じ観点ばかりに触れないでください。',
				'「買ってよかったです」「コスパが良い」「満足しています」のような決まり文句や、どの商品にも当てはまる抽象的な表現を避け、その商品ならではの具体的な使用場面・感想を書いてください。',
				'「総じて」「全体的に」「〜という点が挙げられます」のような硬いまとめ言葉を使わず、実際の口コミらしい話し言葉にしてください。',
				'レビュー同士で同じ文型・同じ結論の流れ・同じ締めくくり・同じ単語の使い回しを繰り返さないでください（AI が書いたと分かる不自然さを避ける）。',
				'与えられた商品情報に無い具体的な事実（価格・受賞歴・製品仕様の数値など）は創作しないでください。',
				'text には HTML タグ・Markdown 記号を入れず、テキストだけを入れてください（改行も入れない）。',
			)
		);
	}
	private static function user_prompt( Site_Profile $profile, array $product, int $count ): string {
		$parts = array();
		$site  = Site_Generator::scope_context( $profile->get_scope() );
		if ( '' !== $site ) {
			$parts[] = $site;
		}
		$parts[] = self::product_context( $product );
		$parts[] = 'この商品について、実際に購入・使用した人の口コミレビューを ' . $count . ' 件、JSON で作成してください。';
		$parts[] = $count . ' 件はそれぞれ別の人物が書いたように、書き出しの型・口調・文の長さ・観点を重複させず、できるだけ大きくばらつかせてください。';
		return implode( "\n\n", $parts );
	}
	private static function product_context( array $product ): string {
		$lines = array();
		$keyed = array(
			'product_name' => '商品名',
			'brand'        => 'ブランド',
			'model_number' => '型番',
		);
		foreach ( $keyed as $key => $label ) {
			$value = $product[ $key ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$lines[] = $label . ': ' . trim( (string) $value );
			}
		}
		return '【商品】' . "\n" . implode( "\n", $lines );
	}
	private static function normalize( array $data, int $count ): array {
		$raw = $data['reviews'] ?? $data['items'] ?? $data;
		if ( is_scalar( $raw ) ) {
			$raw = preg_split( '/\n{2,}/u', (string) $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$reviews = array();
		foreach ( $raw as $item ) {
			if ( count( $reviews ) >= $count ) {
				break;
			}
			$review = self::normalize_one( $item );
			if ( array() !== $review ) {
				$reviews[] = $review;
			}
		}
		return $reviews;
	}
	private static function normalize_one( $item ): array {
		if ( is_scalar( $item ) ) {
			$text = self::clean_text( $item );
			return '' === $text ? array() : self::review( '', $text );
		}
		if ( ! is_array( $item ) ) {
			return array();
		}
		$title = self::clean_text( $item['title'] ?? '' );
		$text = self::clean_text( $item['text'] ?? $item['body'] ?? $item['content'] ?? '' );
		return '' === $text ? array() : self::review( $title, $text );
	}
	private static function review( string $title, string $text ): array {
		return array(
			'source'      => self::SOURCE,
			'external_id' => '',
			'community'   => '',
			'title'       => mb_substr( $title, 0, self::MAX_TITLE ),
			'permalink'   => '',
			'url'         => '',
			'text'        => mb_substr( $text, 0, self::MAX_TEXT ),
		);
	}
	private static function clean_text( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( (string) $value );
	}
}

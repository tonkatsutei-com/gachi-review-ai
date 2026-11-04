<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
final class Keyword_Proposer {
	private const CANDIDATE_COUNT = 20;
	private const WORD_MAX = 100;
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== (string) ( $this->llm['api_key'] ?? '' );
	}
	public static function unavailable_message(): string {
		return __( 'API キーが未設定のためキーワードの提案は使えません。「LLM設定」タブで LLM の API キーを保存すると有効になります。', GACHIREAI_TEXT_DOMAIN );
	}
	public function propose( array $scope, array $existing, int $want ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		if ( $want < 1 ) {
			return array();
		}
		$provider = Manager::provider_for( $this->llm, 'keyword.system' );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt(),
			self::user_prompt( $scope, $existing ),
			array( 'temperature' => 0.7 )
		);
		$candidates = self::clean_list( $data['keywords'] ?? array(), max( self::CANDIDATE_COUNT, $want ) );
		return self::without_existing( $candidates, $existing, $want );
	}
	private static function system_prompt(): string {
		return implode(
			"\n",
			array(
				'あなたは日本語のアフィリエイトサイトで扱う商品を探すための「検索キーワード」を考えるアシスタントです。',
				'これから【サイトの情報】と【既存のキーワード】を渡します。',
				'サイトが扱う商品を EC（ネット通販）で検索して見つけられる、具体的なキーワードを考えてください。',
				'既存のキーワードと同じ語・同じ意味の語は**返さないでください**（必ず新しい語だけ）。',
				'必ず次のキーだけを持つ JSON オブジェクトを返してください: keywords',
				'keywords: キーワードの配列（' . self::CANDIDATE_COUNT . ' 件程度）。1 語または 2 語の複合語にしてください。',
				'2 語の複合語は「ワイヤレス イヤホン」のように**空白で区切った 1 つの文字列**として返してください（引用符は付けない）。',
				'キーワードは 20 文字以内の日本語にしてください。記号・説明文・番号は付けないでください。',
			)
		);
	}
	private static function user_prompt( array $scope, array $existing ): string {
		$parts = array();
		$site  = Site_Generator::scope_context( $scope );
		$parts[] = '' !== $site
			? $site
			: "【サイトの情報】\n（サイト設定が未入力です。一般的な商品レビューに向くキーワードを考えてください）";
		$parts[] = "【既存のキーワード】\n" . ( array() !== $existing ? implode( ', ', $existing ) : '（なし）' );
		$parts[] = 'このサイトで扱う商品を EC で検索するための、既存と重複しない新しいキーワードを JSON で返してください。';
		return implode( "\n\n", $parts );
	}
	private static function clean_list( $value, int $max ): array {
		if ( is_scalar( $value ) ) {
			$value = preg_split( '/[,\x{3001}\x{30FB}\n]+/u', (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$list = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$text = trim( trim( (string) $item ), "\"' \t\n\r\0\x0B" );
			$text = mb_substr( $text, 0, self::WORD_MAX );
			if ( '' !== $text ) {
				$list[] = $text;
			}
		}
		return array_slice( array_values( array_unique( $list ) ), 0, $max );
	}
	private static function without_existing( array $candidates, array $existing, int $want ): array {
		$seen  = array();
		$found = array();
		foreach ( $existing as $word ) {
			$seen[ self::key( (string) $word ) ] = true;
		}
		foreach ( $candidates as $word ) {
			$text = trim( (string) $word );
			$key  = self::key( $text );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$found[]      = $text;
			if ( count( $found ) >= $want ) {
				break;
			}
		}
		return $found;
	}
	private static function key( string $word ): string {
		return mb_strtolower( trim( $word ) );
	}
}

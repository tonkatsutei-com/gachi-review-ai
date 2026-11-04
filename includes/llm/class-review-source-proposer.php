<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
final class Review_Source_Proposer {
	public const KEYS = array( 'subreddits', 'boards' );
	private const MAX_SUBREDDITS = 8;
	private const MAX_BOARDS = 8;
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== (string) ( $this->llm['api_key'] ?? '' );
	}
	public static function unavailable_message(): string {
		return __( 'API キーが未設定のためレビューの取得元を特定できません。「LLM設定」タブで LLM の API キーを保存すると有効になります。', GACHIREAI_TEXT_DOMAIN );
	}
	public function propose( string $product_name, array $scope = array() ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		$provider = Manager::provider_for( $this->llm, 'source_proposer.system' );
		$data     = Manager::generate_json(
			$provider,
			self::system_prompt(),
			self::user_prompt( $product_name, $scope ),
			array( 'temperature' => 0.3 )
		);
		return self::normalize( $data );
	}
	private static function system_prompt(): string {
		return '';
	}
	private static function user_prompt( string $product_name, array $scope ): string {
		$parts = array( "【商品名】\n" . trim( $product_name ) );
		$site  = Site_Generator::scope_context( $scope );
		if ( '' !== $site ) {
			$parts[] = $site;
		}
		$parts[] = 'この商品のレビューを探すための「サブレディット」と「5ch の板」の候補を JSON で提案してください。';
		return implode( "\n\n", $parts );
	}
	private static function normalize( array $data ): array {
		return array(
			'subreddits' => self::clean_list( $data['subreddits'] ?? array(), self::MAX_SUBREDDITS, true ),
			'boards'     => self::clean_list( $data['boards'] ?? array(), self::MAX_BOARDS, false ),
		);
	}
	private static function clean_list( $value, int $max, bool $is_subreddit ): array {
		if ( is_scalar( $value ) ) {
			$value = preg_split( '/[\s,、]+/u', (string) $value );
			if ( ! is_array( $value ) ) {
				return array();
			}
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
			if ( $is_subreddit ) {
				$text = (string) preg_replace( '#^/?r/#i', '', $text );
				$text = trim( $text );
			} else {
				$text = (string) preg_replace( '#^https?://[^/]+/#i', '', $text );
				$text = (string) preg_replace( '#^/?5ch\.io/#i', '', $text );
				$text = trim( $text, '/' );
			}
			if ( '' === $text ) {
				continue;
			}
			$list[] = $text;
		}
		return array_slice( array_values( array_unique( $list ) ), 0, $max );
	}
}

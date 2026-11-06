<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Generator;
final class Source_Selector {
	public const FIVECH_KEYS = array( 'categories', 'boards' );
	public const REDDIT_KEYS = array( 'subreddits' );
	private const MAX_CATEGORIES = 10;
	private const MAX_BOARDS = 15;
	private const MAX_SUBREDDITS = 20;
	private array $llm;
	public function __construct( ?Settings $settings = null ) {
		$settings  = $settings ?? new Settings();
		$this->llm = $settings->active_llm();
	}
	public function is_available(): bool {
		return '' !== (string) ( $this->llm['api_key'] ?? '' );
	}
	public static function unavailable_message(): string {
		return __( 'API キーが未設定のため検索先の提案は使えません。「AI設定」タブで LLM の API キーを保存すると有効になります。', GACHIREAI_TEXT_DOMAIN );
	}
	public static function subreddit_catalog(): array {
		static $cached = null;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$path = GACHIREAI_PLUGIN_DIR . 'includes/data/subreddits.json';
		if ( ! is_readable( $path ) ) {
			$cached = array();
			return $cached;
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); 
		if ( ! is_array( $data ) ) {
			$cached = array();
			return $cached;
		}
		$list = array();
		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$name = trim( (string) ( $entry['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$list[] = array(
				'name'  => $name,
				'label' => trim( (string) ( $entry['label'] ?? '' ) ),
			);
		}
		$cached = $list;
		return $cached;
	}
	public function select_fivech( array $categories, array $scope ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		if ( array() === $categories ) {
			return array(
				'categories' => array(),
				'boards'     => array(),
			);
		}
		$provider = Manager::provider_for( $this->llm, 'source_select.fivech' );
		$data     = Manager::generate_json(
			$provider,
			self::fivech_system_prompt(),
			self::fivech_user_prompt( $categories, $scope ),
			array( 'temperature' => 0.3 )
		);
		return array(
			'categories' => self::clean_list( $data['categories'] ?? array(), self::MAX_CATEGORIES ),
			'boards'     => self::clean_list( $data['boards'] ?? array(), self::MAX_BOARDS ),
		);
	}
	public function select_reddit( array $catalog, array $scope ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( self::unavailable_message() );
		}
		if ( array() === $catalog ) {
			return array( 'subreddits' => array() );
		}
		$provider = Manager::provider_for( $this->llm, 'source_select.reddit' );
		$data     = Manager::generate_json(
			$provider,
			self::reddit_system_prompt(),
			self::reddit_user_prompt( $catalog, $scope ),
			array( 'temperature' => 0.3 )
		);
		return array(
			'subreddits' => self::clean_list( $data['subreddits'] ?? array(), self::MAX_SUBREDDITS, true ),
		);
	}
	private static function fivech_system_prompt(): string {
		return '';
	}
	private static function fivech_user_prompt( array $categories, array $scope ): string {
		$parts = array();
		$site  = Site_Generator::scope_context( $scope );
		$parts[] = '' !== $site
			? $site
			: "【サイトの情報】\n（サイト設定が未入力です。一般的な商品レビューに向く板を選んでください）";
		$lines = array();
		foreach ( $categories as $category => $boards ) {
			$names = array();
			foreach ( $boards as $board ) {
				if ( '' !== $board['board_name'] ) {
					$names[] = $board['board_name'];
				}
			}
			if ( array() !== $names ) {
				$lines[] = $category . ': ' . implode( ', ', $names );
			}
		}
		$parts[] = "【カテゴリーと板の一覧】\n" . implode( "\n", $lines );
		$parts[] = 'このサイトの商品レビュー記事に役立つ「カテゴリー」と「板」を、上の一覧から JSON で選んでください。'
			. '「categories」にはカテゴリー名（各行の「:」より前）、「boards」には**板名**（「:」より後ろに並んでいる語）を入れてください。';
		return implode( "\n\n", $parts );
	}
	private static function reddit_system_prompt(): string {
		return '';
	}
	private static function reddit_user_prompt( array $catalog, array $scope ): string {
		$parts = array();
		$site  = Site_Generator::scope_context( $scope );
		$parts[] = '' !== $site
			? $site
			: "【サイトの情報】\n（サイト設定が未入力です。一般的な商品レビューに向くサブレディットを選んでください）";
		$lines = array();
		foreach ( $catalog as $entry ) {
			$lines[] = '' !== $entry['label'] ? $entry['name'] . '（' . $entry['label'] . '）' : $entry['name'];
		}
		$parts[] = "【サブレディットの一覧】\n" . implode( "\n", $lines );
		$parts[] = 'このサイトの商品レビュー記事に役立つサブレディットを、上の一覧から JSON で選んでください。';
		return implode( "\n\n", $parts );
	}
	private static function clean_list( $value, int $max, bool $is_subreddit = false ): array {
		if ( is_scalar( $value ) ) {
			$value = preg_split( '/[\s,、]+/u', (string) $value );
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
				$text = trim( (string) preg_replace( '#^/?r/#i', '', $text ) );
			}
			if ( '' === $text ) {
				continue;
			}
			$list[] = $text;
		}
		return array_slice( array_values( array_unique( $list ) ), 0, $max );
	}
}

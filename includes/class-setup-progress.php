<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
use Tonkatsutei\Gachi_Review_AI\Admin\Api_Affiliate;
use Tonkatsutei\Gachi_Review_AI\Admin\Tabs;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Article\Cron_Schedule;
use Tonkatsutei\Gachi_Review_AI\Article\Source_Settings;
use Tonkatsutei\Gachi_Review_AI\Ec\Ec_Review_Use;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\License\Status;
use Tonkatsutei\Gachi_Review_AI\Policy\Policy_Page;
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
use Tonkatsutei\Gachi_Review_AI\Scraping\Store;
use Tonkatsutei\Gachi_Review_AI\SiteProfile\Site_Profile;
use Tonkatsutei\Gachi_Review_AI\Top\Top_Page;
final class Setup_Progress {
	public const STATUS_COMPLETE = 'complete';
	public const STATUS_INCOMPLETE = 'incomplete';
	public const MARK_SINGLE = 'single';
	public const MARK_EC = 'ec';
	public const MARK_ANY = 'any';
	private ?Settings $settings;
	private ?Site_Profile $profile;
	public function __construct( ?Settings $settings = null, ?Site_Profile $profile = null ) {
		$this->settings = $settings;
		$this->profile  = $profile;
	}
	public function tabs(): array {
		return array(
			$this->tab( Tabs::TAB_LLM, $this->ai_sections() ),
			$this->tab( Tabs::TAB_ASP, $this->asp_sections() ),
			$this->tab( Tabs::TAB_SCRAPING, $this->scraping_sections() ),
			$this->tab( Tabs::TAB_SITE, $this->site_sections() ),
			$this->tab( Tabs::TAB_ARTICLE, $this->article_sections() ),
			$this->tab( Tabs::TAB_REVIEW, $this->review_sections() ),
		);
	}
	public function missing_required(): array {
		$missing = array();
		foreach ( $this->tabs() as $tab ) {
			$labels    = array();
			$has_group = array();
			$any_saved = array();
			foreach ( (array) $tab['sections'] as $section ) {
				$mark = (string) $section['section_mark'];
				$done = ! empty( $section['section_saved'] );
				if ( self::MARK_SINGLE === $mark ) {
					if ( ! $done ) {
						$labels[] = (string) $section['section_label'];
					}
					continue;
				}
				if ( self::MARK_EC === $mark || self::MARK_ANY === $mark ) {
					$has_group[ $mark ] = true;
					if ( $done ) {
						$any_saved[ $mark ] = true;
					}
				}
			}
			foreach ( array_keys( $has_group ) as $mark ) {
				if ( empty( $any_saved[ $mark ] ) ) {
					$labels[] = self::missing_group_label( $mark );
				}
			}
			if ( array() !== $labels ) {
				$missing[] = array(
					'tab_slug'       => (string) $tab['tab_slug'],
					'tab_label'      => (string) $tab['tab_label'],
					'section_labels' => $labels,
				);
			}
		}
		return $missing;
	}
	private static function missing_group_label( string $mark ): string {
		if ( self::MARK_EC === $mark ) {
			return __( 'いずれかの EC', GACHIREAI_TEXT_DOMAIN );
		}
		return __( 'いずれかのレビュー取得元', GACHIREAI_TEXT_DOMAIN );
	}
	public function all_complete(): bool {
		foreach ( $this->tabs() as $tab ) {
			if ( self::STATUS_COMPLETE !== $tab['tab_status'] ) {
				return false;
			}
		}
		return true;
	}
	public function is_ec_basis_complete(): bool {
		return $this->is_basis_complete();
	}
	public function can_auto_post(): bool {
		return Status::capabilities()->can_auto_post();
	}
	private function ai_sections(): array {
		$settings = $this->settings();
		return array(
			$this->section(
				__( '文章生成AI', GACHIREAI_TEXT_DOMAIN ),
				self::MARK_SINGLE,
				Connection_Status::is_verified( 'llm', $settings ),
				$this->verified_note( 'llm', __( '使用するAIの API キーを保存し、接続テストに成功していること。', GACHIREAI_TEXT_DOMAIN ) ),
				1,
				Api_Affiliate::llm_section_key()
			),
			$this->section(
				__( '判定AI', GACHIREAI_TEXT_DOMAIN ),
				'',
				Connection_Status::is_verified( 'jev', $settings ),
				$this->verified_note( 'jev', __( '未設定ではレビューが判定されません。', GACHIREAI_TEXT_DOMAIN ) ),
				1,
				'jev'
			),
			$this->section(
				__( '画像生成AI', GACHIREAI_TEXT_DOMAIN ),
				'',
				Connection_Status::is_verified( 'image', $settings ),
				$this->verified_note( 'image', '' ),
				1,
				'image'
			),
		);
	}
	private function verified_note( string $group, string $base ): string {
		if ( Connection_Status::is_revoked( $group, $this->settings() ) ) {
			return Connection_Status::revocation_message();
		}
		return $base;
	}
	private function asp_sections(): array {
		$sections = array(
			$this->section(
				__( 'メインEC', GACHIREAI_TEXT_DOMAIN ),
				self::MARK_SINGLE,
				$this->is_basis_complete(),
				__( '設定済みのECを選んでいること。', GACHIREAI_TEXT_DOMAIN ),
				1,
				'ec'
			),
		);
		$slugs = array( Product_Selector::EC_RAKUTEN, Product_Selector::EC_AMAZON, Product_Selector::EC_YAHOO );
		foreach ( $slugs as $index => $slug ) {
			$sections[] = $this->section(
				Product_Selector::ec_label( $slug ),
				self::MARK_EC,
				Product_Selector::AVAILABILITY_READY === $this->availability_of( $slug ),
				0 === $index ? __( '*2　一つ以上のECを設定すること。', GACHIREAI_TEXT_DOMAIN ) : '',
				0 === $index ? count( $slugs ) : 1,
				$slug
			);
		}
		return $sections;
	}
	private function is_basis_complete(): bool {
		$settings  = $this->settings();
		$ec        = trim( (string) $settings->get( 'ec.ec', '' ) );
		$selection = trim( (string) $settings->get( 'ec.selection', '' ) );
		if ( '' === $ec || '' === $selection ) {
			return false;
		}
		return Product_Selector::AVAILABILITY_READY === $this->availability_of( $ec );
	}
	private function availability_of( string $ec ): string {
		return Product_Selector::availability_of( $ec, $this->settings() );
	}
	private function scraping_sections(): array {
		$sections = array();
		foreach ( Service_Catalog::ids() as $id ) {
			$row        = Store::get( $id );
			$sections[] = $this->section(
				Service_Catalog::label( $id ),
				'',
				Store::is_verified( $id ),
				'error' === (string) $row['status'] ? (string) $row['message'] : '',
				1,
				'scraping-' . $id
			);
		}
		return $sections;
	}
	private function site_sections(): array {
		$my             = $this->profile()->get_my_persona();
		$target         = $this->profile()->get_target_persona();
		$elements       = $this->profile()->get_site_elements();
		$my_ok          = $this->array_has_value( $my );
		$target_ok      = $this->array_has_value( $target );
		$elements_ok    = $this->array_has_value( $elements );
		$site_images_ok = $this->site_images_ready();
		$pages_ok       = $this->static_pages_ready();
		return array(
			$this->section( __( '取扱商品', GACHIREAI_TEXT_DOMAIN ), self::MARK_SINGLE, $this->is_scope_complete(), '', 1, 'scope' ),
			$this->section( __( 'マイペルソナ', GACHIREAI_TEXT_DOMAIN ), self::MARK_SINGLE, $my_ok, '', 1, 'my_persona' ),
			$this->section( __( 'ターゲットペルソナ', GACHIREAI_TEXT_DOMAIN ), self::MARK_SINGLE, $target_ok, '', 1, 'target' ),
			$this->section( __( 'サイト要素', GACHIREAI_TEXT_DOMAIN ), '', $elements_ok, '', 1, 'site_elements' ),
			$this->section( __( 'サイト画像', GACHIREAI_TEXT_DOMAIN ), '', $site_images_ok, '', 1, 'site_images' ),
			$this->section( __( '固定ページ', GACHIREAI_TEXT_DOMAIN ), '', $pages_ok, '', 1, 'static_pages' ),
		);
	}
	private function static_pages_ready(): bool {
		return Top_Page::is_ready() || Policy_Page::is_ready();
	}
	private function site_images_ready(): bool {
		$favicon    = (int) get_option( 'site_icon', 0 );
		$my_persona = $this->profile()->get_my_persona();
		$photo      = absint( (string) ( $my_persona['photo'] ?? '' ) );
		return 0 < $favicon && 0 < $photo;
	}
	private function is_scope_complete(): bool {
		$scope = $this->profile()->get_scope();
		$type  = (string) ( $scope['site_type'] ?? Site_Profile::DEFAULT_SITE_TYPE );
		if ( 'general' === $type ) {
			return true;
		}
		if ( '' === trim( (string) ( $scope['category'] ?? '' ) ) ) {
			return false;
		}
		if ( 'niche' === $type ) {
			return '' !== trim( (string) ( $scope['products'] ?? '' ) );
		}
		return true;
	}
	private function article_sections(): array {
		return array(
			$this->section( __( '記事生成投稿設定', GACHIREAI_TEXT_DOMAIN ), self::MARK_SINGLE, $this->is_article_complete(), '', 1, 'article_settings' ),
			$this->section( __( '自動投稿設定', GACHIREAI_TEXT_DOMAIN ), '', $this->is_auto_post_saved(), '', 1, 'auto_post_settings' ),
			$this->section( __( 'アイキャッチ設定', GACHIREAI_TEXT_DOMAIN ), '', is_array( $this->settings()->get( 'image', array() ) ) && array() !== $this->settings()->get( 'image', array() ), '', 1, 'eyecatch' ),
			$this->section( __( '商品ボックス設定', GACHIREAI_TEXT_DOMAIN ), self::MARK_SINGLE, true, '', 1, 'card_settings' ),
		);
	}
	private function is_article_complete(): bool {
		$article = $this->settings()->get( 'article', array() );
		if ( ! is_array( $article ) ) {
			return false;
		}
		return '' !== (string) ( $article['article_type'] ?? '' );
	}
	private function is_auto_post_saved(): bool {
		$article = $this->settings()->get( 'article', array() );
		if ( ! is_array( $article ) ) {
			return false;
		}
		return '' !== trim( (string) ( $article[ Cron_Schedule::KEY ] ?? '' ) );
	}
	private function review_sections(): array {
		$sections = array(
			$this->section( __( '掲載数とAIによるレビュー生成', GACHIREAI_TEXT_DOMAIN ), self::MARK_SINGLE, true, '', 1, 'ai_review' ),
			$this->section( __( '表示と保持', GACHIREAI_TEXT_DOMAIN ), '', true, '', 1, Source_Settings::GROUP ),
		);
		$review_note = Status::is_free()
			? __( '*3　FREE は AI 生成のみのため、実レビューの取得設定は不要です。', GACHIREAI_TEXT_DOMAIN )
			: __( '*3　一つ以上を取得対象にすること。', GACHIREAI_TEXT_DOMAIN );
		foreach ( array( 'youtube', 'mastodon' ) as $group ) {
			if ( Connection_Status::is_revoked( $group, $this->settings() ) ) {
				$review_note .= ' ' . Connection_Status::revocation_message();
			}
		}
		$sections[] = $this->section(
			__( 'レビュー取得元', GACHIREAI_TEXT_DOMAIN ),
			self::MARK_ANY,
			Source_Settings::any_source_enabled() || Status::is_free(),
			$review_note,
			1,
			Source_Settings::PARTNER_GROUP
		);
		$sections[] = $this->section(
			__( 'リライト', GACHIREAI_TEXT_DOMAIN ),
			'',
			true,
			'',
			1,
			Ec_Review_Use::GROUP
		);
		return $sections;
	}
	private function tab( string $slug, array $sections ): array {
		$single_ok = true;
		$has_group = array();
		$any_saved = array();
		foreach ( $sections as $section ) {
			$mark = (string) $section['section_mark'];
			$done = ! empty( $section['section_saved'] );
			if ( self::MARK_SINGLE === $mark && ! $done ) {
				$single_ok = false;
			}
			if ( self::MARK_EC === $mark || self::MARK_ANY === $mark ) {
				$has_group[ $mark ] = true;
				if ( $done ) {
					$any_saved[ $mark ] = true;
				}
			}
		}
		$groups_ok = true;
		foreach ( array_keys( $has_group ) as $mark ) {
			if ( empty( $any_saved[ $mark ] ) ) {
				$groups_ok = false;
			}
		}
		foreach ( $sections as $index => $section ) {
			$mark        = (string) $section['section_mark'];
			$is_group    = self::MARK_EC === $mark || self::MARK_ANY === $mark;
			$group_empty = $is_group && empty( $any_saved[ $mark ] );
			$sections[ $index ]['section_warning'] = empty( $section['section_saved'] )
				&& ( self::MARK_SINGLE === $mark || $group_empty );
		}
		return array(
			'tab_slug'   => $slug,
			'tab_label'  => Tabs::label( $slug ),
			'tab_status' => ( $single_ok && $groups_ok ) ? self::STATUS_COMPLETE : self::STATUS_INCOMPLETE,
			'sections'   => $sections,
		);
	}
	private function section( string $label, string $mark, bool $saved, string $note = '', int $span = 1, string $anchor = '' ): array {
		return array(
			'section_label'  => $label,
			'section_mark'   => $mark,
			'section_saved'  => $saved,
			'section_note'   => $note,
			'section_span'   => max( 1, $span ),
			'section_anchor' => $anchor,
		);
	}
	private function group_has_value( string $group ): bool {
		$values = $this->settings()->get( $group, array() );
		return is_array( $values ) && $this->array_has_value( $values );
	}
	private function array_has_value( array $values ): bool {
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				if ( $this->array_has_value( $value ) ) {
					return true;
				}
				continue;
			}
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}
	private function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}
		return $this->settings;
	}
	private function profile(): Site_Profile {
		if ( null === $this->profile ) {
			$this->profile = new Site_Profile();
		}
		return $this->profile;
	}
}

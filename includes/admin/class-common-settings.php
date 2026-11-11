<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Admin;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Amazon_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Rakuten_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Yahoo_Client;
use Tonkatsutei\Gachi_Review_AI\Common\Store;
use Tonkatsutei\Gachi_Review_AI\Image\Image_Catalog;
use Tonkatsutei\Gachi_Review_AI\Image\Manager as Image_Manager;
use Tonkatsutei\Gachi_Review_AI\Llm\Jev_Client;
use Tonkatsutei\Gachi_Review_AI\Llm\Model_Catalog;
use Tonkatsutei\Gachi_Review_AI\Mastodon\Mastodon_Client;
use Tonkatsutei\Gachi_Review_AI\Scraping\Connection_Tester;
use Tonkatsutei\Gachi_Review_AI\Scraping\Service_Catalog;
use Tonkatsutei\Gachi_Review_AI\Scraping\Store as Scraping_Store;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Youtube\YouTube_Client;
final class Common_Settings extends Screen {
	public const ACTION_SAVE = 'gachireai_common_save';
	private const NONCE_SAVE = 'gachireai_common_settings';
	public const SAVED_ARG = 'gachireai_common_saved';
	public const TESTED_ARG = 'gachireai_common_tested';
	private const TEST_TRANSIENT_PREFIX = 'gachireai_common_test_';
	public function render(): void {
		if ( ! current_user_can( 'manage_network' ) ) {
			return;
		}
		$this->common_mode = true;
		switch ( Common_Tabs::current() ) {
			case Common_Tabs::TAB_ASP:
				$this->render_sections( $this->asp_sections() );
				break;
			case Common_Tabs::TAB_LLM:
				$this->render_sections( $this->llm_sections() );
				break;
			case Common_Tabs::TAB_SCRAPING:
				$this->render_sections( $this->scraping_sections(), Scraping_Legend::model() );
				break;
			case Common_Tabs::TAB_REVIEW:
				$this->render_sections( $this->review_sections() );
				break;
			case Common_Tabs::TAB_MAINTENANCE:
				$this->render_maintenance();
				break;
			case Common_Tabs::TAB_DASHBOARD:
			default:
				$this->render_dashboard();
				break;
		}
	}
	private function render_dashboard(): void {
		$model = array(
			'page_title' => esc_html( Common_Tabs::heading( Common_Tabs::TAB_DASHBOARD ) ),
			'tabs'       => Common_Tabs::models( Common_Tabs::TAB_DASHBOARD ),
		) + License_Settings::form_model() + ( new Site_Create() )->form_model();
		$model['license_form_action']     = esc_url( Common_Tabs::url( Common_Tabs::TAB_DASHBOARD ) );
		$model['license_hidden_fields'][] = array(
			'name'  => esc_attr( License_Settings::COMMON_FIELD ),
			'value' => '1',
		);
		$this->render_template( 'admin/common-dashboard', $model );
	}
	private function render_maintenance(): void {
		$maintenance = new Maintenance();
		$maintenance->use_common_mode();
		$maintenance->render();
	}
	private function render_sections( array $sections, array $tag_descriptions = array() ): void {
		$model = array(
			'page_title' => esc_html( Common_Tabs::heading( Common_Tabs::current() ) ),
			'tabs'       => Common_Tabs::models( Common_Tabs::current() ),
			'sections'   => $sections,
		);
		if ( array() !== $tag_descriptions ) {
			$model['tag_descriptions'] = $tag_descriptions;
		}
		$this->render_template( 'admin/common-settings', $model );
	}
	private function asp_sections(): array {
		$store = new Store();
		$rakuten = (array) $store->get( 'rakuten', array() );
		$amazon  = (array) $store->get( 'amazon', array() );
		$yahoo   = (array) $store->get( 'yahoo', array() );
		$amazon['backend'] = ! empty( $amazon['use_creators_api'] ) ? Amazon_Client::BACKEND_CREATORS : Amazon_Client::BACKEND_FREE_AMZN;
		return array(
			$this->section_model(
				'rakuten',
				__( '楽天市場', GACHIREAI_TEXT_DOMAIN ),
				__( '楽天市場の商品検索とアフィリエイトリンクに使う ID を設定します。', GACHIREAI_TEXT_DOMAIN ),
				$this->definitions_rakuten(),
				'rakuten',
				$rakuten,
				$rakuten
			),
			$this->section_model(
				'amazon',
				__( 'Amazon', GACHIREAI_TEXT_DOMAIN ),
				__( 'Amazon の商品取得とアフィリエイトリンクに使う ID を設定します。', GACHIREAI_TEXT_DOMAIN ),
				$this->definitions_amazon(),
				'amazon',
				$amazon,
				$amazon
			),
			$this->section_model(
				'yahoo',
				__( 'Yahoo!ショッピング', GACHIREAI_TEXT_DOMAIN ),
				__( 'Yahoo!ショッピングの商品検索とアフィリエイトリンクに使う ID を設定します。', GACHIREAI_TEXT_DOMAIN ),
				$this->definitions_yahoo(),
				'yahoo',
				$yahoo,
				$yahoo
			),
		);
	}
	private function llm_sections(): array {
		$store = new Store();
		$entries   = $store->llm_entries();
		$active    = $store->active_llm_id();
		$providers = Model_Catalog::providers();
		if ( '' === $active ) {
			$active = (string) array_key_first( $providers );
		}
		$entry = (array) ( $entries[ $active ] ?? array() );
		$llm   = array(
			'provider' => $active,
			'api_key'  => (string) ( $entry['api_key'] ?? '' ),
			'model'    => (string) ( $entry['model'] ?? '' ),
		);
		$jev   = $store->jev();
		$image = $store->image();
		return array(
			$this->section_model(
				'llm',
				__( '文章生成AI', GACHIREAI_TEXT_DOMAIN ),
				__( '記事の生成に使う文章生成AIの API キーを設定します。', GACHIREAI_TEXT_DOMAIN ),
				$this->definitions_llm(),
				'llm',
				$llm,
				$llm
			),
			$this->section_model(
				'jev',
				__( '判定AI', GACHIREAI_TEXT_DOMAIN ),
				__( 'レビューを判定する判定AI（Jev）の API キーを設定します。判定AI（Jev）を使うと、収集したレビューを「採用・保留・除外」に自動判定でき、記事に使う素材の品質を保てます。判定AI（Jev）は安価な有料サービスです（Jev（typesafe.ai）のアカウントと API キーが必要です）。必須ではありません（任意設定）。未設定でもレビューの収集・記事生成は行われ、判定だけがスキップされます。', GACHIREAI_TEXT_DOMAIN ),
				$this->definitions_jev(),
				'jev',
				$jev,
				$jev
			),
			$this->section_model(
				'image',
				__( '画像生成AI', GACHIREAI_TEXT_DOMAIN ),
				__( 'ファビコン・プロフィール画像・アイキャッチなどの生成に使う画像生成AIの API キーを設定します。', GACHIREAI_TEXT_DOMAIN ),
				$this->definitions_image(),
				'image',
				$image,
				$image
			),
		);
	}
	private function scraping_sections(): array {
		$store    = new Store();
		$saved    = (array) $store->get( 'scraping.services', array() );
		$sections = array();
		foreach ( Service_Catalog::all() as $id => $definition ) {
			$row     = (array) ( $saved[ $id ] ?? array() );
			$current = array( 'api_key' => (string) ( $row['api_key'] ?? '' ) );
			$sections[] = $this->section_model(
				'scraping_' . $id,
				$definition['label'],
				$definition['features'],
				array(
					'api_key' => array(
						'label'       => __( 'APIキー', GACHIREAI_TEXT_DOMAIN ),
						'type'        => 'password',
						'max'         => 500,
						'description' => __( '保存済みのキーは表示されません。変更するときだけ入力してください。', GACHIREAI_TEXT_DOMAIN ),
					),
				),
				'',
				$current,
				$current,
				array(
					'used_by' => Scraping_Legend::tags( $id ),
					'url'     => esc_url( $definition['url'] ),
				)
			);
		}
		return $sections;
	}
	private function review_sections(): array {
		$store = new Store();
		$youtube  = (array) $store->get( 'youtube', array() );
		$mastodon = (array) $store->get( 'mastodon', array() );
		return array(
			$this->section_model(
				'youtube',
				__( 'YouTube', GACHIREAI_TEXT_DOMAIN ),
				__( 'YouTube Data API の接続設定です。', GACHIREAI_TEXT_DOMAIN ),
				$this->definitions_youtube(),
				'youtube',
				$youtube,
				$youtube
			),
			$this->section_model(
				'mastodon',
				__( 'Mastodon', GACHIREAI_TEXT_DOMAIN ),
				__( 'Mastodon の接続設定です。', GACHIREAI_TEXT_DOMAIN ),
				$this->definitions_mastodon(),
				'mastodon',
				$mastodon,
				$mastodon
			),
		);
	}
	private function section_model( string $section, string $title, string $description, array $definitions, string $prefix, array $values, array $current, array $extra = array() ): array {
		$form      = new Form( $definitions );
		$keys      = array_keys( $definitions );
		$display   = $values;
		$overrides = array();
		foreach ( $definitions as $key => $definition ) {
			if ( 'password' !== (string) ( $definition['type'] ?? '' ) ) {
				continue;
			}
			$secret            = isset( $current[ $key ] ) && is_scalar( $current[ $key ] ) ? (string) $current[ $key ] : '';
			$display[ $key ]   = '';
			$overrides[ $key ] = array( 'placeholder' => Form::mask_secret( $secret ) );
		}
		$override_sites = $this->override_sites( $section );
		$model = array(
			'section_key'   => esc_attr( 'common-' . $section ),
			'title'         => esc_html( $title ),
			'description'   => esc_html( $description ),
			'form_action'   => esc_url( admin_url( 'admin-post.php' ) ),
			'hidden_fields' => array(
				array( 'name' => 'action', 'value' => esc_attr( self::ACTION_SAVE ) ),
				array( 'name' => '_wpnonce', 'value' => esc_attr( wp_create_nonce( self::NONCE_SAVE ) ) ),
				array( 'name' => 'section', 'value' => esc_attr( $section ) ),
			),
			'fields'        => $form->build_list( $display, $keys, $prefix, $overrides ),
			'save_label'    => esc_html__( '保存', GACHIREAI_TEXT_DOMAIN ),
			'status_text'   => esc_html( $this->section_status( $section ) ),
			'messages'      => $this->result_messages( $section ),
			'common_override_sites' => $override_sites,
			'common_override_label' => esc_html__( 'このまとまりを独自設定しているサイト:', GACHIREAI_TEXT_DOMAIN ),
		);
		return array_merge( $model, $extra );
	}
	private function section_status( string $section ): string {
		if ( str_starts_with( $section, 'scraping_' ) ) {
			$row = Scraping_Store::get( substr( $section, strlen( 'scraping_' ) ) );
			return 'error' === (string) $row['status'] ? (string) $row['message'] : '';
		}
		if ( Connection_Status::is_revoked( $section, new Store() ) ) {
			return Connection_Status::revocation_message();
		}
		return '';
	}
	private function override_sites( string $group ): array {
		if ( ! is_multisite() || ! in_array( $group, Settings::COMMON_GROUPS, true ) ) {
			return array();
		}
		$sites = array();
		foreach ( (array) get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
			$blog_id = (int) $blog_id;
			if ( $blog_id === get_current_blog_id() ) {
				continue;
			}
			switch_to_blog( $blog_id );
			try {
				$overrides = ( new Settings() )->common_overrides();
				$name      = (string) get_bloginfo( 'name' );
			} finally {
				restore_current_blog();
			}
			if ( in_array( $group, $overrides, true ) ) {
				$sites[] = array(
					'site_name' => esc_html( $name ),
					'site_url'  => esc_url( get_admin_url( $blog_id ) ),
				);
			}
		}
		return $sites;
	}
	private function result_messages( string $section ): array {
		$saved  = isset( $_GET[ self::SAVED_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::SAVED_ARG ] ) ) : '';
		$tested = isset( $_GET[ self::TESTED_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::TESTED_ARG ] ) ) : '';
		if ( $saved !== $section ) {
			return array();
		}
		switch ( $tested ) {
			case 'success':
				$type = 'success';
				$text = __( '保存し、接続テストに成功しました。', GACHIREAI_TEXT_DOMAIN );
				break;
			case 'warning':
				$type = 'warning';
				$text = __( '保存しました。接続テストで警告が出ました。', GACHIREAI_TEXT_DOMAIN );
				break;
			case 'error':
				$type = 'error';
				$text = __( '接続テストに失敗したため、保存しませんでした。', GACHIREAI_TEXT_DOMAIN );
				break;
			default:
				$type = 'success';
				$text = __( '保存しました。', GACHIREAI_TEXT_DOMAIN );
				break;
		}
		$detail = $this->take_test_message();
		$text   = '' !== $detail ? $text . ' ' . $detail : $text;
		return array( $this->message( $type, esc_html( $text ) ) );
	}
	private function take_test_message(): string {
		$key     = self::TEST_TRANSIENT_PREFIX . get_current_user_id();
		$message = get_transient( $key );
		if ( false !== $message ) {
			delete_transient( $key );
			return is_scalar( $message ) ? (string) $message : '';
		}
		return '';
	}
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die(
				esc_html__( 'この操作を行う権限がありません。', GACHIREAI_TEXT_DOMAIN ),
				esc_html__( 'gachi-review-ai', GACHIREAI_TEXT_DOMAIN ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( self::NONCE_SAVE );
		$section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( (string) $_POST['section'] ) ) : '';
		$store = new Store();
		$type  = 'saved';
		$note  = '';
		switch ( $section ) {
			case 'rakuten':
				$values = $this->prepare_simple( $store, 'rakuten', $this->definitions_rakuten() );
				$result = ( new Rakuten_Client() )->test( $values );
				$type   = $this->apply_test( $result );
				if ( 'error' !== $type ) {
					$store->set( 'rakuten', $values );
					$this->record_verified( $store, 'rakuten', $result );
				}
				break;
			case 'amazon':
				$values = $this->prepare_amazon( $store );
				$result = ( new Amazon_Client() )->test( $values );
				$type   = $this->apply_test( $result );
				if ( 'error' !== $type ) {
					$store->set( 'amazon', $values );
					$this->record_verified( $store, 'amazon', $result );
				}
				break;
			case 'yahoo':
				$values = $this->prepare_simple( $store, 'yahoo', $this->definitions_yahoo() );
				$posted = isset( $_POST['yahoo'] ) && is_array( $_POST['yahoo'] ) ? (array) wp_unslash( $_POST['yahoo'] ) : array();
				$result = ( new Yahoo_Client() )->test( $values, $posted );
				$type   = $this->apply_test( $result );
				if ( 'error' !== $type ) {
					$store->set( 'yahoo', $values );
					$this->record_verified( $store, 'yahoo', $result );
				}
				break;
			case 'llm':
				$prepared = $this->prepare_llm( $store );
				$result   = ( new Api_Affiliate() )->test_llm( $prepared['test'] );
				$type     = $this->apply_test( $result );
				if ( 'error' !== $type && null !== $prepared['save'] ) {
					$store->set( 'llm', $prepared['save'] );
					$this->record_verified_state( $store, 'llm', '' !== (string) ( $prepared['test']['api_key'] ?? '' ) );
				}
				break;
			case 'jev':
				$values = $this->prepare_simple( $store, 'jev', $this->definitions_jev() );
				$result = ( new Jev_Client( (string) ( $values['api_key'] ?? '' ), (string) ( $values['model'] ?? '' ) ) )->test();
				$type   = $this->apply_test( $result );
				if ( 'error' !== $type ) {
					$store->set( 'jev', $values );
					$this->record_verified( $store, 'jev', $result );
				}
				break;
			case 'image':
				$prepared = $this->prepare_image( $store );
				$type     = $this->test_image( $prepared['test'] );
				if ( 'error' !== $type ) {
					$store->set( 'image', $prepared['save'] );
					$this->record_verified_state( $store, 'image', '' !== $store->image_api_key( $prepared['test'] ) );
				}
				break;
			case 'youtube':
				$values = $this->prepare_simple( $store, 'youtube', $this->definitions_youtube() );
				$result = ( new YouTube_Client() )->test( (string) ( $values['api_key'] ?? '' ) );
				$type   = $this->apply_test( $result );
				if ( 'error' !== $type ) {
					$store->set( 'youtube', $values );
					$this->record_verified( $store, 'youtube', $result );
				}
				break;
			case 'mastodon':
				$values = $this->prepare_simple( $store, 'mastodon', $this->definitions_mastodon() );
				$result = ( new Mastodon_Client() )->test(
					(string) ( $values['instance'] ?? '' ),
					(string) ( $values['token'] ?? '' )
				);
				$type   = $this->apply_test( $result );
				if ( 'error' !== $type ) {
					$store->set( 'mastodon', $values );
					$this->record_verified( $store, 'mastodon', $result );
				}
				break;
			default:
				if ( str_starts_with( $section, 'scraping_' ) ) {
					$service  = substr( $section, strlen( 'scraping_' ) );
					$prepared = $this->prepare_scraping( $store, $service );
					$test     = Connection_Tester::test( $service, (string) ( $prepared['test']['api_key'] ?? '' ) );
					$type     = ! empty( $test['ok'] ) ? 'success' : 'error';
					$note     = (string) ( $test['message'] ?? '' );
					if ( 'error' !== $type && null !== $prepared['save'] ) {
						$store->set( 'scraping.services', $prepared['save'] );
						$this->save_scraping_status( $store, $service, true, $note );
					}
				}
				break;
		}
		$this->redirect( $this->tab_for_section( $section ), $section, $type, $note );
	}
	private function record_verified( Store $store, string $group, array $result ): void {
		$connected = ! empty( $result['connected'] ) || 'success' === (string) ( $result['type'] ?? '' );
		$this->record_verified_state( $store, $group, $connected );
	}
	private function record_verified_state( Store $store, string $group, bool $connected ): void {
		if ( $connected ) {
			Connection_Status::mark_verified( $group, $store );
		} else {
			Connection_Status::mark_unverified( $group, $store );
		}
	}
	private function save_scraping_status( Store $store, string $service, bool $ok, string $message ): void {
		if ( ! Service_Catalog::exists( $service ) ) {
			return;
		}
		$rows             = (array) $store->get( 'scraping.services', array() );
		$rows[ $service ] = array_replace(
			(array) ( $rows[ $service ] ?? array() ),
			array(
				'status'    => $ok ? 'ok' : 'error',
				'message'   => mb_substr( trim( $message ), 0, 300 ),
				'tested_at' => (string) current_time( 'mysql' ),
			)
		);
		$store->set( 'scraping.services', $rows );
	}
	private function prepare_simple( Store $store, string $group, array $definitions ): array {
		$posted  = isset( $_POST[ $group ] ) && is_array( $_POST[ $group ] ) ? (array) wp_unslash( $_POST[ $group ] ) : array();
		$current = (array) $store->get( $group, array() );
		$clean   = ( new Form( $definitions ) )->sanitize( $posted, $current );
		return array_replace( $current, $clean );
	}
	private function prepare_amazon( Store $store ): array {
		$posted  = isset( $_POST['amazon'] ) && is_array( $_POST['amazon'] ) ? (array) wp_unslash( $_POST['amazon'] ) : array();
		$current = (array) $store->get( 'amazon', array() );
		$clean   = ( new Form( $this->definitions_amazon() ) )->sanitize( $posted, $current );
		if ( isset( $clean['backend'] ) ) {
			if ( Amazon_Client::BACKEND_CREATORS === (string) $clean['backend'] ) {
				$clean['use_creators_api']       = '1';
				$clean['use_free_amzn_creators'] = '';
			} else {
				$clean['use_free_amzn_creators'] = '1';
				$clean['use_creators_api']       = '';
			}
			unset( $clean['backend'] );
		}
		return array_replace( $current, $clean );
	}
	private function prepare_llm( Store $store ): array {
		$posted = isset( $_POST['llm'] ) && is_array( $_POST['llm'] ) ? (array) wp_unslash( $_POST['llm'] ) : array();
		$entries  = (array) $store->get( 'llm.entries', array() );
		$active   = (string) $store->get( 'llm.active', '' );
		$provider = isset( $posted['provider'] ) ? sanitize_key( (string) $posted['provider'] ) : $active;
		$current = array(
			'provider' => $provider,
			'api_key'  => (string) ( $entries[ $provider ]['api_key'] ?? '' ),
			'model'    => (string) ( $entries[ $provider ]['model'] ?? '' ),
		);
		$clean = ( new Form( $this->definitions_llm() ) )->sanitize( $posted, $current );
		$provider = (string) ( $clean['provider'] ?? '' );
		if ( '' === $provider ) {
			return array( 'save' => null, 'test' => $clean );
		}
		$entries[ $provider ] = array(
			'provider' => $provider,
			'api_key'  => Settings::trim_secret( (string) ( $clean['api_key'] ?? '' ) ),
			'model'    => Settings::trim_secret( (string) ( $clean['model'] ?? '' ) ),
		);
		return array(
			'save' => array(
				'active'  => $provider,
				'entries' => $entries,
			),
			'test' => $entries[ $provider ],
		);
	}
	private function prepare_image( Store $store ): array {
		$posted  = isset( $_POST['image'] ) && is_array( $_POST['image'] ) ? (array) wp_unslash( $_POST['image'] ) : array();
		$current = $store->image();
		$clean   = ( new Form( $this->definitions_image() ) )->sanitize( $posted, $current );
		return array(
			'save' => array_replace( (array) $store->get( 'image', array() ), $clean ),
			'test' => array_replace( $current, $clean ),
		);
	}
	private function prepare_scraping( Store $store, string $service ): array {
		if ( ! Service_Catalog::exists( $service ) ) {
			return array( 'save' => null, 'test' => array( 'api_key' => '' ) );
		}
		$posted  = isset( $_POST['api_key'] ) ? (string) wp_unslash( $_POST['api_key'] ) : '';
		$rows    = (array) $store->get( 'scraping.services', array() );
		$current = array( 'api_key' => (string) ( $rows[ $service ]['api_key'] ?? '' ) );
		$clean = ( new Form( array( 'api_key' => array( 'type' => 'password', 'max' => 500 ) ) ) )->sanitize(
			array( 'api_key' => $posted ),
			$current
		);
		$rows[ $service ] = array_replace( (array) ( $rows[ $service ] ?? array() ), $clean );
		return array( 'save' => $rows, 'test' => $clean );
	}
	private function test_image( array $values ): string {
		try {
			$provider = Image_Manager::provider( $values, (string) ( $values['api_key'] ?? '' ) );
			$result   = Image_Manager::test( $provider );
			if ( ! empty( $result['message'] ) ) {
				$this->store_test_message( (string) $result['message'] );
			}
			return (string) ( $result['type'] ?? 'saved' );
		} catch ( \Throwable $e ) {
			$this->store_test_message( $e->getMessage() );
			return 'error';
		}
	}
	private function apply_test( array $result ): string {
		if ( ! empty( $result['message'] ) ) {
			$this->store_test_message( (string) $result['message'] );
		}
		return (string) ( $result['type'] ?? 'saved' );
	}
	private function store_test_message( string $message ): void {
		$message = mb_substr( trim( $message ), 0, 300 );
		if ( '' !== $message ) {
			set_transient( self::TEST_TRANSIENT_PREFIX . get_current_user_id(), $message, 60 );
		}
	}
	private function redirect( string $tab, string $section, string $tested, string $message = '' ): void {
		if ( '' !== $message ) {
			$this->store_test_message( $message );
		}
		$url = Common_Tabs::url(
			$tab,
			array(
				self::SAVED_ARG  => $section,
				self::TESTED_ARG => $tested,
			)
		);
		$url .= '#gachireai-section-common-' . rawurlencode( $section );
		wp_safe_redirect( $url );
		exit;
	}
	private function tab_for_section( string $section ): string {
		if ( in_array( $section, array( 'rakuten', 'amazon', 'yahoo' ), true ) ) {
			return Common_Tabs::TAB_ASP;
		}
		if ( in_array( $section, array( 'llm', 'jev', 'image' ), true ) ) {
			return Common_Tabs::TAB_LLM;
		}
		if ( in_array( $section, array( 'youtube', 'mastodon' ), true ) ) {
			return Common_Tabs::TAB_REVIEW;
		}
		if ( str_starts_with( $section, 'scraping_' ) ) {
			return Common_Tabs::TAB_SCRAPING;
		}
		return Common_Tabs::TAB_DASHBOARD;
	}
	private function definitions_rakuten(): array {
		return array(
			'application_id' => array(
				'label'       => __( 'アプリケーションID', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'description' => __( '楽天ウェブサービスの「アプリケーションID」です。', GACHIREAI_TEXT_DOMAIN ),
			),
			'access_key'     => array(
				'label'       => __( 'アクセスキー', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 300,
				'description' => __( '保存済みのキーは表示されません。変更するときだけ入力してください。', GACHIREAI_TEXT_DOMAIN ),
			),
			'affiliate_id'   => array(
				'label'       => __( 'アフィリエイトID', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'description' => __( '楽天アフィリエイトの ID です（アフィリエイトURLの生成に使います）。', GACHIREAI_TEXT_DOMAIN ),
			),
			'site_url'       => array(
				'label'       => __( 'サイトURL', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 500,
				'description' => __( '楽天API に送るリクエスト元のサイト URL です。', GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function definitions_amazon(): array {
		return array(
			'backend'                    => array(
				'label'   => __( 'API設定', GACHIREAI_TEXT_DOMAIN ),
				'type'    => 'select',
				'options' => array(
					Amazon_Client::BACKEND_FREE_AMZN => __( 'Free AMZN Creators API', GACHIREAI_TEXT_DOMAIN ),
					Amazon_Client::BACKEND_CREATORS  => __( 'Creators API（公式）', GACHIREAI_TEXT_DOMAIN ),
				),
				'default' => Amazon_Client::BACKEND_FREE_AMZN,
			),
			Amazon_Client::CLIENT_ID     => array(
				'label'       => __( 'Credential ID', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 300,
				'description' => __( 'Creators API（公式）を使うときの Credential ID です。', GACHIREAI_TEXT_DOMAIN ),
			),
			Amazon_Client::CLIENT_SECRET => array(
				'label'       => __( 'Credential Secret', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 500,
				'description' => __( '保存済みの値は表示されません。変更するときだけ入力してください。', GACHIREAI_TEXT_DOMAIN ),
			),
			Amazon_Client::VERSION       => array(
				'label'       => __( 'Credential version', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 20,
				'default'     => '3-3',
				'placeholder' => '3-3',
			),
			'partner_tag'                => array(
				'label'       => __( 'パートナータグ', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 100,
				'description' => __( 'Amazon アソシエイトのトラッキング ID（例: xxxxx-22）です。', GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function definitions_yahoo(): array {
		return array(
			'client_id'      => array(
				'label'       => __( 'Client ID（アプリケーションID）', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 300,
				'description' => __( 'Yahoo!デベロッパーネットワークの Client ID です。', GACHIREAI_TEXT_DOMAIN ),
			),
			'affiliate_code' => array(
				'label'       => __( 'アフィリエイトコード', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'textarea',
				'max'         => 2000,
				'rows'        => 4,
				'description' => __( 'バリューコマースの「自由テキストリンク」のコードをそのまま貼り付けてください（URL を自動で取り出します）。', GACHIREAI_TEXT_DOMAIN ),
				'normalize'   => array( Yahoo_Client::class, 'extract_affiliate_url' ),
			),
		);
	}
	private function definitions_llm(): array {
		$providers = Model_Catalog::providers();
		$default   = (string) array_key_first( $providers );
		return array(
			'provider' => array(
				'label'   => __( '使用するAI', GACHIREAI_TEXT_DOMAIN ),
				'type'    => 'select',
				'options' => $providers,
				'default' => $default,
			),
			'api_key'  => array(
				'label'       => __( 'APIキー', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 500,
				'description' => __( '保存済みのキーは表示されません。変更するときだけ入力してください。', GACHIREAI_TEXT_DOMAIN ),
			),
			'model'    => array(
				'label'       => __( 'モデル', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'description' => __( '空欄ならプロバイダーの既定モデルを使います。', GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function definitions_jev(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'APIキー', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 500,
				'description' => __( '判定AI（Jev）は安価な有料サービスです（任意設定。Jev（typesafe.ai）で API キーを取得）。保存済みのキーは表示されません。変更するときだけ入力してください。', GACHIREAI_TEXT_DOMAIN ),
			),
			'model'   => array(
				'label'       => __( 'モデル', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'description' => __( '空欄なら既定モデル（jev-latest）を使います。', GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function definitions_image(): array {
		return array(
			'provider'   => array(
				'label'   => __( '使用するAI', GACHIREAI_TEXT_DOMAIN ),
				'type'    => 'select',
				'options' => Image_Catalog::provider_choices(),
				'default' => Image_Catalog::CLOUDFLARE,
			),
			'account_id' => array(
				'label'       => __( 'アカウントID', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'description' => __( 'Cloudflare Workers AI を使うときのアカウント ID です。', GACHIREAI_TEXT_DOMAIN ),
			),
			'api_key'    => array(
				'label'       => __( 'APIキー', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 500,
				'description' => __( '保存済みのキーは表示されません。変更するときだけ入力してください。', GACHIREAI_TEXT_DOMAIN ),
			),
			'model'      => array(
				'label'       => __( 'モデル', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'description' => __( '空欄ならプロバイダーの既定モデルを使います。', GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function definitions_youtube(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'APIキー', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 500,
				'description' => __( 'YouTube Data API v3 の API キーです。保存済みのキーは表示されません。', GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
	private function definitions_mastodon(): array {
		return array(
			'instance'     => array(
				'label'       => __( 'インスタンス', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'text',
				'max'         => 200,
				'placeholder' => 'mastodon.social',
				'description' => __( '接続先のインスタンス（例: mastodon.social）です。', GACHIREAI_TEXT_DOMAIN ),
			),
			'token'        => array(
				'label'       => __( 'アクセストークン', GACHIREAI_TEXT_DOMAIN ),
				'type'        => 'password',
				'max'         => 500,
				'description' => __( 'read:search スコープのユーザートークンです。保存済みの値は表示されません。', GACHIREAI_TEXT_DOMAIN ),
			),
		);
	}
}

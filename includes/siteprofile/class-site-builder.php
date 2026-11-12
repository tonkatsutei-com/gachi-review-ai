<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\SiteProfile;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Connection_Status;
use Tonkatsutei\Gachi_Review_AI\Article\Ai_Review;
use Tonkatsutei\Gachi_Review_AI\Article\Article_Generator;
use Tonkatsutei\Gachi_Review_AI\Article\Dialogue_Character;
use Tonkatsutei\Gachi_Review_AI\Common\Store as Common_Store;
use Tonkatsutei\Gachi_Review_AI\Ec\Genre_Catalog;
use Tonkatsutei\Gachi_Review_AI\Ec\Product_Selector;
use Tonkatsutei\Gachi_Review_AI\Event_Logger;
use Tonkatsutei\Gachi_Review_AI\Image\Favicon;
use Tonkatsutei\Gachi_Review_AI\Image\Manager as Image_Manager;
use Tonkatsutei\Gachi_Review_AI\Image\Profile as Image_Profile;
use Tonkatsutei\Gachi_Review_AI\Image\Top_Eyecatch;
use Tonkatsutei\Gachi_Review_AI\Policy\Policy_Page;
use Tonkatsutei\Gachi_Review_AI\Settings;
use Tonkatsutei\Gachi_Review_AI\Top\Top_Page;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Site_Builder {
	public const CHOICE_AUTO = 'auto';
	public const EC_CHOICES = array( self::CHOICE_AUTO, Product_Selector::EC_RAKUTEN, Product_Selector::EC_AMAZON, Product_Selector::EC_YAHOO );
	public const TYPE_CHOICES = array( self::CHOICE_AUTO, 'general', 'category', 'niche' );
	public const ARTICLE_CHOICES = array( self::CHOICE_AUTO, 'summary', 'quote', 'experience', 'dialogue' );
	private const TIME_LIMIT = 300;
	private const STEP_TOTAL = 8;
	public function preflight(): array {
		$errors = array();
		if ( ! is_multisite() ) {
			return array( __( 'マルチサイトでのみ利用できます。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( function_exists( 'is_plugin_active_for_network' ) && defined( 'GACHIREAI_PLUGIN_FILE' ) ) {
			if ( ! is_plugin_active_for_network( plugin_basename( GACHIREAI_PLUGIN_FILE ) ) ) {
				$errors[] = __( 'プラグインがネットワークで有効化されていません。ネットワーク管理で「ネットワークで有効化」してください。', GACHIREAI_TEXT_DOMAIN );
			}
		}
		$store = new Common_Store();
		if ( ! Connection_Status::is_verified( 'llm', $store ) ) {
			$errors[] = __( '文章生成AIの API キーが未設定か、接続テストに合格していません（AI設定タブ）。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( array() === $this->available_ecs() ) {
			$errors[] = __( 'メインECに使える EC がありません（ASP設定タブで接続テストに合格してください）。', GACHIREAI_TEXT_DOMAIN );
		}
		if ( ! Image_Manager::is_available( (array) $store->image(), $store ) ) {
			$errors[] = __( '画像生成AIが使える状態ではありません（AI設定タブの画像生成AI）。', GACHIREAI_TEXT_DOMAIN );
		}
		return $errors;
	}
	public function build( string $theme, string $ec_choice, string $type_choice, string $article_choice, ?callable $progress = null ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_LIMIT );
		}
		$theme = trim( $theme );
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_INFO,
			__( 'AIサイト生成を開始しました。', GACHIREAI_TEXT_DOMAIN ),
			array(
				'theme'   => $theme,
				'ec'      => $ec_choice,
				'type'    => $type_choice,
				'article' => $article_choice,
			)
		);
		$errors = $this->preflight();
		if ( array() !== $errors ) {
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_ERROR,
				__( 'AIサイト生成を中止しました（設定の不備）。', GACHIREAI_TEXT_DOMAIN ),
				array( 'errors' => $errors )
			);
			throw new \RuntimeException(
				implode(
					"\n",
					array_merge(
						array( __( '設定に不備があるため中止しました（サイトは作成していません）。', GACHIREAI_TEXT_DOMAIN ) ),
						array_map( static fn( string $error ): string => '・' . $error, $errors )
					)
				)
			);
		}
		$this->notify( $progress, __( '設定を確認しました。', GACHIREAI_TEXT_DOMAIN ), 1 );
		try {
			$ec      = $this->resolve_ec( $ec_choice );
			$type    = $this->resolve_type( $type_choice, $ec );
			$article = $this->resolve_article( $article_choice );
			$this->notify( $progress, __( 'プロフィールを生成中...', GACHIREAI_TEXT_DOMAIN ), 2 );
			$profile_parts = $this->generate_profile_parts( $theme, $type );
			$site_elements = $profile_parts['site_elements'];
			$this->notify( $progress, __( '子サイトを作成中...', GACHIREAI_TEXT_DOMAIN ), 3 );
			$user_id               = get_current_user_id();
			$slug                  = $this->generate_site_slug( (string) $site_elements['site_name'], $theme );
			list( $domain, $path ) = $this->unique_site_location( $slug );
			$blog_id               = $this->insert_site( $domain, $path, (string) $site_elements['site_name'], $user_id );
			switch_to_blog( $blog_id );
			try {
				$result = $this->build_in_site( $theme, $ec, $type, $article, $profile_parts, $user_id, $progress );
			} catch ( \Throwable $e ) {
				restore_current_blog();
				wp_delete_site( $blog_id );
				throw $e;
			}
			restore_current_blog();
			$this->notify( $progress, __( '完了しました。', GACHIREAI_TEXT_DOMAIN ), self::STEP_TOTAL );
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_INFO,
				sprintf(
					__( 'AIサイト生成が完了しました: %s', GACHIREAI_TEXT_DOMAIN ),
					(string) $site_elements['site_name']
				),
				array(
					'blog_id'  => $blog_id,
					'site_url' => get_site_url( $blog_id ),
					'ec'       => $ec,
					'type'     => $type,
					'article'  => $article,
				)
			);
			return $result + array(
				'blog_id'      => $blog_id,
				'site_name'    => (string) $site_elements['site_name'],
				'theme'        => $theme,
				'ec'           => $ec,
				'site_type'    => $type,
				'article_type' => $article,
				'site_url'     => get_site_url( $blog_id ),
				'admin_url'    => get_admin_url( $blog_id ),
			);
		} catch ( \Throwable $e ) {
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_ERROR,
				sprintf(
					__( 'AIサイト生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					mb_substr( trim( $e->getMessage() ), 0, 300 )
				),
				array(
					'theme'   => $theme,
					'ec'      => $ec_choice,
					'type'    => $type_choice,
					'article' => $article_choice,
				)
			);
			throw $e;
		}
	}
	private function build_in_site( string $theme, string $ec, string $type, string $article, array $profile_parts, int $user_id, ?callable $progress = null ): array {
		$this->notify( $progress, __( '設定を保存中...', GACHIREAI_TEXT_DOMAIN ), 4 );
		$scope = $this->prepare_scope( $ec, $type, $theme, (string) ( $profile_parts['keywords'] ?? '' ) );
		$settings = new Settings();
		$ec_group              = (array) $settings->get( 'ec', array() );
		$ec_group['ec']        = $ec;
		$ec_group['selection'] = $this->default_selection( $ec );
		$settings->set( 'ec', $ec_group );
		$article_group                 = (array) $settings->get( 'article', array() );
		$article_group['article_type'] = $article;
		$settings->set( 'article', $article_group );
		$settings->set(
			'ai_review',
			array(
				Ai_Review::KEY_MODE  => Ai_Review::MODE_ONLY,
				Ai_Review::KEY_COUNT => Ai_Review::DEFAULT_COUNT,
			)
		);
		$profile = new Site_Profile();
		$profile->save(
			array(
				'my_persona'     => $profile_parts['my_persona'],
				'scope'          => $scope,
				'target_persona' => $profile_parts['target_persona'],
				'site_elements'  => $profile_parts['site_elements'],
			)
		);
		update_option( 'blogname', (string) $profile_parts['site_elements']['site_name'] );
		update_option( 'blogdescription', (string) $profile_parts['site_elements']['description'] );
		if ( 'dialogue' === $article ) {
			$this->generate_dialogue_mentee( $settings, $profile );
		}
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_INFO,
			__( 'サイトの設定を保存しました。', GACHIREAI_TEXT_DOMAIN ),
			array(
				'ec'        => $ec,
				'site_type' => $type,
				'article'   => $article,
				'category'  => (string) ( $scope['category'] ?? '' ),
				'products'  => (string) ( $scope['products'] ?? '' ),
			)
		);
		$this->delete_sample_content();
		$this->notify( $progress, __( '固定ページを生成中...', GACHIREAI_TEXT_DOMAIN ), 5 );
		$top_id = Top_Page::generate( $profile );
		if ( $top_id <= 0 ) {
			throw new \RuntimeException( __( 'トップページの生成に失敗しました。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$policy_id = Policy_Page::generate();
		if ( $policy_id <= 0 ) {
			throw new \RuntimeException( __( 'プライバシーポリシーの生成に失敗しました。', GACHIREAI_TEXT_DOMAIN ) );
		}
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_INFO,
			__( '固定ページを生成しました（トップ／プライバシーポリシー）。', GACHIREAI_TEXT_DOMAIN ),
			array(
				'top_page_id'    => $top_id,
				'policy_page_id' => $policy_id,
			)
		);
		$this->notify( $progress, __( 'サイト画像を生成中...', GACHIREAI_TEXT_DOMAIN ), 6 );
		$image_failures = $this->generate_site_images( (string) $settings->image()['fail_behavior'] );
		if ( array() === $image_failures ) {
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_INFO,
				__( 'サイト画像を生成しました（ファビコン／プロフィール／トップ画）。', GACHIREAI_TEXT_DOMAIN )
			);
		} else {
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_WARN,
				sprintf(
					__( '一部のサイト画像を生成できませんでした（設定どおり続行しました）: %s', GACHIREAI_TEXT_DOMAIN ),
					implode( ' / ', $image_failures )
				)
			);
		}
		$this->notify( $progress, __( '最初の記事を生成・公開中...', GACHIREAI_TEXT_DOMAIN ), 7 );
		$first_post = $this->generate_first_post( $user_id );
		Event_Logger::record(
			Event_Logger::TYPE_GENERATE,
			Event_Logger::LEVEL_INFO,
			sprintf(
				! empty( $first_post['published'] )
					? __( '最初の記事を公開しました: %s', GACHIREAI_TEXT_DOMAIN )
					: __( '最初の記事を下書きとして保存しました（アイキャッチ画像を生成できませんでした）: %s', GACHIREAI_TEXT_DOMAIN ),
				(string) $first_post['title']
			),
			array( 'post_id' => (int) $first_post['post_id'] )
		);
		return array(
			'top_page_id'    => $top_id,
			'policy_page_id' => $policy_id,
			'first_post'     => $first_post,
			'persona_name'   => (string) ( $profile_parts['my_persona']['name'] ?? '' ),
			'scope_label'    => (string) ( $scope['category'] ?? '' ),
		);
	}
	private function generate_dialogue_mentee( Settings $settings, Site_Profile $profile ): void {
		$mentee = ( new Dialogue_Character( $settings, $profile ) )->generate_mentee();
		$article_group      = (array) $settings->get( 'article', array() );
		$dialogue           = is_array( $article_group['dialogue'] ?? null ) ? (array) $article_group['dialogue'] : array();
		$dialogue['mentee'] = array(
			'name'   => (string) ( $mentee['name'] ?? '' ),
			'gender' => (string) ( $mentee['gender'] ?? '' ),
			'age'    => (string) ( $mentee['age'] ?? '' ),
			'tone'   => (string) ( $mentee['tone'] ?? '' ),
			'image'  => (int) ( $mentee['attachment_id'] ?? 0 ),
		);
		$article_group['dialogue'] = $dialogue;
		$settings->set( 'article', $article_group );
		Event_Logger::record(
			Event_Logger::TYPE_SYSTEM,
			Event_Logger::LEVEL_INFO,
			sprintf(
				__( '対話のメンティーを生成しました: %s', GACHIREAI_TEXT_DOMAIN ),
				(string) $dialogue['mentee']['name']
			),
			array( 'image' => (int) $dialogue['mentee']['image'] )
		);
		if ( '' !== (string) ( $mentee['image_error'] ?? '' ) ) {
			Event_Logger::record(
				Event_Logger::TYPE_SYSTEM,
				Event_Logger::LEVEL_WARN,
				sprintf(
					__( 'メンティー画像の生成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					mb_substr( (string) $mentee['image_error'], 0, 300 )
				)
			);
		}
	}
	private function notify( ?callable $progress, string $label, int $phase ): void {
		if ( function_exists( 'connection_aborted' ) && 1 === connection_aborted() ) {
			throw new \RuntimeException( __( '生成を中断しました。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( null !== $progress ) {
			$progress( $label, $phase, self::STEP_TOTAL );
		}
	}
	private function generate_profile_parts( string $theme, string $type ): array {
		$generator = new Site_Generator();
		if ( ! $generator->is_available() ) {
			throw new \RuntimeException( Site_Generator::unavailable_message() );
		}
		$context = array(
			'site_type' => $type,
			'products'  => $theme,
		);
		$persona = $this->clean_text_fields(
			$generator->structure_from_free_text(
				$theme,
				$context,
				array(
					'gender' => 'male',
					'tone'   => 'polite',
				)
			),
			Site_Generator::generated_keys()
		);
		$persona['gender'] = 'male';
		$persona['tone']   = 'polite';
		$target = $this->clean_text_fields(
			$generator->structure_target_persona( $theme, $context, $persona ),
			Site_Generator::target_generated_keys()
		);
		$candidates = $generator->generate_site_elements( $context, $persona, $target );
		$site_name  = $this->first_candidate( (array) ( $candidates['site_name'] ?? array() ) );
		if ( '' === $site_name ) {
			throw new \RuntimeException( __( 'サイト名の生成に失敗しました。コンセプトを変えて、もう一度お試しください。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$elements = array(
			'site_name'   => sanitize_text_field( $site_name ),
			'description' => sanitize_textarea_field( $this->first_candidate( (array) ( $candidates['description'] ?? array() ) ) ),
			'profile'     => sanitize_textarea_field( $this->first_candidate( (array) ( $candidates['profile'] ?? array() ) ) ),
		);
		$elements['writing_sample'] = sanitize_textarea_field( $generator->generate_writing_sample( $persona, $elements ) );
		$keywords = 'niche' === $type ? $generator->keywords_from_theme( $theme ) : '';
		return array(
			'my_persona'     => $persona,
			'target_persona' => $target,
			'site_elements'  => $elements,
			'keywords'       => $keywords,
		);
	}
	private function prepare_scope( string $ec, string $type, string $theme, string $keywords = '' ): array {
		$scope = array( 'site_type' => $type );
		if ( 'general' === $type ) {
			return $scope;
		}
		$catalog = new Genre_Catalog();
		$nodes   = $this->genre_nodes( $catalog, $ec );
		if ( array() === $nodes ) {
			if ( 'niche' === $type && '' !== $theme ) {
				$scope['products'] = '' !== $keywords ? $keywords : $theme;
			}
			return $scope;
		}
		$node = $this->pick_genre( $nodes, $theme );
		$scope['category'] = Genre_Catalog::format_entry( (string) ( $node['genre_id'] ?? '' ), (string) ( $node['name'] ?? '' ) );
		if ( 'niche' === $type ) {
			if ( '' !== $keywords ) {
				$scope['products'] = $keywords;
			} else {
				$scope['products'] = '' !== $theme ? $theme : (string) ( $node['name'] ?? '' );
			}
		}
		return $scope;
	}
	private function genre_nodes( Genre_Catalog $catalog, string $ec ): array {
		if ( Product_Selector::EC_AMAZON === $ec ) {
			$nodes = array();
			foreach ( $catalog->known( $ec, 300 ) as $node ) {
				if ( 2 === (int) ( $node['level'] ?? 0 ) ) {
					$nodes[] = $node;
				}
			}
			return $nodes;
		}
		return $catalog->at_level( $ec, 2, 15 );
	}
	private function pick_genre( array $nodes, string $theme ): array {
		if ( '' !== $theme ) {
			foreach ( $nodes as $node ) {
				$name = (string) ( $node['name'] ?? '' );
				if ( '' !== $name && false !== mb_strpos( $theme, $name ) ) {
					return $node;
				}
			}
		}
		return $nodes[ array_rand( $nodes ) ];
	}
	private function generate_first_post( int $user_id ): array {
		$settings = new Settings();
		$image    = $settings->image();
		$saved    = ( new Article_Generator( $settings ) )->generate( null, null, '', '', true, $user_id, '', true );
		$post_id  = (int) ( $saved['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			throw new \RuntimeException( __( '最初の記事の保存に失敗しました。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$permalink = get_permalink( $post_id );
		if ( 'failed' === (string) ( $saved['eyecatch_status'] ?? '' ) && Settings::IMAGE_FAIL_CONTINUE !== (string) $image['fail_behavior'] ) {
			Event_Logger::record(
				Event_Logger::TYPE_GENERATE,
				Event_Logger::LEVEL_WARN,
				sprintf(
					__( '最初の記事のアイキャッチ画像を生成できなかったため、公開せずに下書きのまま残しました: %s', GACHIREAI_TEXT_DOMAIN ),
					mb_substr( (string) ( $saved['eyecatch_message'] ?? '' ), 0, 300 )
				),
				array( 'post_id' => $post_id )
			);
			return array(
				'post_id'   => $post_id,
				'title'     => (string) ( $saved['post_title'] ?? '' ),
				'permalink' => is_string( $permalink ) ? $permalink : '',
				'published' => false,
			);
		}
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		return array(
			'post_id'   => $post_id,
			'title'     => (string) ( $saved['post_title'] ?? '' ),
			'permalink' => is_string( $permalink ) ? $permalink : '',
			'published' => true,
		);
	}
	private function generate_site_images( string $fail_behavior ): array {
		$generators = array(
			__( 'ファビコン', GACHIREAI_TEXT_DOMAIN )             => new Favicon(),
			__( 'プロフィール画像', GACHIREAI_TEXT_DOMAIN )       => new Image_Profile(),
			__( 'トップページ用アイキャッチ', GACHIREAI_TEXT_DOMAIN ) => new Top_Eyecatch(),
		);
		$failures = array();
		foreach ( $generators as $label => $generator ) {
			try {
				$generator->generate();
			} catch ( \Throwable $e ) {
				if ( Settings::IMAGE_FAIL_CONTINUE !== $fail_behavior ) {
					throw $e;
				}
				$failures[] = $label;
			}
		}
		return $failures;
	}
	private function delete_sample_content(): void {
		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$samples = array( 'hello-world', 'sample-page' );
		foreach ( $posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( in_array( (string) $post->post_name, $samples, true ) ) {
				wp_delete_post( (int) $post_id, true );
			}
		}
	}
	private function insert_site( string $domain, string $path, string $title, int $user_id ): int {
		$blog_id = wpmu_create_blog( $domain, $path, $title, $user_id, array(), get_current_network_id() );
		if ( is_wp_error( $blog_id ) ) {
			throw new \RuntimeException(
				sprintf(
					__( '子サイトの作成に失敗しました: %s', GACHIREAI_TEXT_DOMAIN ),
					$blog_id->get_error_message()
				)
			);
		}
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 ) {
			throw new \RuntimeException( __( '子サイトの作成に失敗しました（ブログ ID を取得できませんでした）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $blog_id;
	}
	private function unique_site_location( string $base ): array {
		$network   = function_exists( 'get_network' ) ? get_network() : null;
		$domain    = is_object( $network ) && isset( $network->domain ) ? (string) $network->domain : '';
		$root_path = is_object( $network ) && isset( $network->path ) ? (string) $network->path : '/';
		$subdomain = function_exists( 'is_subdomain_install' ) && is_subdomain_install();
		for ( $i = 1; $i <= 100; $i++ ) {
			$slug = 1 === $i ? $base : $base . '-' . $i;
			if ( $subdomain ) {
				$site_domain = '' !== $domain ? $slug . '.' . $domain : $slug;
				$site_path   = '/';
			} else {
				$site_domain = $domain;
				$site_path   = '/' . ltrim( rtrim( $root_path, '/' ) . '/' . $slug . '/', '/' );
			}
			if ( 0 === (int) get_blog_id_from_url( $site_domain, $site_path ) ) {
				return array( $site_domain, $site_path );
			}
		}
		throw new \RuntimeException( __( '子サイトの URL の空きが見つかりませんでした。', GACHIREAI_TEXT_DOMAIN ) );
	}
	private function generate_site_slug( string $site_name, string $theme ): string {
		try {
			$slug = ( new Site_Generator() )->generate_slug( $site_name, $theme );
			if ( '' !== $slug ) {
				return $slug;
			}
		} catch ( \Throwable $e ) {
		}
		return $this->slug_base( $site_name );
	}
	private function slug_base( string $name ): string {
		$slug = strtolower( (string) sanitize_title( $name ) );
		$slug = (string) preg_replace( '/[^a-z0-9\-]+/', '', $slug );
		$slug = trim( $slug, '-' );
		$slug = substr( $slug, 0, 40 );
		return '' !== $slug ? $slug : 'site';
	}
	private function available_ecs(): array {
		$store = new Common_Store();
		$ecs   = array();
		foreach ( array( Product_Selector::EC_RAKUTEN, Product_Selector::EC_YAHOO, Product_Selector::EC_AMAZON ) as $ec ) {
			if ( Product_Selector::AVAILABILITY_READY === Product_Selector::availability_of( $ec, $store ) ) {
				$ecs[] = $ec;
			}
		}
		return $ecs;
	}
	private function resolve_ec( string $choice ): string {
		$available = $this->available_ecs();
		if ( self::CHOICE_AUTO === $choice ) {
			return $available[ array_rand( $available ) ];
		}
		$ec = Product_Selector::normalize_ec( $choice );
		if ( ! in_array( $ec, $available, true ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'メインEC「%s」は接続テストに合格していないため使えません。', GACHIREAI_TEXT_DOMAIN ),
					(string) ( self::ec_labels()[ $ec ] ?? $ec )
				)
			);
		}
		return $ec;
	}
	private function resolve_type( string $choice, string $ec ): string {
		$amazon_niche_only = Product_Selector::EC_AMAZON === $ec && ! $this->amazon_supports_genres();
		if ( self::CHOICE_AUTO !== $choice && in_array( $choice, array( 'general', 'category', 'niche' ), true ) ) {
			if ( $amazon_niche_only && 'niche' !== $choice ) {
				throw new \RuntimeException( __( 'Amazon では「総合」「カテゴリー限定」を選べません。「ニッチ」にするか、別のメインECを選んでください。', GACHIREAI_TEXT_DOMAIN ) );
			}
			return $choice;
		}
		if ( $amazon_niche_only ) {
			return 'niche';
		}
		$types = array( 'general', 'category', 'niche' );
		return $types[ array_rand( $types ) ];
	}
	private function amazon_supports_genres(): bool {
		$amazon   = (array) ( new Common_Store() )->get( 'amazon', array() );
		$free     = ! empty( $amazon['use_free_amzn_creators'] );
		$creators = ! empty( $amazon['use_creators_api'] );
		return ! ( $free && ! $creators );
	}
	private function resolve_article( string $choice ): string {
		if ( self::CHOICE_AUTO === $choice ) {
			return 'summary';
		}
		if ( ! in_array( $choice, array( 'summary', 'quote', 'experience', 'dialogue' ), true ) ) {
			throw new \RuntimeException( __( '記事タイプの指定が不正です。', GACHIREAI_TEXT_DOMAIN ) );
		}
		return $choice;
	}
	private function default_selection( string $ec ): string {
		$options = Product_Selector::selection_options( $ec );
		$first   = array_key_first( $options );
		return is_string( $first ) ? $first : '';
	}
	private function first_candidate( array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
				return trim( (string) $candidate );
			}
		}
		return '';
	}
	private function clean_text_fields( array $raw, array $keys ): array {
		$clean = array();
		foreach ( $keys as $key ) {
			$value = $raw[ $key ] ?? '';
			if ( is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}
	public static function ec_labels(): array {
		return array(
			Product_Selector::EC_RAKUTEN => __( '楽天市場', GACHIREAI_TEXT_DOMAIN ),
			Product_Selector::EC_AMAZON  => __( 'Amazon', GACHIREAI_TEXT_DOMAIN ),
			Product_Selector::EC_YAHOO   => __( 'Yahoo!ショッピング', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function type_labels(): array {
		return array(
			'general'  => __( '総合', GACHIREAI_TEXT_DOMAIN ),
			'category' => __( 'カテゴリー限定', GACHIREAI_TEXT_DOMAIN ),
			'niche'    => __( 'ニッチ', GACHIREAI_TEXT_DOMAIN ),
		);
	}
	public static function article_labels(): array {
		return array(
			'summary'    => __( '総評型', GACHIREAI_TEXT_DOMAIN ),
			'quote'      => __( '引用型', GACHIREAI_TEXT_DOMAIN ),
			'experience' => __( '体験談型', GACHIREAI_TEXT_DOMAIN ),
			'dialogue'   => __( '対話型', GACHIREAI_TEXT_DOMAIN ),
		);
	}
}

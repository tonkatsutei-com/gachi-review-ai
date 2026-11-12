<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Network_Setup {
	public const STATE_NOT_DEFINED = 'not_defined';
	public const STATE_ALLOW_DEFINED = 'allow_defined';
	public const STATE_TABLES_EXIST = 'tables_exist';
	public const STATE_COMPLETE = 'complete';
	private const WP_CONFIG_MARKERS = array(
		"/* That's all, stop editing! Happy publishing. */",
		'/* That’s all, stop editing! Happy publishing. */',
		"/* That's all, stop editing! */",
		'/* That’s all, stop editing! */',
	);
	private const BACKUP_SUFFIX = '.gachireai-bak-';
	private const PLUGINS_OPTION = 'gachireai_network_plugins_pending';
	public function state(): string {
		if ( is_multisite() ) {
			return self::STATE_COMPLETE;
		}
		if ( ! defined( 'WP_ALLOW_MULTISITE' ) || ! WP_ALLOW_MULTISITE ) {
			return self::STATE_NOT_DEFINED;
		}
		if ( $this->network_tables_exist() ) {
			return self::STATE_TABLES_EXIST;
		}
		return self::STATE_ALLOW_DEFINED;
	}
	public function preflight( string $step ): array {
		$errors = array();
		$path   = $this->wp_config_path();
		if ( null === $path ) {
			$errors[] = __( 'wp-config.php が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN );
		} else {
			if ( ! $this->is_writable( $path ) ) {
				$errors[] = sprintf(
					__( 'wp-config.php に書き込めません（読み取り専用の可能性があります）。%s', GACHIREAI_TEXT_DOMAIN ),
					$path
				);
			}
			$content = $this->read( $path );
			if ( null === $content || null === $this->marker_position( $content ) ) {
				$errors[] = __( 'wp-config.php に挿入位置（「That\'s all, stop editing!」の行）が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN );
			}
		}
		if ( 'apply' === $step ) {
			if ( ! $this->network_tables_exist() ) {
				$errors[] = __( 'ネットワークテーブルがまだ作成されていません。「ツール → ネットワーク設定」を先に完了してください。', GACHIREAI_TEXT_DOMAIN );
			}
			$htaccess = $this->htaccess_path();
			if ( ! $this->is_writable( $htaccess ) ) {
				$errors[] = sprintf(
					__( '.htaccess に書き込めません（読み取り専用の可能性があります）。%s', GACHIREAI_TEXT_DOMAIN ),
					$htaccess
				);
			}
		}
		return $errors;
	}
	public function wp_config_path(): ?string {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		if ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) ) {
			return dirname( ABSPATH ) . '/wp-config.php';
		}
		return null;
	}
	public function htaccess_path(): string {
		return ABSPATH . '.htaccess';
	}
	public function is_wp_config_writable(): bool {
		$path = $this->wp_config_path();
		return null !== $path && $this->is_writable( $path );
	}
	public function is_htaccess_writable(): bool {
		return $this->is_writable( $this->htaccess_path() );
	}
	public function domain(): string {
		$home   = (string) get_option( 'home' );
		$domain = (string) wp_parse_url( $home, PHP_URL_HOST );
		return '' !== $domain ? $domain : (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_HOST );
	}
	public function base_path(): string {
		$path = wp_parse_url( trailingslashit( (string) get_option( 'home' ) ), PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}
	public function subdomain_install(): bool {
		global $wpdb;
		if ( ! $this->network_tables_exist() ) {
			return false;
		}
		$sitemeta = $wpdb->base_prefix . 'sitemeta';
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$sitemeta} WHERE site_id = 1 AND meta_key = %s",
				'subdomain_install'
			)
		);
		return ! empty( $value );
	}
	public function subdirectory_disallowed(): bool {
		global $wpdb;
		$post = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_date < DATE_SUB(NOW(), INTERVAL 1 MONTH) AND post_status = 'publish'"
		);
		return ! empty( $post );
	}
	public function write_allow_multisite(): void {
		$errors = $this->preflight( 'allow' );
		if ( array() !== $errors ) {
			throw new \RuntimeException( implode( "\n", $errors ) );
		}
		$path = (string) $this->wp_config_path();
		if ( $this->contains_definition( $path, 'WP_ALLOW_MULTISITE' ) ) {
			return;
		}
		$content = $this->read( $path );
		if ( null === $content ) {
			throw new \RuntimeException( __( 'wp-config.php を読み込めませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$block = "/* Multisite（gachi-review-ai により追加） */\n"
			. "define( 'WP_ALLOW_MULTISITE', true );\n";
		$new = $this->insert_before_marker( $content, $block );
		if ( null === $new ) {
			throw new \RuntimeException( __( 'wp-config.php に挿入位置が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$this->write_safely( $path, $new, true );
	}
	public function write_constants_and_htaccess(): void {
		$errors = $this->preflight( 'apply' );
		if ( array() !== $errors ) {
			throw new \RuntimeException( implode( "\n", $errors ) );
		}
		$path    = (string) $this->wp_config_path();
		$content = $this->read( $path );
		if ( null === $content ) {
			throw new \RuntimeException( __( 'wp-config.php を読み込めませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( ! $this->contains_definition( $path, 'MULTISITE' ) ) {
			$new = $this->insert_before_marker( $content, $this->constants_block() );
			if ( null === $new ) {
				throw new \RuntimeException( __( 'wp-config.php に挿入位置が見つかりませんでした。', GACHIREAI_TEXT_DOMAIN ) );
			}
			$this->write_safely( $path, $new, true );
		}
		$this->write_htaccess();
	}
	public function record_and_deactivate_plugins(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active = array_values(
			array_filter(
				(array) get_option( 'active_plugins', array() ),
				'is_string'
			)
		);
		$self   = plugin_basename( GACHIREAI_PLUGIN_FILE );
		$targets = array_values(
			array_filter(
				$active,
				static function ( $plugin ) use ( $self ) {
					return $plugin !== $self;
				}
			)
		);
		$pending = array_values( array_unique( array_merge( $this->pending_plugins(), $targets, array( $self ) ) ) );
		update_option( self::PLUGINS_OPTION, $pending );
		if ( array() !== $targets ) {
			deactivate_plugins( $targets, true );
		}
	}
	public function create_network( bool $subdomain_install ): void {
		if ( $this->network_tables_exist() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		require_once ABSPATH . 'wp-admin/includes/schema.php';
		global $wpdb;
		foreach ( $wpdb->tables( 'ms_global' ) as $table => $prefixed_table ) {
			$wpdb->$table = $prefixed_table;
		}
		install_network();
		$result = populate_network(
			1,
			$this->domain(),
			(string) get_option( 'admin_email' ),
			$this->network_title(),
			$this->base_path(),
			$subdomain_install
		);
		if ( is_wp_error( $result ) && 'no_wildcard_dns' !== $result->get_error_code() ) {
			throw new \RuntimeException( $result->get_error_message() );
		}
	}
	public function activate_recorded_plugins(): void {
		$pending = $this->pending_plugins();
		if ( array() === $pending ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( $pending as $plugin ) {
			if ( is_plugin_active_for_network( $plugin ) ) {
				continue;
			}
			activate_plugin( $plugin, '', true, false );
		}
		$self = plugin_basename( GACHIREAI_PLUGIN_FILE );
		if ( is_plugin_active_for_network( $self ) && get_current_blog_id() === get_main_site_id() ) {
			$site_active = array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( $self ) ) );
			update_option( 'active_plugins', $site_active );
		}
		delete_option( self::PLUGINS_OPTION );
	}
	public static function maybe_network_activate_recorded_plugins(): void {
		if ( ! is_multisite() || ! current_user_can( 'manage_network' ) ) {
			return;
		}
		( new self() )->activate_recorded_plugins();
	}
	public function restore_deactivated_plugins(): void {
		$pending = $this->pending_plugins();
		if ( array() === $pending ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		activate_plugins( $pending, '', is_multisite(), true );
		delete_option( self::PLUGINS_OPTION );
	}
	private function pending_plugins(): array {
		$pending = get_option( self::PLUGINS_OPTION, array() );
		if ( ! is_array( $pending ) ) {
			return array();
		}
		return array_values( array_filter( $pending, 'is_string' ) );
	}
	private function network_title(): string {
		$blogname = (string) get_option( 'blogname' );
		return '' !== $blogname ? $blogname : $this->domain();
	}
	private function write_htaccess(): void {
		$path     = $this->htaccess_path();
		$original = $this->read( $path );
		$lines    = $this->htaccess_rules();
		if ( null === $original ) {
			throw new \RuntimeException( __( '.htaccess を読み込めませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$begin = '# BEGIN WordPress';
		$end   = '# END WordPress';
		$block = $begin . "\n" . implode( "\n", $lines ) . "\n" . $end;
		$pattern = '/' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '/s';
		if ( 1 === preg_match( $pattern, $original ) ) {
			$new = (string) preg_replace( $pattern, $block, $original );
		} else {
			$new = ( '' === trim( $original ) ? '' : rtrim( $original ) . "\n\n" ) . $block . "\n";
		}
		$this->write_safely( $path, $new, false );
	}
	private function htaccess_rules(): array {
		$subdomain      = $this->subdomain_install();
		$base           = $this->base_path();
		$subdir_match   = $subdomain ? '' : '([_0-9a-zA-Z-]+/)?';
		$replacement_01 = $subdomain ? '' : '$1';
		$replacement_12 = $subdomain ? '$1' : '$2';
		$rewrite_base   = $this->rewrite_base();
		return array(
			'RewriteEngine On',
			'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
			'RewriteBase ' . $base,
			'RewriteRule ^index\\.php$ - [L]',
			'',
			'# add a trailing slash to /wp-admin',
			'RewriteRule ^' . $subdir_match . 'wp-admin$ ' . $replacement_01 . 'wp-admin/ [R=301,L]',
			'',
			'RewriteCond %{REQUEST_FILENAME} -f [OR]',
			'RewriteCond %{REQUEST_FILENAME} -d',
			'RewriteRule ^ - [L]',
			'RewriteRule ^' . $subdir_match . '(wp-(content|admin|includes).*) ' . $rewrite_base . $replacement_12 . ' [L]',
			'RewriteRule ^' . $subdir_match . '(.*\\.php)$ ' . $rewrite_base . $replacement_12 . ' [L]',
			'RewriteRule . index.php [L]',
		);
	}
	private function rewrite_base(): string {
		$doc_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '';
		if ( '' === $doc_root ) {
			return '';
		}
		$document_root = str_replace( '\\', '/', (string) realpath( $doc_root ) );
		$abspath       = str_replace( '\\', '/', ABSPATH );
		if ( '' === $document_root || ! str_starts_with( $abspath, $document_root ) ) {
			return '';
		}
		$home      = (string) get_option( 'home' );
		$base      = wp_parse_url( trailingslashit( $home ), PHP_URL_PATH );
		$base      = is_string( $base ) ? $base : '/';
		$home_path = $document_root . $base;
		$subdir = (string) preg_replace( '#^' . preg_quote( $home_path, '#' ) . '#', '', $abspath );
		return '' !== $subdir ? ltrim( trailingslashit( $subdir ), '/' ) : '';
	}
	private function constants_block(): string {
		return "/* Multisite（gachi-review-ai により追加） */\n"
			. "define( 'MULTISITE', true );\n"
			. "define( 'SUBDOMAIN_INSTALL', " . ( $this->subdomain_install() ? 'true' : 'false' ) . " );\n"
			. "define( 'DOMAIN_CURRENT_SITE', " . var_export( $this->domain(), true ) . " );\n"
			. "define( 'PATH_CURRENT_SITE', " . var_export( $this->base_path(), true ) . " );\n"
			. "define( 'SITE_ID_CURRENT_SITE', 1 );\n"
			. "define( 'BLOG_ID_CURRENT_SITE', 1 );\n";
	}
	private function insert_before_marker( string $content, string $block ): ?string {
		$pos = $this->marker_position( $content );
		if ( null === $pos ) {
			return null;
		}
		return substr( $content, 0, $pos ) . $block . "\n" . substr( $content, $pos );
	}
	private function marker_position( string $content ): ?int {
		foreach ( self::WP_CONFIG_MARKERS as $marker ) {
			$pos = strpos( $content, $marker );
			if ( false !== $pos ) {
				return $pos;
			}
		}
		return null;
	}
	private function contains_definition( string $path, string $constant ): bool {
		$content = $this->read( $path );
		if ( null === $content ) {
			return false;
		}
		$pattern = '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]/';
		return 1 === preg_match( $pattern, $content );
	}
	private function write_safely( string $path, string $new_content, bool $validate_php ): void {
		if ( ! $this->is_writable( $path ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'ファイルに書き込めません: %s', GACHIREAI_TEXT_DOMAIN ),
					$path
				)
			);
		}
		$exists   = file_exists( $path );
		$original = $exists ? (string) @file_get_contents( $path ) : '';
		$tmp      = $path . '.gachireai-tmp-' . wp_generate_password( 12, false );
		if ( false === @file_put_contents( $tmp, $new_content, LOCK_EX ) ) {
			throw new \RuntimeException( __( '一時ファイルを作成できませんでした。', GACHIREAI_TEXT_DOMAIN ) );
		}
		if ( $validate_php && ! $this->validate_php( $tmp ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( __( '構文の検証に失敗したため、書き込みを中止しました（元のファイルは変更していません）。', GACHIREAI_TEXT_DOMAIN ) );
		}
		$backup = $exists ? $this->backup( $path, $original ) : '';
		$ok     = @copy( $tmp, $path );
		if ( ! $ok ) {
			if ( '' !== $backup ) {
				@copy( $backup, $path );
			} else {
				@unlink( $path );
			}
		}
		@unlink( $tmp );
		if ( '' !== $backup ) {
			@unlink( $backup );
		}
		if ( ! $ok ) {
			throw new \RuntimeException( __( 'ファイルの書き込みに失敗しました。元に戻しました。', GACHIREAI_TEXT_DOMAIN ) );
		}
	}
	private function backup( string $path, string $content ): string {
		$is_php = '.php' === strtolower( substr( $path, -4 ) );
		$backup = $path . self::BACKUP_SUFFIX . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false ) . ( $is_php ? '.php' : '' );
		if ( false === @file_put_contents( $backup, $content, LOCK_EX ) ) {
			throw new \RuntimeException(
				sprintf(
					__( 'バックアップを作成できませんでした: %s', GACHIREAI_TEXT_DOMAIN ),
					$backup
				)
			);
		}
		return $backup;
	}
	private function validate_php( string $file ): bool {
		$binary = $this->cli_php_binary();
		if ( '' === $binary || ! function_exists( 'exec' ) ) {
			return true;
		}
		$output = array();
		$code   = 0;
		@exec( escapeshellarg( $binary ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $code ); 
		$text = implode( "\n", $output );
		if ( false !== stripos( $text, 'No syntax errors' ) ) {
			return true;
		}
		if ( false !== stripos( $text, 'Parse error' ) || false !== stripos( $text, 'syntax error' ) ) {
			return false;
		}
		return true;
	}
	private function cli_php_binary(): string {
		$candidates = array();
		if ( defined( 'PHP_BINARY' ) && '' !== (string) PHP_BINARY ) {
			$candidates[] = (string) PHP_BINARY;
		}
		$candidates[] = 'php';
		foreach ( $candidates as $candidate ) {
			$base = strtolower( basename( $candidate ) );
			if ( in_array( $base, array( 'php', 'php.exe' ), true ) || 1 === preg_match( '/^php[0-9.]*$/', $base ) ) {
				return $candidate;
			}
		}
		return '';
	}
	private function network_tables_exist(): bool {
		global $wpdb;
		$table = $wpdb->base_prefix . 'site';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return ! empty( $found );
	}
	private function is_writable( string $path ): bool {
		if ( file_exists( $path ) ) {
			return is_writable( $path );
		}
		return is_writable( dirname( $path ) );
	}
	private function read( string $path ): ?string {
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$content = @file_get_contents( $path );
		return false === $content ? null : $content;
	}
}

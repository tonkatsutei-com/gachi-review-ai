<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Ec;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Rakuten_Client;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Yahoo_Client;
use Tonkatsutei\Gachi_Review_AI\Settings;
final class Genre_Catalog {
	public const STATUS_OK = 'ok';
	public const STATUS_NO_HIT = 'no_hit';
	public const STATUS_API_ERROR = 'api_error';
	public const STATUS_MISSING_CONFIG = 'missing_config';
	public const STATUS_UNSUPPORTED = 'unsupported_ec';
	private const TTL_DAYS = 30;
	private const MAX_PATH = 20;
	private const NAME_MAX = 191;
	private const ID_MAX = 32;
	private const TERMS = array(
		Product_Selector::EC_RAKUTEN => array(
			'singular' => 'ジャンル',
			'plural'   => 'ジャンル',
		),
		Product_Selector::EC_YAHOO   => array(
			'singular' => 'カテゴリー',
			'plural'   => 'カテゴリー',
		),
		Product_Selector::EC_AMAZON  => array(
			'singular' => 'カテゴリー',
			'plural'   => 'カテゴリー',
		),
	);
	private const ROOTS = array(
		Product_Selector::EC_RAKUTEN => Rakuten_Client::GENRE_ROOT,
		Product_Selector::EC_YAHOO   => Yahoo_Client::CATEGORY_ROOT,
		Product_Selector::EC_AMAZON  => '',
	);
	private Settings $settings;
	private Rakuten_Client $rakuten;
	private Yahoo_Client $yahoo;
	private Product_Selector $selector;
	public function __construct( ?Settings $settings = null, ?Rakuten_Client $rakuten = null, ?Yahoo_Client $yahoo = null, ?Product_Selector $selector = null ) {
		$this->settings = $settings ?? new Settings();
		$this->rakuten  = $rakuten ?? new Rakuten_Client();
		$this->yahoo    = $yahoo ?? new Yahoo_Client();
		$this->selector = $selector ?? new Product_Selector( $this->settings, $this->rakuten, $this->yahoo );
	}
	public function ec(): string {
		return $this->selector->ec();
	}
	public function term( string $form = 'singular' ): string {
		$terms = self::terms( $this->ec() );
		return 'plural' === $form ? $terms['plural'] : $terms['singular'];
	}
	public static function terms( string $ec ): array {
		$terms = self::TERMS[ Product_Selector::normalize_ec( $ec ) ] ?? array();
		return array(
			'singular' => (string) ( $terms['singular'] ?? '' ),
			'plural'   => (string) ( $terms['plural'] ?? '' ),
		);
	}
	public static function root_id( string $ec ): string {
		return (string) ( self::ROOTS[ Product_Selector::normalize_ec( $ec ) ] ?? '' );
	}
	public static function parse_entry( string $text ): array {
		$text = trim( $text );
		if ( preg_match( '/^\(\s*(?:ID\s*:\s*)?([0-9]{1,' . self::ID_MAX . '})\s*\)\s*(.*)$/ui', $text, $matches ) ) {
			return array(
				'genre_id' => (string) $matches[1],
				'name'     => mb_substr( trim( (string) $matches[2] ), 0, self::NAME_MAX ),
			);
		}
		return array(
			'genre_id' => '',
			'name'     => mb_substr( $text, 0, self::NAME_MAX ),
		);
	}
	public static function entries( string $text ): array {
		$lines = preg_split( '/[\r\n]+/u', $text );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		$entries = array();
		$seen    = array();
		foreach ( $lines as $line ) {
			foreach ( self::split_line( (string) $line ) as $part ) {
				$entry = self::parse_entry( $part );
				if ( '' === $entry['genre_id'] && '' === $entry['name'] ) {
					continue;
				}
				$key = '' !== $entry['genre_id'] ? 'id:' . $entry['genre_id'] : 'name:' . $entry['name'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$entries[]    = $entry;
			}
		}
		return $entries;
	}
	private static function split_line( string $line ): array {
		$fragments = preg_split( '/([,\x{FF0C}\x{3001}]+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $fragments ) ) {
			return array();
		}
		$parts   = array();
		$pending = '';
		foreach ( $fragments as $fragment ) {
			if ( preg_match( '/^[,\x{FF0C}\x{3001}]+$/u', $fragment ) ) {
				$pending = $fragment;
				continue;
			}
			$last = array() !== $parts ? (string) $parts[ count( $parts ) - 1 ] : '';
			if ( '' !== $pending && self::has_id_prefix( $last ) && ! self::has_id_prefix( $fragment ) ) {
				$parts[ count( $parts ) - 1 ] = $last . $pending . $fragment;
			} else {
				$parts[] = $fragment;
			}
			$pending = '';
		}
		return $parts;
	}
	private static function has_id_prefix( string $text ): bool {
		return 1 === preg_match( '/^\s*\(\s*(?:ID\s*:\s*)?[0-9]{1,' . self::ID_MAX . '}\s*\)/ui', $text );
	}
	public static function format_entry( string $genre_id, string $name ): string {
		$genre_id = mb_substr( (string) preg_replace( '/[^0-9]/', '', $genre_id ), 0, self::ID_MAX );
		$name     = mb_substr( (string) preg_replace( '/[\r\n\t]+/u', ' ', trim( $name ) ), 0, self::NAME_MAX );
		return '' !== $genre_id ? '(ID:' . $genre_id . ')' . $name : $name;
	}
	public static function append_entry( string $text, string $genre_id, string $name ): string {
		$genre_id = mb_substr( (string) preg_replace( '/[^0-9]/', '', $genre_id ), 0, self::ID_MAX );
		$entry    = self::format_entry( $genre_id, $name );
		if ( '' === $entry ) {
			return $text;
		}
		$lines    = array();
		$replaced = false;
		foreach ( self::entries( $text ) as $existing ) {
			if ( '' !== $genre_id && $existing['genre_id'] === $genre_id ) {
				$lines[]  = $entry;
				$replaced = true;
				continue;
			}
			if ( '' === $genre_id && '' === $existing['genre_id'] && $existing['name'] === mb_substr( trim( $name ), 0, self::NAME_MAX ) ) {
				$replaced = true;
			}
			$lines[] = self::format_entry( $existing['genre_id'], $existing['name'] );
		}
		if ( ! $replaced ) {
			$lines[] = $entry;
		}
		$lines = array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );
		return implode( "\n", $lines );
	}
	public function known( string $ec, int $limit = 0 ): array {
		global $wpdb;
		$ec = Product_Selector::normalize_ec( $ec );
		if ( Product_Selector::EC_AMAZON === $ec ) {
			$nodes = self::amazon_nodes();
			return $limit > 0 ? array_slice( $nodes, 0, $limit ) : $nodes;
		}
		$sql    = 'SELECT genre_id, name, level FROM ' . Activator::table( 'genres' ) . ' WHERE ec = %s AND level >= 1 ORDER BY level ASC, name ASC';
		$params = array( $ec );
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = $limit;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$nodes = array();
		foreach ( (array) $rows as $row ) {
			$nodes[] = array(
				'genre_id' => (string) ( $row['genre_id'] ?? '' ),
				'name'     => (string) ( $row['name'] ?? '' ),
				'level'    => (int) ( $row['level'] ?? 0 ),
			);
		}
		return $nodes;
	}
	public function at_level( string $ec, int $level, int $budget = 0 ): array {
		global $wpdb;
		$ec = Product_Selector::normalize_ec( $ec );
		$this->ensure_level( $ec, $level, $budget );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT genre_id, name, level FROM ' . Activator::table( 'genres' ) . ' WHERE ec = %s AND level = %d AND excluded = 0 ORDER BY name ASC',
				$ec,
				$level
			),
			ARRAY_A
		);
		$nodes = array();
		foreach ( (array) $rows as $row ) {
			$nodes[] = array(
				'genre_id' => (string) ( $row['genre_id'] ?? '' ),
				'name'     => (string) ( $row['name'] ?? '' ),
				'level'    => (int) ( $row['level'] ?? 0 ),
			);
		}
		return $nodes;
	}
	public function exclude( string $ec, string $genre_id ): void {
		global $wpdb;
		$ec       = Product_Selector::normalize_ec( $ec );
		$genre_id = $this->clip( $this->resolve_id( $ec, $genre_id ), self::ID_MAX );
		if ( '' === $genre_id ) {
			return;
		}
		$sql = 'UPDATE ' . Activator::table( 'genres' ) . ' SET excluded = 1 WHERE ec = %s AND genre_id = %s';
		$wpdb->query( $wpdb->prepare( $sql, $ec, $genre_id ) );
	}
	private function ensure_level( string $ec, int $level, int $budget = 0 ): void {
		if ( Product_Selector::EC_AMAZON === $ec || $level < 1 ) {
			return;
		}
		$frontier = array( self::root_id( $ec ) );
		$calls    = 0;
		for ( $depth = 0; $depth < $level && array() !== $frontier; $depth++ ) {
			$next = array();
			foreach ( $frontier as $parent_id ) {
				if ( $this->is_stale( $ec, $parent_id ) ) {
					if ( $budget > 0 && $calls >= $budget ) {
						return;
					}
					$this->sync( $ec, $parent_id );
					$calls++;
				}
				foreach ( $this->children( $ec, $parent_id ) as $child ) {
					$child_id = (string) ( $child['genre_id'] ?? '' );
					if ( '' !== $child_id ) {
						$next[] = $child_id;
					}
				}
			}
			$frontier = $next;
		}
	}
	public function supports( string $ec ): bool {
		return true;
	}
	public function children( string $ec, string $parent_id ): array {
		global $wpdb;
		$ec  = Product_Selector::normalize_ec( $ec );
		$nav = $this->nav_parent( $ec, $parent_id );
		if ( Product_Selector::EC_AMAZON === $ec ) {
			return self::amazon_children( $nav );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT genre_id, name, level FROM ' . Activator::table( 'genres' ) . ' WHERE ec = %s AND parent_id = %s AND level >= 1 ORDER BY level ASC, name ASC',
				$ec,
				$nav
			),
			ARRAY_A
		);
		$children = array();
		foreach ( (array) $rows as $row ) {
			$children[] = array(
				'genre_id' => (string) ( $row['genre_id'] ?? '' ),
				'name'     => (string) ( $row['name'] ?? '' ),
				'level'    => (int) ( $row['level'] ?? 0 ),
			);
		}
		return $children;
	}
	public function find( string $ec, string $genre_id ): array {
		$ec = Product_Selector::normalize_ec( $ec );
		if ( Product_Selector::EC_AMAZON === $ec ) {
			return self::amazon_find( $genre_id );
		}
		global $wpdb;
		$genre_id = $this->clip( $this->resolve_id( $ec, $genre_id ), self::ID_MAX );
		if ( '' === $genre_id ) {
			return array();
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ec, parent_id, genre_id, name, level, fetched_at FROM ' . Activator::table( 'genres' ) . ' WHERE ec = %s AND genre_id = %s LIMIT 1',
				$ec,
				$genre_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array();
		}
		return array(
			'ec'         => (string) ( $row['ec'] ?? '' ),
			'parent_id'  => (string) ( $row['parent_id'] ?? '' ),
			'genre_id'   => (string) ( $row['genre_id'] ?? '' ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'level'      => (int) ( $row['level'] ?? 0 ),
			'fetched_at' => (string) ( $row['fetched_at'] ?? '' ),
		);
	}
	public function foreign_entries( string $ec, string $text ): array {
		$foreign = array();
		foreach ( self::entries( $text ) as $entry ) {
			$genre_id = (string) ( $entry['genre_id'] ?? '' );
			if ( '' === $genre_id ) {
				continue;
			}
			if ( array() === $this->find( $ec, $genre_id ) ) {
				$foreign[] = $entry;
			}
		}
		return $foreign;
	}
	public function remove_foreign( string $ec, string $text ): string {
		$lines = array();
		foreach ( self::entries( $text ) as $entry ) {
			$genre_id = (string) ( $entry['genre_id'] ?? '' );
			if ( '' !== $genre_id && array() === $this->find( $ec, $genre_id ) ) {
				continue;
			}
			$line = self::format_entry( $genre_id, (string) ( $entry['name'] ?? '' ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}
	public function path( string $ec, string $genre_id ): array {
		$ec    = Product_Selector::normalize_ec( $ec );
		$node  = $this->find( $ec, $genre_id );
		$chain = array();
		$seen  = array();
		$hops  = 0;
		while ( array() !== $node && $hops < self::MAX_PATH ) {
			array_unshift( $chain, $node );
			$seen[ (string) $node['genre_id'] ] = true;
			$parent = (string) $node['parent_id'];
			if ( '' === $parent || isset( $seen[ $parent ] ) ) {
				break;
			}
			$node = $this->find( $ec, $parent );
			++$hops;
		}
		$root = $this->find( $ec, self::root_id( $ec ) );
		if ( array() !== $root && ! isset( $seen[ (string) $root['genre_id'] ] ) ) {
			array_unshift( $chain, $root );
		}
		return $chain;
	}
	public function is_stale( string $ec, string $parent_id ): bool {
		$ec  = Product_Selector::normalize_ec( $ec );
		$row = $this->find( $ec, $parent_id );
		if ( array() === $row ) {
			return true;
		}
		$fetched = (string) ( $row['fetched_at'] ?? '' );
		if ( '' === $fetched ) {
			return true;
		}
		return $fetched < $this->expiry();
	}
	public function sync( string $ec, string $parent_id, bool $force = false ): array {
		$ec = Product_Selector::normalize_ec( $ec );
		if ( Product_Selector::EC_AMAZON === $ec ) {
			$children = $this->children( $ec, $parent_id );
			return array(
				'status'   => array() === $children ? self::STATUS_NO_HIT : self::STATUS_OK,
				'children' => $children,
				'fetched'  => false,
				'message'  => '',
			);
		}
		if ( ! $this->supports( $ec ) ) {
			return array(
				'status'   => self::STATUS_UNSUPPORTED,
				'children' => array(),
				'fetched'  => false,
				'message'  => __( 'Amazon のカテゴリー取得は準備中です。', GACHIREAI_TEXT_DOMAIN ),
			);
		}
		$parent_id = $this->resolve_id( $ec, $parent_id );
		$fetched   = false;
		if ( $force || $this->is_stale( $ec, $parent_id ) ) {
			$result = $this->fetch( $ec, $parent_id );
			if ( ! in_array( (string) $result['status'], array( self::STATUS_OK, self::STATUS_NO_HIT ), true ) ) {
				return array(
					'status'   => (string) $result['status'],
					'children' => $this->children( $ec, $parent_id ),
					'fetched'  => false,
					'message'  => (string) $result['message'],
				);
			}
			$this->save_nodes( $ec, $parent_id, (array) $result['current'], (array) $result['children'] );
			$fetched = true;
		}
		$children = $this->children( $ec, $parent_id );
		return array(
			'status'   => array() === $children ? self::STATUS_NO_HIT : self::STATUS_OK,
			'children' => $children,
			'fetched'  => $fetched,
			'message'  => '',
		);
	}
	public function save_nodes( string $ec, string $parent_id, array $current, array $children ): int {
		$ec        = Product_Selector::normalize_ec( $ec );
		$parent_id = $this->clip( $this->resolve_id( $ec, $parent_id ), self::ID_MAX );
		$nav       = $this->nav_parent( $ec, $parent_id );
		$is_root   = ( $parent_id === self::root_id( $ec ) );
		$now       = $this->now();
		$existing  = $this->find( $ec, $parent_id );
		$row_parent = (string) ( $existing['parent_id'] ?? '' );
		if ( '' === $row_parent && ! $is_root ) {
			$row_parent = (string) ( $current['parent_id'] ?? '' );
		}
		$row_parent = $this->nav_parent( $ec, $row_parent );
		if ( $is_root || $row_parent === $parent_id ) {
			$row_parent = '';
		}
		$name  = '' !== (string) ( $current['name'] ?? '' ) ? (string) $current['name'] : (string) ( $existing['name'] ?? '' );
		$level = $is_root ? 0 : (int) ( $current['level'] ?? ( $existing['level'] ?? 0 ) );
		$this->upsert(
			array(
				'ec'         => $ec,
				'parent_id'  => $row_parent,
				'genre_id'   => $parent_id,
				'name'       => $this->clip( $name, self::NAME_MAX ),
				'level'      => max( 0, $level ),
				'fetched_at' => $now,
			)
		);
		$keep = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$genre_id = $this->clip( (string) ( $child['genre_id'] ?? '' ), self::ID_MAX );
			if ( '' === $genre_id || $genre_id === $parent_id ) {
				continue;
			}
			$keep[] = $genre_id;
			$this->upsert(
				array(
					'ec'        => $ec,
					'parent_id' => $nav,
					'genre_id'  => $genre_id,
					'name'      => $this->clip( (string) ( $child['name'] ?? '' ), self::NAME_MAX ),
					'level'     => max( 1, (int) ( $child['level'] ?? ( $level + 1 ) ) ),
				),
				true
			);
		}
		$this->delete_missing( $ec, $nav, $keep );
		return count( $keep );
	}
	public function pending( string $ec, int $limit = 0 ): array {
		global $wpdb;
		$ec = Product_Selector::normalize_ec( $ec );
		if ( ! $this->supports( $ec ) ) {
			return array();
		}
		$sql = 'SELECT genre_id, name, level FROM ' . Activator::table( 'genres' )
			. ' WHERE ec = %s AND ( fetched_at IS NULL OR fetched_at < %s )'
			. ' ORDER BY ( fetched_at IS NULL ) DESC, fetched_at ASC, level ASC, genre_id ASC';
		$params = array( $ec, $this->expiry() );
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = $limit;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$nodes = array();
		foreach ( (array) $rows as $row ) {
			$nodes[] = array(
				'genre_id' => (string) ( $row['genre_id'] ?? '' ),
				'name'     => (string) ( $row['name'] ?? '' ),
				'level'    => (int) ( $row['level'] ?? 0 ),
			);
		}
		$root = self::root_id( $ec );
		if ( ! $this->exists( $ec, $root ) ) {
			array_unshift(
				$nodes,
				array(
					'genre_id' => $root,
					'name'     => '',
					'level'    => 0,
				)
			);
			if ( $limit > 0 && count( $nodes ) > $limit ) {
				$nodes = array_slice( $nodes, 0, $limit );
			}
		}
		return $nodes;
	}
	public function grow( string $ec, int $budget = 1 ): array {
		$ec         = Product_Selector::normalize_ec( $ec );
		$budget     = max( 1, $budget );
		$candidates = $this->pending( $ec, $budget );
		$summary    = array(
			'status'  => self::STATUS_OK,
			'fetched' => 0,
			'failed'  => 0,
			'pending' => count( $candidates ),
			'message' => '',
		);
		foreach ( $candidates as $node ) {
			$synced = $this->sync( $ec, (string) $node['genre_id'] );
			if ( true === (bool) $synced['fetched'] ) {
				++$summary['fetched'];
				continue;
			}
			++$summary['failed'];
			$summary['status']  = (string) $synced['status'];
			$summary['message'] = (string) $synced['message'];
			break;
		}
		return $summary;
	}
	private function fetch( string $ec, string $parent_id ): array {
		if ( Product_Selector::EC_YAHOO === $ec ) {
			return $this->yahoo->categories( (array) $this->settings->get( 'yahoo', array() ), $parent_id );
		}
		return $this->rakuten->genres( (array) $this->settings->get( 'rakuten', array() ), $parent_id );
	}
	private function nav_parent( string $ec, string $parent_id ): string {
		$parent_id = trim( $parent_id );
		return ( '' === $parent_id || $parent_id === self::root_id( $ec ) ) ? '' : $parent_id;
	}
	private function resolve_id( string $ec, string $parent_id ): string {
		$parent_id = trim( $parent_id );
		return '' === $parent_id ? self::root_id( $ec ) : $parent_id;
	}
	private function exists( string $ec, string $genre_id ): bool {
		return array() !== $this->find( $ec, $genre_id );
	}
	private function upsert( array $row, bool $keep_fetched_at = false ): void {
		global $wpdb;
		$table  = Activator::table( 'genres' );
		$params = array(
			(string) $row['ec'],
			(string) $row['parent_id'],
			(string) $row['genre_id'],
			(string) $row['name'],
			(int) $row['level'],
		);
		if ( $keep_fetched_at ) {
			$sql = "INSERT INTO {$table} (ec, parent_id, genre_id, name, level, excluded, fetched_at)
				VALUES (%s, %s, %s, %s, %d, 0, NULL)
				ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), name = VALUES(name), level = VALUES(level), excluded = 0";
		} else {
			$sql      = "INSERT INTO {$table} (ec, parent_id, genre_id, name, level, excluded, fetched_at)
				VALUES (%s, %s, %s, %s, %d, 0, %s)
				ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), name = VALUES(name), level = VALUES(level), excluded = 0, fetched_at = VALUES(fetched_at)";
			$params[] = (string) ( $row['fetched_at'] ?? '' );
		}
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
	}
	private function delete_missing( string $ec, string $nav, array $keep ): void {
		global $wpdb;
		$sql = 'DELETE FROM ' . Activator::table( 'genres' ) . ' WHERE ec = %s AND parent_id = %s AND level >= 1';
		if ( array() !== $keep ) {
			$sql .= ' AND genre_id NOT IN (' . implode( ',', array_fill( 0, count( $keep ), '%s' ) ) . ')';
		}
		$wpdb->query( $wpdb->prepare( $sql, array_merge( array( $ec, $nav ), $keep ) ) );
	}
	private static function amazon_find( string $genre_id ): array {
		$genre_id = trim( $genre_id );
		if ( '' === $genre_id ) {
			return array(
				'ec'         => Product_Selector::EC_AMAZON,
				'parent_id'  => '',
				'genre_id'   => '',
				'name'       => '',
				'level'      => 0,
				'fetched_at' => '',
			);
		}
		foreach ( self::amazon_tree() as $node ) {
			if ( $node['genre_id'] === $genre_id ) {
				return array(
					'ec'         => Product_Selector::EC_AMAZON,
					'parent_id'  => $node['parent_id'],
					'genre_id'   => $node['genre_id'],
					'name'       => $node['name'],
					'level'      => $node['level'],
					'fetched_at' => '',
				);
			}
		}
		return array();
	}
	private static function amazon_tree(): array {
		static $nodes = null;
		if ( is_array( $nodes ) ) {
			return $nodes;
		}
		$nodes = array();
		$path  = GACHIREAI_PLUGIN_DIR . 'includes/data/amazon-categories.json';
		if ( ! is_readable( $path ) ) {
			return $nodes;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return $nodes;
		}
		foreach ( $data as $top ) {
			if ( ! is_array( $top ) ) {
				continue;
			}
			$id   = trim( (string) ( $top['node'] ?? '' ) );
			$name = trim( (string) ( $top['name'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$nodes[] = array(
				'genre_id'  => $id,
				'name'      => $name,
				'parent_id' => '',
				'level'     => 1,
			);
			foreach ( (array) ( $top['children'] ?? array() ) as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				$child_id   = trim( (string) ( $child['node'] ?? '' ) );
				$child_name = trim( (string) ( $child['name'] ?? '' ) );
				if ( '' === $child_id ) {
					continue;
				}
				$nodes[] = array(
					'genre_id'  => $child_id,
					'name'      => $child_name,
					'parent_id' => $id,
					'level'     => 2,
				);
			}
		}
		return $nodes;
	}
	private static function amazon_nodes(): array {
		$nodes = array();
		$seen  = array();
		foreach ( self::amazon_tree() as $node ) {
			if ( isset( $seen[ $node['genre_id'] ] ) ) {
				continue;
			}
			$seen[ $node['genre_id'] ] = true;
			$nodes[] = array(
				'genre_id' => $node['genre_id'],
				'name'     => $node['name'],
				'level'    => $node['level'],
			);
		}
		return $nodes;
	}
	private static function amazon_children( string $parent_id ): array {
		$parent_id = trim( $parent_id );
		$children  = array();
		foreach ( self::amazon_tree() as $node ) {
			if ( $node['parent_id'] === $parent_id ) {
				$children[] = array(
					'genre_id' => $node['genre_id'],
					'name'     => $node['name'],
					'level'    => $node['level'],
				);
			}
		}
		return $children;
	}
	private function now(): string {
		return current_datetime()->format( 'Y-m-d H:i:s' );
	}
	private function expiry(): string {
		return current_datetime()->modify( '-' . self::TTL_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
	}
	private function clip( string $value, int $max ): string {
		return mb_substr( trim( $value ), 0, $max );
	}
}

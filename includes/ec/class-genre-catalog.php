<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Ec;
use Tonkatsutei\Gachi_Review_AI\Activator;
use Tonkatsutei\Gachi_Review_AI\Affiliate\Amazon_Client;
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
	private ?Amazon_Category_Gateway $gateway = null;
	private Amazon_Client $amazon;
	public function __construct( ?Settings $settings = null, ?Rakuten_Client $rakuten = null, ?Yahoo_Client $yahoo = null, ?Product_Selector $selector = null, ?Amazon_Client $amazon = null ) {
		$this->settings = $settings ?? new Settings();
		$this->rakuten  = $rakuten ?? new Rakuten_Client();
		$this->yahoo    = $yahoo ?? new Yahoo_Client();
		$this->selector = $selector ?? new Product_Selector( $this->settings, $this->rakuten, $this->yahoo );
		$this->amazon   = $amazon ?? new Amazon_Client();
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
			$nodes = $this->amazon_nodes();
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
			return $this->amazon_children( $nav );
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
			return $this->amazon_find( $genre_id );
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
	private function amazon_find( string $genre_id ): array {
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
		foreach ( $this->amazon_tree() as $node ) {
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
		if ( Amazon_Client::BACKEND_CREATORS === $this->amazon_backend() ) {
			$settings = (array) $this->settings->get( 'amazon', array() );
			$raw      = $this->amazon->browse_nodes( $settings, array( $genre_id ), 'ancestor' );
			if ( array() !== $raw ) {
				$node = $raw[0];
				$raw_level   = 1;
				$wrapper_cnt = 0;
				$ancestor    = $node['ancestor'] ?? null;
				while ( is_array( $ancestor ) ) {
					$raw_level++;
					if ( self::amazon_is_wrapper( (string) ( $ancestor['displayName'] ?? '' ) ) ) {
						$wrapper_cnt++;
					}
					$ancestor = $ancestor['ancestor'] ?? null;
				}
				return array(
					'ec'         => Product_Selector::EC_AMAZON,
					'parent_id'  => (string) ( $node['ancestor']['id'] ?? '' ),
					'genre_id'   => (string) ( $node['id'] ?? $genre_id ),
					'name'       => (string) ( $node['displayName'] ?? '' ),
					'level'      => max( 1, $raw_level - $wrapper_cnt ),
					'fetched_at' => '',
				);
			}
			return array();
		}
		try {
			$resolved = $this->gateway()->resolve( $genre_id );
		} catch ( \RuntimeException $e ) {
			$resolved = array();
		}
		if ( array() !== $resolved ) {
			return array(
				'ec'         => Product_Selector::EC_AMAZON,
				'parent_id'  => (string) ( $resolved['parent_id'] ?? '' ),
				'genre_id'   => (string) ( $resolved['genre_id'] ?? '' ),
				'name'       => (string) ( $resolved['name'] ?? '' ),
				'level'      => (int) ( $resolved['level'] ?? 3 ),
				'fetched_at' => '',
			);
		}
		return array();
	}
	private function amazon_tree(): array {
		static $nodes = null;
		if ( is_array( $nodes ) ) {
			return $nodes;
		}
		$nodes = array();
		if ( Amazon_Client::BACKEND_CREATORS === $this->amazon_backend() ) {
			$nodes = $this->amazon_direct_tree();
		} else {
			try {
				$nodes = $this->gateway()->tree();
			} catch ( \RuntimeException $e ) {
				$nodes = array();
			}
		}
		return $nodes;
	}
	private function amazon_backend(): string {
		return Amazon_Client::backend( (array) $this->settings->get( 'amazon', array() ) );
	}
	public function depth_limit( string $ec ): int {
		if ( Product_Selector::EC_AMAZON !== Product_Selector::normalize_ec( $ec ) ) {
			return 0;
		}
		if ( Amazon_Client::BACKEND_CREATORS === $this->amazon_backend() ) {
			return 0;
		}
		return Amazon_Category_Gateway::LEVEL_MAX;
	}
	private const AMAZON_EXCLUDED_NAMES = array(
		'Arborist Merchandising Root',
		'Featured Categories',
		'Specialty Boutique',
		'Camera & Photo Features',
		'Household Appliances Features',
		'Alcohol Features',
		'Custom Stores',
		'Specialty Stores',
		'Self Service',
		'スペシャルフィーチャー',
		'Special',
	);
	private const AMAZON_WRAPPER_NAMES = array(
		'カテゴリー別',
		'ジャンル別',
		'機種別',
		'Subjects',
	);
	private const AMAZON_TREE_CACHE_KEY = 'gachireai_amazon_direct_tree_v3';
	private const AMAZON_KIDS_CACHE_PREFIX = 'gachireai_amazon_kids_v1_';
	private static function amazon_is_excluded( string $name ): bool {
		return in_array( trim( $name ), self::AMAZON_EXCLUDED_NAMES, true );
	}
	private static function amazon_is_wrapper( string $name ): bool {
		$name = trim( $name );
		if ( in_array( $name, self::AMAZON_WRAPPER_NAMES, true ) ) {
			return true;
		}
		return '別' === mb_substr( $name, -1 );
	}
	private function amazon_flatten_children( array $children ): array {
		$wrappers = array();
		foreach ( $children as $child ) {
			if ( is_array( $child ) && self::amazon_is_wrapper( (string) ( $child['displayName'] ?? '' ) ) ) {
				$wrappers[] = $child;
			}
		}
		$out = array();
		if ( array() !== $wrappers ) {
			$settings = (array) $this->settings->get( 'amazon', array() );
			foreach ( $wrappers as $wrapper ) {
				$wrapper_id = trim( (string) ( $wrapper['id'] ?? '' ) );
				if ( '' === $wrapper_id ) {
					continue;
				}
				foreach ( $this->amazon->browse_nodes( $settings, array( $wrapper_id ), 'children' ) as $node ) {
					$out = array_merge( $out, $this->amazon_flatten_children( (array) ( $node['children'] ?? array() ) ) );
				}
			}
			return $out;
		}
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$id   = trim( (string) ( $child['id'] ?? '' ) );
			$name = (string) ( $child['displayName'] ?? '' );
			if ( '' === $id || self::amazon_is_excluded( $name ) ) {
				continue;
			}
			$out[] = array( 'id' => $id, 'name' => $name );
		}
		return $out;
	}
	private function amazon_direct_tree(): array {
		$cached = get_transient( self::AMAZON_TREE_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$tree = array();
		try {
			foreach ( $this->gateway()->children( '', 1 ) as $level1 ) {
				$tree[] = array(
					'genre_id'  => (string) $level1['id'],
					'name'      => (string) $level1['name'],
					'parent_id' => '',
					'level'     => 1,
				);
			}
		} catch ( \RuntimeException $e ) {
			return array();
		}
		$root_ids = array_column( $tree, 'genre_id' );
		if ( array() === $root_ids ) {
			return $tree;
		}
		$settings = (array) $this->settings->get( 'amazon', array() );
		$raw = array();
		$ok  = true;
		foreach ( array_chunk( $root_ids, 10 ) as $batch ) {
			$nodes = $this->amazon->browse_nodes( $settings, $batch, 'children' );
			if ( array() === $nodes ) {
				$ok = false;
				continue;
			}
			foreach ( $nodes as $node ) {
				$rid = (string) ( $node['id'] ?? '' );
				if ( '' !== $rid ) {
					$raw[ $rid ] = (array) ( $node['children'] ?? array() );
				}
			}
		}
		$wrapper_ids = array();
		foreach ( $root_ids as $rid ) {
			foreach ( self::amazon_wrapper_ids( (array) ( $raw[ $rid ] ?? array() ) ) as $wid ) {
				$wrapper_ids[ $wid ] = true;
			}
		}
		$wrapper_raw = array();
		foreach ( array_chunk( array_keys( $wrapper_ids ), 10 ) as $batch ) {
			$nodes = $this->amazon->browse_nodes( $settings, $batch, 'children' );
			if ( array() === $nodes ) {
				$ok = false;
				continue;
			}
			foreach ( $nodes as $node ) {
				$wid = (string) ( $node['id'] ?? '' );
				if ( '' !== $wid ) {
					$wrapper_raw[ $wid ] = (array) ( $node['children'] ?? array() );
				}
			}
		}
		foreach ( $root_ids as $rid ) {
			$children = (array) ( $raw[ $rid ] ?? array() );
			$wids     = self::amazon_wrapper_ids( $children );
			if ( array() !== $wids ) {
				foreach ( $wids as $wid ) {
					foreach ( self::amazon_real_children( (array) ( $wrapper_raw[ $wid ] ?? array() ) ) as $child ) {
						$tree[] = array(
							'genre_id'  => $child['id'],
							'name'      => $child['name'],
							'parent_id' => $rid,
							'level'     => 2,
						);
					}
				}
			} else {
				foreach ( self::amazon_real_children( $children ) as $child ) {
					$tree[] = array(
						'genre_id'  => $child['id'],
						'name'      => $child['name'],
						'parent_id' => $rid,
						'level'     => 2,
					);
				}
			}
		}
		if ( $ok ) {
			set_transient( self::AMAZON_TREE_CACHE_KEY, $tree, 86400 );
		}
		return $tree;
	}
	private static function amazon_wrapper_ids( array $children ): array {
		$ids = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) || ! self::amazon_is_wrapper( (string) ( $child['displayName'] ?? '' ) ) ) {
				continue;
			}
			$id = trim( (string) ( $child['id'] ?? '' ) );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
	private static function amazon_real_children( array $children ): array {
		$out = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$id   = trim( (string) ( $child['id'] ?? '' ) );
			$name = (string) ( $child['displayName'] ?? '' );
			if ( '' === $id || self::amazon_is_excluded( $name ) ) {
				continue;
			}
			$out[] = array( 'id' => $id, 'name' => $name );
		}
		return $out;
	}
	private function amazon_nodes(): array {
		$nodes = array();
		$seen  = array();
		foreach ( $this->amazon_tree() as $node ) {
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
	private function amazon_children( string $parent_id ): array {
		$parent_id = trim( $parent_id );
		if ( '' === $parent_id ) {
			$children = array();
			foreach ( $this->amazon_tree() as $node ) {
				$children[] = array(
					'genre_id' => $node['genre_id'],
					'name'     => $node['name'],
					'level'    => (int) $node['level'],
				);
			}
			return $children;
		}
		if ( Amazon_Client::BACKEND_CREATORS === $this->amazon_backend() ) {
			$cached = get_transient( self::AMAZON_KIDS_CACHE_PREFIX . md5( $parent_id ) );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$level = 0;
		foreach ( $this->amazon_tree() as $node ) {
			if ( $node['genre_id'] === $parent_id ) {
				$level = (int) $node['level'];
				break;
			}
		}
		if ( 0 === $level ) {
			$found = $this->amazon_find( $parent_id );
			$level = (int) ( $found['level'] ?? 0 );
		}
		$child_level = max( 1, $level + 1 );
		if ( 1 === $level ) {
			$children = array();
			foreach ( $this->amazon_tree() as $node ) {
				if ( $node['parent_id'] === $parent_id ) {
					$children[] = array(
						'genre_id' => $node['genre_id'],
						'name'     => $node['name'],
						'level'    => (int) $node['level'],
					);
				}
			}
			return $children;
		}
		if ( Amazon_Client::BACKEND_CREATORS === $this->amazon_backend() ) {
			$settings = (array) $this->settings->get( 'amazon', array() );
			$children = array();
			foreach ( $this->amazon->browse_nodes( $settings, array( $parent_id ), 'children' ) as $node ) {
				foreach ( $this->amazon_flatten_children( (array) ( $node['children'] ?? array() ) ) as $child ) {
					$children[] = array( 'genre_id' => $child['id'], 'name' => $child['name'], 'level' => $child_level );
				}
			}
			if ( array() !== $children ) {
				set_transient( self::AMAZON_KIDS_CACHE_PREFIX . md5( $parent_id ), $children, 86400 );
			}
			return $children;
		}
		if ( $child_level > Amazon_Category_Gateway::LEVEL_MAX ) {
			return array();
		}
		try {
			$children = array();
			foreach ( $this->gateway()->children( $parent_id, $child_level ) as $child ) {
				$children[] = array(
					'genre_id' => (string) $child['id'],
					'name'     => (string) $child['name'],
					'level'    => $child_level,
				);
			}
			return $children;
		} catch ( \RuntimeException $e ) {
			return array();
		}
	}
	private function gateway(): Amazon_Category_Gateway {
		if ( null === $this->gateway ) {
			$this->gateway = new Amazon_Category_Gateway();
		}
		return $this->gateway;
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

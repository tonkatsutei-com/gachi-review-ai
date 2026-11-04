<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\SiteProfile;
final class Site_Profile {
	private const OPTION_KEY = 'gachireai_site_profile';
	public const DEFAULT_SITE_TYPE = 'category';
	public const VERSION_KEY = 'version';
	private array $data;
	public function __construct() {
		$saved       = get_option( self::OPTION_KEY, array() );
		$this->data  = is_array( $saved ) ? $saved : array();
	}
	public function get(): array {
		return $this->data;
	}
	public function save( array $data ): void {
		$before       = $this->content();
		$prev_version = $this->version();
		$this->data   = $data;
		$this->data[ self::VERSION_KEY ] = ( $before !== $this->content() )
			? $prev_version + 1
			: $prev_version;
		update_option( self::OPTION_KEY, $this->data );
	}
	public function get_field( string $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}
	public function update_field( string $key, $value ): void {
		if ( self::VERSION_KEY === $key ) {
			return;
		}
		$before             = $this->content();
		$this->data[ $key ] = $value;
		if ( $before !== $this->content() ) {
			$this->data[ self::VERSION_KEY ] = $this->version() + 1;
		}
		update_option( self::OPTION_KEY, $this->data );
	}
	public function version(): int {
		return (int) ( $this->data[ self::VERSION_KEY ] ?? 0 );
	}
	private function content(): array {
		$data = $this->data;
		unset( $data[ self::VERSION_KEY ] );
		return $data;
	}
	public function get_my_persona(): array {
		$value = $this->get_field( 'my_persona', array() );
		return is_array( $value ) ? $value : array();
	}
	public function get_target_persona(): array {
		$value = $this->get_field( 'target_persona', array() );
		return is_array( $value ) ? $value : array();
	}
	public function get_scope(): array {
		$value = $this->get_field( 'scope', array() );
		return is_array( $value ) ? $value : array();
	}
	public function get_article_styles(): array {
		$value = $this->get_field( 'article_styles', array() );
		return is_array( $value ) ? $value : array();
	}
	public function get_site_elements(): array {
		$value = $this->get_field( 'site_elements', array() );
		return is_array( $value ) ? $value : array();
	}
	public function get_site_images(): array {
		$value = $this->get_field( 'site_images', array() );
		return is_array( $value ) ? $value : array();
	}
}

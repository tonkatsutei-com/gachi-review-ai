<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Template;
final class Div_Renderer {
	public const EXTENSION = '.dv';
	private const TEMPLATE_DIR = 'templates';
	private static bool $include_path_registered = false;
	public function render( string $template, array $model = array() ): string {
		$path = $this->locate( $template );
		if ( null === $path ) {
			throw new \RuntimeException( sprintf( 'テンプレートが見つかりません: %s%s', $template, self::EXTENSION ) );
		}
		if ( ! gachireai_load_div() ) {
			throw new \RuntimeException( 'DIV（lib/div.php）を読み込めませんでした。lib/ が配置されているか確認してください。' );
		}
		$this->register_include_path();
		return \divengine\div::div( $path, $model );
	}
	public function exists( string $template ): bool {
		return null !== $this->locate( $template );
	}
	public function locate( string $template ): ?string {
		$relative = trim( str_replace( '\\', '/', $template ) );
		if (
			'' === $relative
			|| false !== strpos( $relative, "\0" )
			|| false !== strpos( $relative, '..' )
			|| str_starts_with( $relative, '/' )
			|| false !== strpos( $relative, ':' )
		) {
			return null;
		}
		$file = GACHIREAI_PLUGIN_DIR . self::TEMPLATE_DIR . '/' . $relative . self::EXTENSION;
		return is_readable( $file ) ? $file : null;
	}
	private function register_include_path(): void {
		if ( self::$include_path_registered ) {
			return;
		}
		\divengine\div::addIncludePath( GACHIREAI_PLUGIN_DIR . self::TEMPLATE_DIR );
		self::$include_path_registered = true;
	}
}

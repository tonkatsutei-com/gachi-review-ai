<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
final class Model_Catalog {
	public const OPENAI   = 'openai';
	public const DEEPSEEK = 'deepseek';
	public const GOOGLE   = 'google';
	public const GROQ     = 'groq';
	public const CEREBRAS = 'cerebras';
	private const CATALOG = array(
		self::OPENAI   => array(
			'label'  => 'OpenAI',
			'models' => array(
				'gpt-4o-mini',
				'gpt-5.6-luna',
				'gpt-5.6-terra',
				'gpt-5.6-sol',
				'gpt-5.6',
			),
		),
		self::DEEPSEEK => array(
			'label'  => 'DeepSeek',
			'models' => array(
				'deepseek-flash',
				'deepseek-v4-pro',
			),
		),
		self::GOOGLE   => array(
			'label'  => 'Google AI Studio',
			'models' => array(
				'gemini-3.8-flash',
				'gemini-3.6-flash',
				'gemini-3.5-flash-lite',
				'gemma-4-31b-it',
				'gemma-4-26b-a4b-it',
			),
		),
		self::GROQ     => array(
			'label'  => 'Groq',
			'models' => array(
				'openai/gpt-oss-120b',
				'openai/gpt-oss-20b',
				'llama-3.3-70b-versatile',
				'llama-3.1-8b-instant',
				'qwen/qwen3.8-27b',
			),
		),
		self::CEREBRAS => array(
			'label'  => 'Cerebras',
			'models' => array(
				'gpt-oss-120b',
				'qwen-3.8-27b',
			),
		),
	);
	public static function providers(): array {
		$providers = array();
		foreach ( self::CATALOG as $slug => $entry ) {
			$providers[ (string) $slug ] = (string) $entry['label'];
		}
		return $providers;
	}
	public static function has( string $provider ): bool {
		return isset( self::CATALOG[ $provider ] );
	}
	public static function label( string $provider ): string {
		return (string) ( self::CATALOG[ $provider ]['label'] ?? $provider );
	}
	public static function models( string $provider ): array {
		return array_values( (array) ( self::CATALOG[ $provider ]['models'] ?? array() ) );
	}
	public static function default_model( string $provider ): string {
		$models = self::models( $provider );
		return (string) ( $models[0] ?? '' );
	}
}

<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Image;
interface Provider {
	public function generate( string $prompt, array $opts = array() ): array;
	public function test(): array;
	public function model(): string;
	public function label(): string;
}

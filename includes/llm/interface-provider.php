<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
interface Provider {
	public function generate( string $system, string $user, array $opts = array() ): array;
	public function supports_json_mode(): bool;
}

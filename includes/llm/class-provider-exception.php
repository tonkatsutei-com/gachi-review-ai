<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Llm;
final class Provider_Exception extends \RuntimeException {
	private const RETRYABLE = array( 429, 500, 502, 503, 504 );
	private int $status;
	public function __construct( string $message, int $status = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->status = $status;
	}
	public function status(): int {
		return $this->status;
	}
	public function is_retryable(): bool {
		return in_array( $this->status, self::RETRYABLE, true );
	}
}

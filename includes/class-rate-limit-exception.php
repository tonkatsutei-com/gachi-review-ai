<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI;
class Rate_Limit_Exception extends \RuntimeException {
	private int $retry_after;
	public function __construct( int $retry_after = 60 ) {
		$this->retry_after = max( 1, $retry_after );
		parent::__construct(
			sprintf( __( 'データ取得のレート制限に達しました（%d 秒後に再試行）。', GACHIREAI_TEXT_DOMAIN ), $this->retry_after ),
			429
		);
	}
	public function get_retry_after(): int {
		return $this->retry_after;
	}
}

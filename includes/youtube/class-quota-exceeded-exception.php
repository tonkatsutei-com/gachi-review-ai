<?php
declare(strict_types=1);
namespace Tonkatsutei\Gachi_Review_AI\Youtube;
class Quota_Exceeded_Exception extends \RuntimeException {
	public function __construct( string $message = '' ) {
		parent::__construct(
			'' !== trim( $message )
				? $message
				: __( 'YouTube Data API の 1 日のクォータを使い切りました。時間をおいて（クォータは太平洋時間の午前 0 時にリセットされます）お試しください。', GACHIREAI_TEXT_DOMAIN ),
			403
		);
	}
}

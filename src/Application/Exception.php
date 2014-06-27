<?php

namespace slc\MVC;

class Application_Exception extends \Exception {
	protected $ExceptionData = null;
	protected $ExceptionMessage = null;
	public function __construct($ExceptionMessage, $ExceptionData) {
//    $ExceptionCode = sprintf('%05d%05d', crc32($ExceptionMessage), crc32(json_encode($ExceptionData)));
		parent::__construct($ExceptionMessage."\n".print_r($ExceptionData, true), crc32($ExceptionMessage));
		$this->ExceptionMessage = $ExceptionMessage;
		$this->ExceptionData = $ExceptionData;
	}
	public function getExceptionMessage() {
		return $this->ExceptionMessage;
	}
}

?>
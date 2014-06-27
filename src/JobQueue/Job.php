<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 17.10.13
 * Time: 15:23
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC\JobQueue;

abstract class Job extends Base {
	const INSTALL_PCNTL_HANDLERS = false;

	public function __construct($Facility) {
		parent::__construct($Facility);
		$this->Setup();
	}
	public function HandlePosixSignals($signal) {}

	abstract public function __sleep();
}

?>
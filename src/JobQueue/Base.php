<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 02.08.13
 * Time: 18:18
 * To change this template use File | Settings | File Templates.
 */

/**
 * Class JobQueue_Base
 *
 * implements the basic methods which are used by the jobqueue master and consumer classes, e.g. loading facility configuration and
 * install pcntl signal handlers
 *
 */

namespace slc\MVC\JobQueue;

abstract class Base {
	const INSTALL_PCNTL_HANDLERS = true;
	const DEBUG = true;

	/**
	 * Consumer facility, uses the corresponding id form configuration file config::JobQueueConsumers
	 *
	 * @var bool|string
	 */
	protected $Facility;

	/**
	 * contains the configuration data for the facility
	 *
	 * @var object
	 */
	private $FacilityConfiguration;

	/**
	 * contains stati of consumers by their corresponding facility
	 *
	 * @var null
	 */
	private static $ConsumersStatus = null;

	/**
	 * register signal handlers and loads the consumer configuration
	 *
	 * @param bool $Facility
	 */
	public function __construct($Facility) {

		$this->Facility = $Facility;
		$this->FacilityConfiguration = \slc\MVC\Base::Factory()->getConfig('JobQueueConsumers', $this->Facility);

		if($this->FacilityConfiguration) {
			if(static::INSTALL_PCNTL_HANDLERS && function_exists('pcntl_signal')) {
				\slc\MVC\Debug::Write('install pcntl_signal handling, STRG + C is safe...', null, 1, 0, __CLASS__);
				pcntl_signal(SIGINT, array($this, "HandlePosixSignals"));
				pcntl_signal(SIGTERM, array($this, "HandlePosixSignals"));
				pcntl_signal(SIGHUP, array($this, "HandlePosixSignals"));
				pcntl_signal(SIGUSR1, array($this, "HandlePosixSignals"));
				pcntl_signal(SIGUSR2, array($this, "HandlePosixSignals"));
				\slc\MVC\Debug::Write('done.', null, 2, 0, __CLASS__);
			} else {
				\slc\MVC\Debug::Write('signal handling failed, pcntl extension missing. Will not use signal handling, be careful with STRG + C.', null, 3, 0, __CLASS__);
			}

			$this->MemoryLimit = ini_get('memory_limit');
			if(preg_match('/([0-9]{1,})(M$|G$)/', $this->MemoryLimit, $MatchResult)) {
				switch($MatchResult[2]) {
					case 'M':
						$this->MemoryLimit = $MatchResult[1] * 1024 * 1024;
						break;
					case 'G':
						$this->MemoryLimit = $MatchResult[1] * 1024 * 1024 * 1024;
						break;
				}
				// default of 15% buffer of memory limit to avoid problems if a method call uses too much memory
				$this->MemoryLimit *= (100 - (isset($this->FacilityConfiguration->MemoryBufferPercentage)?$this->FacilityConfiguration->MemoryBufferPercentage:15)) / 100;
			} elseif($this->MemoryLimit == -1) {
				$this->MemoryLimit = 128 * 1024 * 1024;
			}
		} else
			throw new Base_Exception('MISSING_FACILITY_CONFIGURATION', array('Facility' => $this->Facility));
	}

	/**
	 * returns the current facility configuration, should be used instead accessing the protected variable directly to
	 * make it possible to change the behavior more easily later....
	 *
	 * @return object
	 */
	protected function getFacilityConfig() {
		return $this->FacilityConfiguration;
	}


	abstract public function HandlePosixSignals($signal);
	abstract protected function Setup();

	/**
	 * returns if there is at least one consumer running for the given facility
	 *
	 * @param $Facility
	 * @return mixed
	 */
	public static function isConsumerRunning($Facility) {
		if(!isset(self::$ConsumersStatus[$Facility]) || (self::$ConsumersStatus[$Facility]->LastUpdate + 10) <= time()) {
			$Base = new Base();
			$FacilityConfig = $Base->getConfigAsObject('JobQueueConsumers', 'Consumer', $Facility);
			if($FacilityConfig) {

				$sql = 'SELECT
					COUNT(*) AS Count
				FROM
					`'.JobQueue_Master::TABLE.'` AS jm	INNER JOIN
					`'.$FacilityConfig->ConsumerStatusTable.'` AS jc ON jc.`masterId`=jm.`masterId`
				WHERE
					jm.`Facility`='.$Base->Database->escape($Facility).' AND
					jm.`AppState`='.$Base->Database->escape(DEPLOYMENT_STATE);

				/**
				 * store the result locally to avoid to much database traffic, but to ensure that the result is still valid
				 * invalidate it after 10 seconds, important for longpolls, e.g. after restarting the consumer
				 */
				self::$ConsumersStatus[$Facility] = (object)array(
					'Value' => \slc\MVC\Resources::Factory('Database', __CLASS__)->query($sql)->fetchObject('stdClass')->Count > 0?true:false,
					'LastUpdate' => time()
				);
			} else
				throw new Base_Exception('MISSING_FACILITY_CONFIGURATION', array('Facility' => $Facility));
		}

		return self::$ConsumersStatus[$Facility]->Value;
	}
}

class Base_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 6000000;
	const MISSING_FACILITY_CONFIGURATION = 1;
}

?>
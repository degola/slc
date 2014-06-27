<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 29.07.13
 * Time: 16:52
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC\JobQueue;

abstract class Consumer extends Base {
	const DEBUG = true;

	/**
	 * contains the master id who started this consumer
	 *
	 * @var int|null
	 */
	protected $MasterId;

	/**
	 * contains the pid of the current process or the pid which was assigned by the master
	 *
	 * @var int|null
	 */
	protected $Pid;

	/**
	 * contains a list of parameters with which the process was started
	 *
	 * @var null|object
	 */
	protected $StartParameters = null;

	/**
	 * contains the current consumer data
	 *
	 * @var array
	 */
	public $ConsumerData = array();

	/**
	 * the start time of the consumer
	 *
	 * @var int|null
	 */
	private $StartTime = null;

	/**
	 * defines the max age in seconds of a consumer, if none configured the consumer will run endless
	 *
	 * @var int|null
	 */
	private $MaxAge = null;

	public function __construct($Facility, $MasterId = null, $Pid = null, $StartParameters = null) {
		parent::__construct($Facility);

		$this->MasterId = $MasterId;
		$this->Pid = is_null($Pid)?getmypid():$Pid;
		$this->StartParameters = $StartParameters;
		$this->StartTime = time();
		$this->MaxAge = isset($this->getFacilityConfig()->Execute->Configuration->MaxAge)?$this->getFacilityConfig()->Execute->Configuration->MaxAge:null;

		$this->Setup();
	}

	/**
	 * default posix signal handler (right now only sigterm and sigint implements, later we could also implement some
	 * sigusr signals for refreshing configuration files, etc.
	 *
	 * @param $signal
	 */
	public function HandlePosixSignals($signal) {
		switch($signal) {
			case SIGINT:
			case SIGTERM:
			default:
				\slc\MVC\Debug::Write('received signal '.$signal.', stopping...', null, 3, 1, __CLASS__);
				$this->setConsumerData(array('Status' => 'stopped'));
				break;
		}
	}

	/**
	 * update internal consumer data, this data is used for control the process execution and are stored
	 * periodically to the jobqueue consumer table unless it was forced with the current update
	 *
	 * @param $data
	 */
	protected function setConsumerData($data, $forceUpdate = false) {
		if($diff = (array_diff(array_keys((array)$data), array_keys((array)$this->ConsumerData))))
			throw new Consumer_Exception('INVALID_CONSUMER_DATA', array('Property Diff' => $diff));

		if($forceUpdate === true)
			$this->RefreshConsumerData(true);

		$this->ConsumerData = (object)array_merge(is_object($this->ConsumerData)&&$this->ConsumerData?(array)$this->ConsumerData:array(), (array)$data);
		$this->ConsumerData->__changed = true;

		if($forceUpdate === true)
			return $this->UpdateConsumerData();
		return false;
	}

	/**
	 * returns a variable from the consumer data row
	 *
	 * @param $var
	 * @return null
	 */
	protected final function getConsumerData($var) {
		$this->RefreshConsumerData();
		return isset($this->ConsumerData->$var)?$this->ConsumerData->$var:null;
	}

	/**
	 * updates consumer data if it was changed, this method will be called automatically everytime isStopped() is called
	 *
	 */
	private final function UpdateConsumerData() {
		if(isset($this->ConsumerData->__changed) && $this->ConsumerData->__changed === true) {
			$Updates = array();
			foreach($this->ConsumerData AS $field => $value) {
				// don't update the identifying fields to avoid problems
				if(substr($field, 0, 2) !== '__' && !in_array($field, array('pid', 'masterId')))
					$Updates[] = '`'.$field.'`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($value);
			}

			\slc\MVC\Resources::Factory('Database', __CLASS__)->exec('UPDATE
				`'.$this->getFacilityConfig()->ConsumerStatusTable.'`
			SET
				'.implode(', ', $Updates).'
			WHERE
				`masterId`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($this->getMasterId()).' AND
				`pid`='.$this->getPid());
			$this->ConsumerData->__changed = false;
		}
	}

	/**
	 * refresh local consumer data every 10 seconds from the database to recognize manually changed entries
	 * if the record for our PID and MasterID is missing we're stopping the consumer immediately
	 *
	 * @param bool $force
	 */
	private final function RefreshConsumerData($force = false) {
		if(!isset($this->ConsumerData->__lastUpdated) || $this->ConsumerData->__lastUpdated <= (time() - 10)) {
			$result = \slc\MVC\Resources::Factory('Database', __CLASS__)->query('SELECT
				*
			FROM
				`'.$this->getFacilityConfig()->ConsumerStatusTable.'`
			WHERE
				`masterId`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($this->getMasterId()).' AND
				`pid`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($this->getPid()))->fetchObject('stdClass');
			if($result)
				$this->ConsumerData = $result;
			else $this->ConsumerData = (object)array('Status' => 'stopped');

			$this->ConsumerData->__lastUpdated = time();
		}
	}

	/**
	 * returns true if the consumer should be stopped, otherwise it returns false
	 *
	 * @return bool
	 */
	protected function isStopped() {
		if(function_exists('pcntl_signal_dispatch'))
			pcntl_signal_dispatch();

		// execute updates which were postponed and not executed directly
		$this->UpdateConsumerData();

		// status check
		if($this->getConsumerData('Status') == 'stopped') {
			return true;
		}

		// memory usage
		if(memory_get_usage() >= $this->MemoryLimit) {
			return true;
		}

		// run time
		if(!is_null($this->MaxAge) && time() >= $this->StartTime + $this->MaxAge) {
			return true;
		}

		return false;
	}

	/**
	 * returns the master id for which the consumer was started
	 *
	 * @return int|null
	 */
	protected final function getMasterId() {
		return $this->MasterId;
	}

	/**
	 * returns the own pid for which the consumer was started
	 *
	 * @return int|null
	 */
	protected final function getPid() {
		return $this->Pid;
	}

	/**
	 * starts running the main loop, handles exceptions and calls, onCrash()-method if exception was caught and calls onExit()
	 * at the very end
	 */
	public final function Start() {
		// wait until the master wrote all records to database to avoid immediately stops of the consumers
		sleep(1);

		try {
			$this->Run();
		} catch(\Exception $ex) {
			try {
				\slc\MVC\Debug::Write('Run()-loop failed, exception '.$ex->getMessage().' ('.$ex->getCode().') caught.', 'E', 3, 1, __CLASS__);
				\slc\MVC\Debug::Write('Stacktrace: '.$ex->getTraceAsString(), 'trace', 3, 1, __CLASS__);
				$this->onCrashHard($ex);
			} catch(\Exception $ex) {
				\slc\MVC\Debug::Write('onCrashHard() call failed while handling exception from Run()-loop, exception '.$ex->getMessage().' ('.$ex->getCode().') caught.', 'EF', 3, 1, __CLASS__);
			}
		}
		try {
			\slc\MVC\Debug::Write('calling onExit() after leaving Run()-loop...', null, 1, 1, __CLASS__);
			$this->onExit();
			\slc\MVC\Debug::Write('done.', null, 2, 1, __CLASS__);
		} catch(\Exception $ex) {
			\slc\MVC\Debug::Write('failed, exception from onExit() caught: '.$ex->getMessage().' ('.$ex->getCode().')', 'xF', 2, 1, __CLASS__);
		}
	}

	/**
	 * the Run()-method have to implement the main loop for the consumer, e.g. fetching jobs from the job queue and executing them
	 *
	 * @return mixed
	 */
	abstract protected function Run();

	/**
	 * this method is called after the Run()-main loop was executed completly
	 * @return void
	 */
	abstract protected function onExit();

	/**
	 * this method is always called if something goes wrong (exception was thrown) during the Run()-main loop
	 * this method should remove existing locks, etc., the consumer will die afterwards automatically
	 *
	 * @param Exception $ex
	 * @return void
	 */
	abstract protected function onCrashHard(\Exception $ex);

	/**
	 * this method is always called if something goes wrong while executing a job (exception was thrown) during the Run()-main loop
	 * this method should remove existing locks, etc. but the consumer itself don't stop working
	 *
	 * @param Exception $ex
	 * @return void
	 */
	abstract protected function onCrashSoft(\Exception $ex);

}

class Consumer_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 6000300;
	const INVALID_CONSUMER_DATA = 1;

}


?>
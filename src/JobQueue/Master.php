<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 29.07.13
 * Time: 16:53
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC\JobQueue;

abstract class Master extends Base {
	const DEBUG = true;

	/**
	 * master table
	 */
	const TABLE = 'jobqueue_master';

	/**
	 * defines after how much seconds the master should read it's own entry again from the database
	 */
	const MASTER_DATA_REFRESH_INTERVAL = 10;

	/**
	 * initial and default limit of maximal running processes, can be overwritten with set command
	 *
	 * @var int|null
	 */
	protected $MaxProcs = 10;

	/**
	 * all currently running process ids
	 *
	 * @var null|array
	 */
	protected $ProcessIds = array();

	/**
	 * contains the list of pids and their corresponding logfiles, we'll use that for the consumers table to be able to
	 * fetch logged data later as a stream thru beanstalkd or something
	 *
	 * @var array
	 */
	protected $LogFiles = array();
	protected $LogFilesHandlers = array();
	protected $LogFileBuffer = array();

	/**
	 * contains the list of pids based on the additional argument line, we need this to separate the consumers by
	 * different argument lines, e.g. for event processing and memcached distribution
	 *
	 * @var array
	 */
	protected $PidsByArguments = array();

	/**
	 * local command queue
	 *
	 * @var array
	 */
	protected $LocalCommandQueue = array(
		'kill' => array(),
		'start' => array()
	);

	/**
	 * master configuration (from database table, e.g. status, max/min, etc.)
	 * will be refreshed every MASTER_DATA_REFRESH_INTERVAL seconds
	 *
	 * @var null|object
	 */
	protected $MasterData = null;
	protected $Socket = null;

	/**
	 * load and checks configuration, installs posix signal handlers if available
	 *
	 * @param bool $Facility
	 * @param null $MaxProcs
	 */
	public function __construct($Facility, $MaxProcs = null) {
		parent::__construct($Facility);

		if(!isset($this->getFacilityConfig()->StateCheckProperties))
			throw new Master_Exception('MISSING_STATECHECK_CONFIGURATION', array('Facility' => $this->Facility));

		if(!isset($this->getFacilityConfig()->StateCheckProperties->CalculationMethod))
			throw new Master_Exception(
				'MISSING_STATECHECK_CALCULATION_METHOD_CONFIGURATION',
				array(
					'Facility' => $this->Facility,
					'Property' => 'CalculationMethod'
				)
			);
		if(!isset($this->getFacilityConfig()->StateCheckProperties->CalculationParameters))
			throw new Master_Exception(
				'MISSING_STATECHECK_CALCULATION_PARAMETERS_CONFIGURATION',
				array(
					'Facility' => $this->Facility,
					'Property' => 'CalculationParameters'
				)
			);

		if(!is_null($MaxProcs))
			$this->MaxProcs = $MaxProcs;

		if(isset($this->getFacilityConfig()->MinConsumers))
			$this->setMasterData(array('MinConsumers' => $this->getFacilityConfig()->MinConsumers));

	}

	/**
	 * returns if a jobqueue master for the given facility and app state is running right now and how many consumers
	 * are available (int from 0 for nothing running to $maxConsumers)
	 *
	 * @param $Facility
	 * @param $AppState (optional, if null DEPLOYMENT_STATE is used)
	 * @return bool
	 */
	public static function isRunning($Facility, $AppState = null) {
		if(is_null($AppState)) $AppState = DEPLOYMENT_STATE;

		$FacilityConfiguration = (object)Base::getConfigStatic('JobQueueConsumers', 'Consumer', $Facility);

		$result = \slc\MVC\Resources::Factory('Database', __CLASS__)->query('SELECT
			COUNT(*) AS Count
		FROM
			`'.static::TABLE.'` AS m INNER JOIN
			`'.$FacilityConfiguration->ConsumerStatusTable.'` AS c ON (m.`masterId`=c.`masterId`)
		WHERE
			m.`Facility`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($Facility).' AND
			m.`AppState`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($AppState).' AND
			m.`Status`=\'online\' AND
			c.`Status` NOT IN (\'stopped\')');
		if($result->rowCount() > 0)
			return $result->fetchObject('stdClass')->Count;
		return 0;
	}

	/**
	 * registers and starts a master
	 *
	 * @param bool $ForceStart
	 */
	public function Start($ForceStart = false) {
		if($ForceStart === true || $this->isAlreadyRunning() === false) {
			if($this->Register()) {
				try {
					\slc\MVC\Debug::Write('registered and started master '.$this->getMasterToken().' (#'.$this->getUniqueId().')', 'R', 3, 0, __CLASS__);
					$this->Run();
					\slc\MVC\Debug::Write('master stops normally.', 'e', 3, 0, __CLASS__);
				} catch(Database_Exception $ex) {
					\slc\MVC\Debug::Write('caught database exception: '.$ex->getMessage(), 'F', 2, 0, __CLASS__);
				} catch(Exception $ex) {
					\slc\MVC\Debug::Write('master died with exception: ', $ex->getMessage(), 'F', 2, 0, __CLASS__);
				}
				$this->Unregister();
			} else {
				\slc\MVC\Debug::Write('unable to register master '.$this->getMasterToken(), 'f', 2, 0, __CLASS__);
			}
		} else {
			if($ForceStart === false)
				\slc\MVC\Debug::Write('unable to start master, there is already a registered master for '.$this->getMasterToken(), 'f', 2, 0, __CLASS__);
		}
	}

	/**
	 * stops a master thru the socket server shutdown command
	 *
	 */
	public function Stop() {
		$Port = $this->getSocketPort();

		\slc\MVC\Debug::Write('stopping master on localhost:'.$Port.'...', null, \slc\MVC\Debug::MESSAGE_NEWLINE_BEGIN, 0, __CLASS__);

		$fp = fsockopen('localhost', $Port);
		if($fp) {
			fputs($fp, 'shutdown'."\r\n");
			while(!feof($fp)) {
				fread($fp, 1);
				usleep(10000);
			}
			fclose($fp);
		}
		\slc\MVC\Debug::Write('done.', null, \slc\MVC\Debug::MESSAGE_NEWLINE_END, 0, __CLASS__);
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
				$this->setMasterData(array('Status' => 'offline'));
				break;
		}
	}

	/**
	 * returns an unique identifiable string for the master, based on the host, app state and facility
	 *
	 * @return string
	 */
	protected function getMasterToken() {
		return $this->Facility.'.'.DEPLOYMENT_STATE.'.'.gethostname();
	}

	/**
	 * returns the auto increment id of the master database table
	 *
	 * @return int
	 */
	protected function getUniqueId() {
		return intval($this->MasterId);
	}

	/**
	 * update internal master data, this data is used for control the processes (min, max, expected) and are stored
	 * periodically to the jobqueue master table
	 *
	 * @param $data
	 */
	protected function setMasterData($data) {
		$this->MasterData = (object)array_merge(is_object($this->MasterData)&&$this->MasterData?(array)$this->MasterData:array(), (array)$data);

		if(!isset($this->MasterData->__updatedFields)) $this->MasterData->__updatedFields = array();
		$this->MasterData->__updatedFields = array_unique(array_merge($this->MasterData->__updatedFields, array_keys((array)$data)));
	}

	/**
	 * refreshs master data at least after 10 seconds from the database to recognize manual database changes
	 *
	 * @param bool $setLastAliveDate
	 */
	protected function refreshMasterData($setLastAliveDate = true) {
		if(
			is_null($this->MasterData) ||
			!isset($this->MasterData->__lastUpdated) ||
			$this->MasterData->__lastUpdated <= (time() - 10)
		) {
			if($setLastAliveDate == true || (isset($this->MasterData->__updatedFields) && sizeof($this->MasterData->__updatedFields) > 0)) {
				if($setLastAliveDate === true)
					$this->setMasterData(array('lastAliveDate' => gmdate('Y-m-d H:i:s')));
				// only update fields which were changed to avoid problems with manual changes in the database
				$updates = array();
				foreach($this->MasterData->__updatedFields AS $field) {
					$updates[] = '`'.$field.'`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($this->getMasterData($field));
				}
				$sql = 'UPDATE
					`'.static::TABLE.'`
				SET
					'.implode(', ', $updates).'
				WHERE
					`masterId`='.$this->getUniqueId();

				\slc\MVC\Resources::Factory('Database', __CLASS__)->exec($sql);
			}

			$this->MasterData = \slc\MVC\Resources::Factory('Database', __CLASS__)->query('SELECT * FROM `'.static::TABLE.'` WHERE `masterId`='.$this->getUniqueId())->fetchObject('stdClass');
			$this->MasterData->__lastUpdated = time();
		}
	}

	/**
	 * returns a variable from the master data row
	 *
	 * @param $var
	 * @return null
	 */
	protected function getMasterData($var) {
		return isset($this->MasterData->$var)?$this->MasterData->$var:null;
	}

	/**
	 * check for already running master by it's token
	 *
	 * @return bool
	 */
	protected function isAlreadyRunning() {
		$sql = 'SELECT
			COUNT(*) AS Count
		FROM
			`'.static::TABLE.'`
		WHERE
			`Status` NOT IN (\'dead\', \'offline\') AND
			`MasterToken`=:MasterToken';
		$query = \slc\MVC\Resources::Factory('Database', __CLASS__)->prepare($sql);
		$query->execute(array(':MasterToken' => $this->getMasterToken()));

		$result = $query->fetchObject();

		if($result->Count > 0)
			return true;
		return false;
	}

	/**
	 * creates the socket server instance and opens the port
	 * based on appstate the configured port is increased by 1000 or 2000 (take care of it!)
	 */
	protected function initializeSocketServer() {
		$Port = $this->getSocketPort();
		$this->Socket = new \slc\MVC\Socket_Server($Port);

		return $Port;
	}

	/**
	 * returns the socket port of the current initialized master instance
	 *
	 * @return int
	 */
	protected function getSocketPort() {
		$Port = isset($this->getFacilityConfig()->MasterPort)?$this->getFacilityConfig()->MasterPort:rand(8000, 8999);
		switch(DEPLOYMENT_STATE) {
			case 'testing':
				$Port += 1000;
				break;
			case 'stable':
				$Port += 2000;
				break;
		}
		return $Port;
	}

	/**
	 * creates the entry in the master table
	 *
	 * @return bool
	 */
	protected function Register() {
		$port = $this->initializeSocketServer();

		$this->NumberOfExpectedConsumers = $this->getMasterData('MinConsumers');
		$query = \slc\MVC\Resources::Factory('Database', __CLASS__)->prepare('INSERT INTO
			`'.static::TABLE.'`
		SET
			`MasterToken`=:MasterToken,
			`Facility`=:Facility,
			`AppState`=:AppState,
			`MinConsumers`=:MinConsumers,
			`MaxConsumers`=:MaxConsumers,
			`ExpectedConsumers`=:ExpectedConsumers,
			`MasterPort`=:MasterPort,
			`Status`=\'online\',
			`insertDate`=NOW(),
			`lastAliveDate`=NOW()');
		$query->execute(array(
			':MasterToken' => $this->getMasterToken(),
			':Facility' => $this->Facility,
			':AppState' => DEPLOYMENT_STATE,
			':MinConsumers' => intval($this->getMasterData('MinConsumers')),
			':MaxConsumers' => $this->MaxProcs,
			':ExpectedConsumers' => intval($this->getMasterData('ExpectedConsumers')),
			':MasterPort' => $port,

		));
		if($query->rowCount() == 1) {
			$this->MasterId = \slc\MVC\Resources::Factory('Database', __CLASS__)->lastInsertId();

			$this->refreshMasterData(false);

			\slc\MVC\Logger::Factory('JobQueue::Master')->addInfo(
				$this->getMasterToken().' #'.$this->getUniqueId().': registered new '.__CLASS__, array(
				'MasterToken' => $this->getMasterToken(),
				'MasterId' => $this->getUniqueId()
			));

			try {
				$this->Setup();
			} catch(Exception $ex) {
				$this->Unregister('dead');
				\slc\MVC\Debug::Write('setup failed for '.$this->getMasterToken().' (#'.$this->getUniqueId().'), exception '.get_class($ex).' caught: '.$ex->getMessage(), 'Fs', 2, 0, __CLASS__);
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * set's the given reason as status to the database table
	 *
	 * @param string $reason
	 * @return mixed
	 */
	protected function Unregister($reason = 'offline') {
		\slc\MVC\Logger::Factory('JobQueue::Master')->addInfo(
			$this->getMasterToken().' #'.$this->getUniqueId().': unregistered with status '.$reason, array(
			'MasterToken' => $this->getMasterToken(),
			'MasterId' => $this->getUniqueId(),
			'Status' => $reason
		));

		$this->Socket->close();

		$query = \slc\MVC\Resources::Factory('Database', __CLASS__)->prepare('UPDATE
			`'.static::TABLE.'`
		SET
			`Status`=:reason
		WHERE
				`masterId`=:masterId');
		$query->execute(array(':reason' => $reason, ':masterId' => $this->getUniqueId()));
		return $query->rowCount();
	}

	/**
	 * returns if the master was stopped or has an status != online
	 *
	 * @return bool
	 */
	protected function isStopped() {
		$this->Socket->dispatch(array($this, 'handleSocketData'));

		if($this->getMasterData('Status') != 'online')
			return true;
		return false;
	}

	/**
	 * returns the number of expected consumers
	 *
	 * @return int
	 */
	protected function getNumberOfExpectedConsumers() {
		return json_decode($this->getMasterData('ExpectedConsumers'));
	}

	/**
	 * returns the number of currently running consumer process
	 *
	 * @return int
	 */
	protected function getNumberOfRunningConsumers() {
		$this->checkConsumerAliveness(null);
		$tmp = $this->PidsByArguments;
		array_walk($tmp, function (&$item) { $item = sizeof($item); });
		return $tmp;
	}

	/**
	 * the main loop for the master, checks, creates and handles stops, consumer creations, etc.
	 * secures also the possibility to handle pcntl_signals for STRG + C, SIGINT, SIGUSR1, etc.
	 *
	 */
	protected function Run() {
		// initial start, set expected consumers by min and max values
		$this->calculateExpectedConsumers(0);
		$lastMemoryUsageOutput = null;

		do {
			try {
				// do the check operation stuff to get fresh number of expected consumers
				$this->CheckOperation();
			} catch(Exception $ex) {
				\slc\MVC\Debug::Write('check operation failed, got exception '.$ex->getMessage().' ('.$ex->getCode().')...continuing work', 'E', 3, 1, __CLASS__);
			}
			$diff = 0;
			$lastDiff = 0;
			$lastDiffTime = 0;
			$expected = $this->getNumberOfExpectedConsumers();
			if($expected) {
				foreach($expected AS $Arguments => $expected) {
					if($Arguments != '_empty_') {
						$running = $this->getNumberOfRunningConsumers();
						$running = isset($running[$Arguments])?$running[$Arguments]:0;

						$diff = $expected - $running;
						if(($diff > 0 || $diff < 0) && ($lastDiff != $diff || $lastDiffTime + 10 < time())) {
							\slc\MVC\Debug::Write('found difference bitween number of expected consumers ('.$Arguments.' expects '.$expected.')  vs. number of running consumers ('.$running.', diff '.$diff.')...', 'd', 1, 1, __CLASS__);
							\slc\MVC\Logger::Factory('JobQueue::Master')->addInfo(
								$this->getMasterToken().' #'.$this->getUniqueId().': found difference bitween number of expected consumers ('.$Arguments.' expects '.$expected.')  vs. number of running consumers ('.$running.', diff '.$diff.')', array(
								'MasterToken' => $this->getMasterToken(),
								'MasterId' => $this->getUniqueId(),
								'Running' => $running,
								'Expected' => $expected,
								'Diff' => $diff
							));

							if($diff > 0) {
								$this->StartConsumer($diff, $Arguments);
							} else {
								$diff *= -1;
								if($diff > 1)
									$rand = array_rand($this->PidsByArguments[$Arguments], $diff);
								else
									$rand = array(array_rand($this->PidsByArguments[$Arguments]));

								$pids = array();
								foreach($rand AS $id)
									$pids[] = $this->PidsByArguments[$Arguments][$id];

								$this->KillConsumer($pids);
							}
							$lastDiff = $diff;
							$lastDiffTime = time();
						}
					}
				}
			}

			try {
				$this->refreshMasterData(true);
			} catch(Exception $ex) {
				\slc\MVC\Debug::Write('refresh master data failed, got exception '.$ex->getMessage().' ('.$ex->getCode().')', 'E', 3, 1, __CLASS__);
			}

			usleep(intval($this->getFacilityConfig()->CheckInterval) * 1000);
			if(function_exists('pcntl_signal_dispatch'))
				pcntl_signal_dispatch();

			if($lastMemoryUsageOutput <= time() - 10 || $diff != 0) {
				\slc\MVC\Debug::Write('I\'m master #'.$this->getUniqueId().', current memory usage: '.number_format(memory_get_usage(), 0).' bytes ('.number_format(memory_get_usage() / 1024 / 1024, 2).' MB)', null, 3, 1, __CLASS__);
				$lastMemoryUsageOutput = time();
			}

			unset($expected);
			unset($running);
			unset($diff);

			$this->transferLogFiles();
		} while($this->isStopped() === false);

		$waitCounter = 0;
		do {
			sleep(min(60, $waitCounter++));

			$this->KillConsumer();
		} while(sizeof($this->getLocalJobs('kill')));
	}

	/**
	 * enqueues or overwrites the local job queue
	 *
	 * @param $type
	 * @param $values
	 * @param bool $overwrite
	 */
	protected function enqueueLocalJobs($type, $values, $overwrite = false) {
		if($overwrite === true)
			$this->LocalCommandQueue[$type] = $values;
		else
			$this->LocalCommandQueue[$type] = array_unique(array_merge($this->LocalCommandQueue[$type], $values));
	}

	/**
	 * returns a list of local jobs (usually pids) for the given type
	 *
	 * @param $type
	 * @return mixed
	 */
	protected function getLocalJobs($type) {
		return $this->LocalCommandQueue[$type];
	}

	/**
	 * starts given number of consumers unless all consumers were started
	 *
	 * @todo implement consumer status table usage
	 *
	 * @param int $count
	 * @return bool
	 */
	protected function StartConsumer($count = 1, $additionalArguments = '') {
		\slc\MVC\Debug::Write('start '.$count.' consumers...', 'S', \slc\MVC\Debug::MESSAGE_NEWLINE_BEGIN, 1, __CLASS__);

		$NumberOfRunningConsumers = $this->getNumberOfRunningConsumers();
		$NumberOfRunningConsumers = isset($NumberOfRunningConsumers[$additionalArguments])?$NumberOfRunningConsumers[$additionalArguments]:0;

		if($NumberOfRunningConsumers + $count > $this->getMasterData('MaxConsumers')) {
			$newCount = $this->getMasterData('MaxConsumers') - $NumberOfRunningConsumers;
			if($newCount > 0) {
				\slc\MVC\Debug::Write($count.' not possible, maximum number of consumers reached, reduced to '.$newCount.'...', null, \slc\MVC\Debug::MESSAGE_NEWLINE_BEGIN, 1, __CLASS__);
				$count = $newCount;
			} else {
				return false;
			}
		}

		if($NumberOfRunningConsumers + $count <= $this->getMasterData('MaxConsumers')) {
			$base = 'nohup %1$s >%2$s 2>&1 & echo $!';
			$pids = $this->ProcessIds; // array();
			for($i = 1; $i <= $count; $i++) {
				// define log file name, make it random to avoid two processes with the same log file...
				$logFile = sprintf($this->getFacilityConfig()->Execute->LogFile, gmdate('Y-m-d_His').'_'.(sizeof($pids) + 1));
				$dir = dirname($logFile);
				if(!file_exists($dir)) @mkdir($dir, 0777, true);
				unset($dir);
				
				$cmd = sprintf($this->getFacilityConfig()->Execute->Command, 'Facility='.$this->Facility, 'MasterId='.$this->getUniqueId(), $additionalArguments);
				$cmd = sprintf($base, $cmd, $logFile);
				\slc\MVC\Debug::Write('execute '.$cmd.'...', null, 1, 2, __CLASS__);
				$pids[] = $pid = exec($cmd);
				usleep(1000);
				$this->LogFiles[$pid] = $logFile;
				$this->LogFilesHandlers[$pid] = fopen($logFile, 'r');
				fseek($this->LogFilesHandlers[$pid], 0);
				stream_set_blocking($this->LogFilesHandlers[$pid], 0);

				if(!isset($this->PidsByArguments[$additionalArguments]))
					$this->PidsByArguments[$additionalArguments] = array();

				$this->PidsByArguments[$additionalArguments][] = $pid;

				\slc\MVC\Debug::Write('done. (pid '.$pid.')', null, 2, 1, __CLASS__);
				unset($pid);
				unset($cmd);

				$aliveResult = $this->checkConsumerAliveness();

				if(sizeof($aliveResult) != sizeof($pids)) {
					// remove logfile entries to avoid memleaks...
					foreach(array_diff($aliveResult, $pids) AS $pid) {
						$this->cleanupPidRecords($pid);
					}

					\slc\MVC\Debug::Write('failed, less alive consumers than created ('.sizeof($pids).' vs. '.sizeof($aliveResult).'), trying again to start '.$count - sizeof($aliveResult).' consumers in 100ms...', null, 2, 2, __CLASS__);
					usleep(100000);
					return $this->StartConsumer($count - sizeof($aliveResult), $additionalArguments);
				}

				$this->UpdateConsumerStatus();
				usleep(100000);

			}
			\slc\MVC\Debug::Write('done.', null, 2, 1, __CLASS__);
			return true;
		}

		return false;
	}

	/**
	 * cleans the local storage variables from the given pid
	 *
	 * @param $pid
	 * @param $cleanArguments
	 */
	private function cleanupPidRecords($pid) {
		unset($this->LogFiles[$pid]);
		if(isset($this->LogFilesHandlers[$pid])) {
			fclose($this->LogFilesHandlers[$pid]);
			unset($this->LogFilesHandlers[$pid]);
		}
		foreach(array_keys($this->PidsByArguments) AS $arguments)
		if(($index = array_search($pid, $this->PidsByArguments[$arguments])) !== false)
			unset($this->PidsByArguments[$arguments][$index]);
	}

	/**
	 * @todo check if we really need the update consumer status things...
	 */
	protected function UpdateConsumerStatus() {
		try {
			// do the database update stuff here but currently we don't need it since we have all important informations in the master row
			$sql = 'DELETE FROM
				`'.$this->getFacilityConfig()->ConsumerStatusTable.'`
			WHERE
				`masterId`='.$this->getUniqueId();
			if(sizeof($this->ProcessIds) > 0) {
				$sql .= ' AND
							`pid` NOT IN ('.implode(', ', $this->ProcessIds).')';
			}

			\slc\MVC\Resources::Factory('Database', __CLASS__)->query($sql);

			$inserts = array();
			foreach($this->ProcessIds AS $pid) {
				$inserts[] = implode(', ', array(
					'\'running\'',
					$this->getUniqueId(),
					\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($pid),
					\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($this->LogFiles[$pid]),
					'NOW()',
					'NOW()'
				));
			}
			if(sizeof($inserts) > 0) {
				$sql = 'INSERT INTO
					`'.$this->getFacilityConfig()->ConsumerStatusTable.'`
					(`status`, `masterId`, `pid`, `logFile`, `lastAliveDate`, `insertDate`)
				VALUES
					('.implode('), (', $inserts).')
				ON DUPLICATE KEY UPDATE
					`logFile`=VALUES(`logFile`),
					`lastAliveDate`=VALUES(`lastAliveDate`)';

				\slc\MVC\Resources::Factory('Database', __CLASS__)->query($sql);
			}
		} catch(Database_Exception $ex) {
			\slc\MVC\Debug::Write('database query failed while updating consumer status: '.$ex->getMessage(), 'E', 3, 0, __CLASS__);
		}
	}

	/**
	 * tries to kill consumers by their pid, all pids or random pids if only number of consumers were given
	 * if the kill was not successful it will be enqueued to the local job to ensure that the consumers will killed after
	 * time, we can't wait until the job is killed because single consumers could do things right now and we have to wait
	 * until they finished.
	 *
	 * @param array $pids
	 * @param null $numberOfConsumers
	 */
	protected function KillConsumer(array $pids = null) {
		$killAll = false;
		// build pid list, based on number of consumers, given pids or all pids

		if(is_null($pids)) {
			$killAll = true;
			\slc\MVC\Debug::Write('kill all ('.sizeof($this->ProcessIds).') consumers...', 'K', 1, 1, __CLASS__);
			$pids = $this->ProcessIds;
		} else {
			\slc\MVC\Debug::Write('kill '.sizeof($pids).' consumers...', 'K', 1, 1, __CLASS__);
			\slc\MVC\Debug::Write('(pids '.implode(', ', $pids).')...', null, 0, 1, __CLASS__);
		}

		/**
		 * enqueue to local jobs, this is important because we can't wait until the process was killed successfully regarding
		 * important things which have to be done by the consumer (releasing locks, finishing queries, etc.) and this can take
		 * several seconds up to minutes and/or hours. therefore we just enqueue that we want to kill it, send the SIGTERM signal
		 * to the job and trying to kill it again as soon as possible (if the signal was not received successful.
		 * In this way the master ensures that it clean up everything
		 */
		$this->enqueueLocalJobs('kill', $pids);
		foreach($this->getLocalJobs('kill') AS $pid) {
			posix_kill($pid, SIGTERM);
			usleep(50000);
		}

		/**
		 * after the posix_kill we check the consumer aliveness and write the difference to the enqueued local jobs list which will
		 * be executed by runLocalJobs()
		 */
		$stillAlivePids = $this->checkConsumerAliveness($pids);
		$this->enqueueLocalJobs('kill', $stillAlivePids, $killAll);

		$this->UpdateConsumerStatus();

		if(sizeof($stillAlivePids) == 0)
			\slc\MVC\Debug::Write('done.', null, 2, 1, __CLASS__);
		else
			\slc\MVC\Debug::Write('executed but some processes still running ('.sizeof($stillAlivePids).')', null, 2, 1, __CLASS__);
	}

	/**
	 * returns pids for all running php consumer processes for the current master
	 *
	 * @return array
	 */
	protected function getProcessList() {
		$pids = array();
		$result = exec("ps a |grep 'MasterId=".$this->getUniqueId()."' |grep 'Consumer'", $output);
		foreach($output AS $process) {
			if(preg_match('/^(.*?)([0-9]{1,5})(.*?)exec\.php/', $process, $target))  {
				$pids[] = $target[2];
			}
		}
#               echo "pids alive: ".implode(', ', $pids)."\n";
		return $pids;
	}

	/**
	 * checks if the given process ids or all started processes are still alive and write the active process ids to the
	 * list which is used by the Run() method to determine if we have to start new jobs or if we have to kill jobs
	 *
	 * @param array $pids
	 * @param bool $overwriteResult
	 * @return array
	 */
	protected function checkConsumerAliveness(array $pids = null, $overwriteResult = false) {
		if(is_null($pids)) {
			$pids = $this->ProcessIds;
			$overwriteResult = true;
		}

		$alivePids = $this->getProcessList($pids);

		$OldPids = $this->ProcessIds;
		if($overwriteResult === false) {
			/**
			 * first we have to figure out which processes where killed successful, afterwards we have to substract these pids from the process id list
			 * but we also have to merge the requested pids with $this->ProcessIds because only this method is setting $this->ProcessIds
			 * and we don't have any pid during start or directly after a started consumer
			 */
			$this->ProcessIds = array_diff(array_unique(array_merge($pids, $this->ProcessIds)), array_diff($pids, $alivePids));

		} else {
			$this->ProcessIds = $alivePids;
		}

		if(sizeof($OldPids) > 0) {
			foreach(array_diff($OldPids, $this->ProcessIds) AS $pid) {
				$this->MergeAndRemoveLogFile($pid);
			}
		}
		unset($OldPids);

		return $alivePids;
	}

	/**
	 * returns string which identifies this process
	 *
	 * @return string
	 */
	protected function getLocalRecipientAddress() {
		return $this->getMasterToken().'::'.$this->getUniqueId();
	}

	/**
	 * returns if the given address matches the own process or a global rule
	 *
	 * @param $address
	 * @return bool
	 */
	protected function CheckRecipient($address) {
		if($address == 'all') return true;

		return $this->getLocalRecipientAddress()===$address;
	}

	/**
	 * updates the master data number of expected consumers based on the given value and based on the StateCheckProperties-configuration
	 * should be called from trhe corresponding checkOperation()-method which will add further informations regarding number of currently
	 * available jobs
	 *
	 * @param $NumberOfJobs
	 */
	protected function calculateExpectedConsumers($NumberOfJobsByArguments) {
		$expectedConsumers = $this->{'calculateExpectedConsumers'.$this->getFacilityConfig()->StateCheckProperties->CalculationMethod}(
			$NumberOfJobsByArguments
		);

		$expectedConsumers = json_encode($expectedConsumers);
		if($expectedConsumers != json_encode($this->getNumberOfExpectedConsumers())) {
			$this->setMasterData(array('ExpectedConsumers' => $expectedConsumers));
		}

		unset($expectedConsumers);
	}

	/**
	 * returns the expected number of consumers based on the number of jobs given by the inherited class
	 *
	 * @param $NumberOfJobs
	 * @return int
	 * @throws JobQueue_Master_Exception
	 */
	protected function calculateExpectedConsumersEval($NumberOfJobsByArguments) {
		// check if this is just an old implementation and change to array (arguments => number, default arguments is an empty string)
		if(is_scalar($NumberOfJobsByArguments)) $NumberOfJobsByArguments = array('' => $NumberOfJobsByArguments);

		$result = array();
		foreach($NumberOfJobsByArguments AS $Arguments => $NumberOfJobs) {

			$evalString = '$tmp = '.$this->getFacilityConfig()->StateCheckProperties->CalculationParameters.';';
			@eval($evalString);
			if(!isset($tmp))
				throw new Master_Exception(
					'CALCULATE_EXPECTED_CONSUMERS_EVAL_FAILED',
					array(
						'Facility' => $this->Facility,
						'EvalString' => $evalString
					)
				);
			unset($evalString);
			// avoid under or over running masters limit
			$result[$Arguments] = min(max($tmp, $this->getMasterData('MinConsumers')), $this->getMasterData('MaxConsumers'));
		}
		return $result;
	}

	/**
	 * returns the expected number of consumers, to find them out the configured static method will be called, this allows
	 * us a more custom way to build the required number of consumers, e.g. based on number of messages in a single amqp channel, etc.
	 *
	 * @param $NumberOfJobsByArguments
	 * @return array
	 */
	protected function calculateExpectedConsumersByForeignMethod($NumberOfJobsByArguments) {
		if(is_scalar($NumberOfJobsByArguments)) $NumberOfJobsByArguments = array('' => $NumberOfJobsByArguments);

		return call_user_func(
			$this->getFacilityConfig()->StateCheckProperties->CalculationParameters,
			$this->Facility,
			$this->getFacilityConfig(),
			$NumberOfJobsByArguments
		);
	}

	/**
	 * remove a log file for given pid and extend the day logfile with the stopped consumer log
	 *
	 * @param $pid
	 */
	protected function MergeAndRemoveLogFile($pid) {
		$LogFile = $this->LogFiles[$pid];
		$LogFileBase = $this->getFacilityConfig()->Execute->LogFile;
		if($LogFile && file_exists($LogFile) && is_file($LogFile)) {
			$LogFileMergedPath = sprintf($LogFileBase, gmdate('Y-m-d'));
			$fpDestination = fopen($LogFileMergedPath, 'a');
			$fpSource = fopen($LogFile, 'r');
			if($fpDestination && $fpSource) {
				while(!flock($fpDestination, LOCK_EX)) usleep(1000);
				while(!flock($fpSource, LOCK_EX)) usleep(1000);

				while(!feof($fpSource)) {
					$line = rtrim(fgets($fpSource, 8192));
					if(strlen($line) > 0) {
						fputs($fpDestination, $this->getMasterToken().'['.$this->getUniqueId().'::'.$pid.'] '.$line."\n");
					}
				}
				unset($line);
				flock($fpSource, LOCK_UN);
				flock($fpDestination, LOCK_UN);
				fclose($fpSource);
				fclose($fpDestination);
			}

			unlink($LogFile);
		}
		$this->cleanupPidRecords($pid);
	}

	/**
	 * method for sending latest log files to clients which connected to the socket server of the master process
	 */
	protected function transferLogFiles() {
		foreach($this->LogFilesHandlers AS $pid => $fh) {
			if(!isset($this->LogFileBuffer[$pid]))
				$this->LogFileBuffer[$pid] = '';

			while(strlen($char = fread($fh, 16)) > 0) {
				$this->LogFileBuffer[$pid] .= $char;
			}
			if(strlen($this->LogFileBuffer[$pid]) > 0 && preg_match('/\n$/', $this->LogFileBuffer[$pid])) {
				$this->LogFileBuffer[$pid] = str_replace("\n", "\n\033[38;5;203m[PID ".$pid."]\033[1;0m", $this->LogFileBuffer[$pid]);
				$this->Socket->send($this->LogFileBuffer[$pid]);
				$this->LogFileBuffer[$pid] = '';
			}
		}
	}

	/**
	 * handles incoming data from socket connections
	 *
	 * @param $socketId
	 * @param $socket
	 * @param $msg
	 */
	public function handleSocketData($socketId, $socket, $msg) {
		$msg = trim($msg);
		if(strpos($msg, ' ') !== false) {
			list($cmd, $data) = explode($msg, 2);
		} else $cmd = $msg;

		switch($cmd) {
			case 'quit':
				$this->Socket->send('Goodbye.'."\n");
				$this->Socket->disconnectClient($socketId);
				break;
			case 'status':
				foreach(array(
					'MasterToken',
					'Facility',
					'AppState',
					'MinConsumers',
					'MaxConsumers',
					'ExpectedConsumers',
					'MasterPort',
					'Status',
					'insertDate',
					'lastAliveDate'
				) AS $key) {
					$data[] = $key.': '.$this->getMasterData($key);
				}
				$data[] = 'RunningConsumers: '.sizeof($this->ProcessIds);
				$data[] = 'RunningPids: '.implode(',', $this->ProcessIds);
				$data = implode("\n", $data);
				$data .= "\n".str_repeat('-', 10)."\nstatus response end\n";
				$this->Socket->send('status response start'."\n".str_repeat('-', 10)."\n".(strlen($data))."\n".$data);
				break;
			case 'shutdown':
				$this->setMasterData(array('Status' => 'offline'));
				break;
		}
	}

	abstract protected function CheckOperation();

}

class Master_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 6000200;
	const MISSING_STATECHECK_CONFIGURATION = 1;
	const MISSING_STATECHECK_CALCULATION_METHOD_CONFIGURATION = 2;
	const MISSING_STATECHECK_CALCULATION_PARAMETERS_CONFIGURATION = 3;
	const CALCULATE_EXPECTED_CONSUMERS_EVAL_FAILED = 4;
}


?>
<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 02.08.13
 * Time: 18:17
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC\JobQueue;

abstract class Consumer_Beanstalk extends Consumer {
	const DEBUG = true;

	/**
	 * @var Beanstalk_Provider
	 */
	protected $Beanstalk = null;

	/**
	 * setups beanstalkd object
	 *
	 * @throws JobQueue_Master_AMQP_Beanstalk_Exception
	 */
	protected function Setup() {

		if(!isset($this->getFacilityConfig()->StateCheckProperties->BeanstalkConfigId))
			throw new Consumer_Beanstalk_Exception(
				'MISSING_STATECHECK_BEANSTALK_CONFIG_ID_CONFIGURATION',
				array(
					'Facility' => $this->Facility,
					'Property' => 'BeanstalkConfigId'
				)
			);

		\slc\MVC\Debug::Write('setting up beanstalkd connection...', null, 1, 1, __CLASS__);

        if(isset($this->StartParameters->Host) && isset($this->StartParameters->Port)) {
            $this->Beanstalk = new \slc\MVC\Beanstalkd\Driver(
                array(
                    'Host' => $this->StartParameters->Host,
                    'Port' => $this->StartParameters->Port,
                    'Connections' => isset($this->StartParameters->Connections)?$this->StartParameters->Connections:10
                )
            );
            $tube = $this->getFacilityConfig()->Consumer->BeanstalkTube;
        } else {
            $Provider = \Beanstalk_Provider::Factory(null, $this->getFacilityConfig()->StateCheckProperties->BeanstalkConfigId);
            $this->Beanstalk = $Provider->getDriver();
            $tube = $this->Beanstalk->getTube();
        }
		\slc\MVC\Debug::Write('listen to tube '.$tube.'...', null, 0, 1, __CLASS__);
		$this->Beanstalk->watch($tube);
		\slc\MVC\Debug::Write('done.', null, 2, 1, __CLASS__);

	}

	/**
	 * starts the main loop of the job consumption process
	 * handles locked jobs based on the configuration file
	 *
	 * @return mixed|void
	 */
	protected final function Run() {

		\slc\MVC\Debug::Write('starting job consumption...', null, 3, 1, __CLASS__);

		$this->Beanstalk->getDriver()->startReserve();

		while(is_array($jobs = $this->Beanstalk->getDriver()->fetchReserved(array($this, 'CheckConsumerStatus')))) {
            foreach($jobs AS $job) {
				/**
				 * @var $job \slc\MVC\Beanstalkd\Driver_Job
				 */
				$JobData = json_decode(trim($job->getData()));
				if(
					// check if the consumer should take care about the job execution regarding race conditions (LockJobExecution)
					(
						isset($this->getFacilityConfig()->Execute->Configuration->LockJobExecution) &&
						$this->getFacilityConfig()->Execute->Configuration->LockJobExecution === 'true' &&
						$this->CreateJobLock($JobData)
					) ||
					!isset($this->getFacilityConfig()->Execute->Configuration->LockJobExecution) ||
					$this->getFacilityConfig()->Execute->Configuration->LockJobExecution !== 'true'
				) {
					$jobExecutableResult = $this->isJobExecutable($job, $JobData);
					$ReleaseDelayResult = 5;
					if(preg_match('/^release ([0-9]{1,})/i', $jobExecutableResult, $ReleaseDelayResult)) {
						$ReleaseDelayResult = intval($ReleaseDelayResult[1]);
						$jobExecutableResult = 'release';
					}

					switch($jobExecutableResult) {
						case 'delete':
							\slc\MVC\Debug::Write('isJobExecutable delivered "delete", deleting job ('.$JobData->JobId.')', 'jD', 2, 2, __CLASS__);
							$job->delete();
							break;
						case 'release':
							\slc\MVC\Debug::Write('isJobExecutable delivered "release", releasing job', 'jR', 2, 2, __CLASS__);
							// release job with low priority to make other jobs faster
							$job->release($ReleaseDelayResult, 10000);
							break;
						case 'execute':
						default:
							try {
								\slc\MVC\Debug::TimeUsageStart(__CLASS__, __METHOD__, 'JobQueue_Consumer_Beanstalk::executeJob');
								\slc\MVC\Debug::Write('start job execution.', 's', 1, 1, __CLASS__);
								$jobExecutionResult = $this->executeJob($job, $JobData);
								switch($jobExecutionResult->Type) {
									case 'release':
										if(isset($jobExecutionResult->Delay) && $jobExecutionResult->Delay > 0) {
											$job->release($jobExecutionResult->Delay);
										} else {
											$job->release();
										}
										break;
									case 'delete':
									default:
										$job->delete();
										break;

								}
								\slc\MVC\Debug::Write('job execution done. ('.number_format(\slc\MVC\Debug::TimeUsageEnd(__CLASS__, __METHOD__, 'JobQueue_Consumer_Beanstalk::executeJob'), 5, '.', ',')."s)", 'x', 2, 1, __CLASS__);
							} catch(\Exception $ex) {
								\slc\MVC\Debug::TimeUsageEnd(__CLASS__, __METHOD__, 'JobQueue_Consumer_Beanstalk::executeJob');
								\slc\MVC\Debug::Write('job execution failed, job release, exception caught: '.$ex->getMessage().' ('.$ex->getCode().')', 'E', 3, 1, __CLASS__);
								\slc\MVC\Debug::Write('Stack: '.$ex->getTraceAsString(), null, 3, 1, __CLASS__);

								\slc\MVC\Logger::Factory('Logger::JobQueue::Master')->addCritical('caught exception while running job, executing onCrashSoft(), exception '.$ex->getMessage().' ('.$ex->getCode().') caught.', array(
									'ErrorMessage' => $ex->getMessage(),
									'ErrorCode' => $ex->getCode(),
									'ErrorFile' => $ex->getFile(),
									'ErrorLine' => $ex->getLine(),
									'ErrorTrace' => $ex->getTraceAsString()
								));

								try {
									$this->onCrashSoft($ex);
								} catch(\Exception $ex) {
									// delete job lock
									if(
										isset($this->getFacilityConfig()->Execute->Configuration->LockJobExecution) &&
										$this->getFacilityConfig()->Execute->Configuration->LockJobExecution === 'true'
									) {
										$this->DeleteJobLock($JobData);
									}
									\slc\MVC\Logger::Factory('Logger::JobQueue::Master')->addCritical('caught exception while executing onCrashSoft(), exception '.$ex->getMessage().' ('.$ex->getCode().') caught.', array(
										'ErrorMessage' => $ex->getMessage(),
										'ErrorCode' => $ex->getCode(),
										'ErrorFile' => $ex->getFile(),
										'ErrorLine' => $ex->getLine(),
										'ErrorTrace' => $ex->getTraceAsString()
									));

									throw $ex;
								}

								$job->release();
							}
					}

					// delete job lock
					if(
						isset($this->getFacilityConfig()->Execute->Configuration->LockJobExecution) &&
						$this->getFacilityConfig()->Execute->Configuration->LockJobExecution === 'true'
					) {
						$this->DeleteJobLock($JobData);
					}

				} else {
					// check if we have to delete locked jobs or if we just release it
					if(isset($this->getFacilityConfig()->Execute->Configuration->DeleteLockedJobs) && $this->getFacilityConfig()->Execute->Configuration->DeleteLockedJobs === 'true') {
						\slc\MVC\Debug::Write('job locked, deleting job', null, 2, 1, __CLASS__);
						$job->delete();
					} else {
						\slc\MVC\Debug::Write('job locked, releasing job and delay for 5 seconds', null, 2, 1, __CLASS__);
						$job->release(5);
					}
				}
			}
		}

		\slc\MVC\Debug::Write('job consumption stopped (last jobs result: '.print_r($jobs, true).').', null, 3, 1, __CLASS__);
	}

	/**
	 * returns if the consumer should be stopped as (string)true regarding beanstalkd
	 *
	 * @return string
	 */
	public final function CheckConsumerStatus() {
		// the beanstalkd driver callback requires a "string(true)" instead of a boolean
		if($this->isStopped())
			return 'true';
		return;
	}
	/**
	 * builds a memcache lock key for given job id
	 *
	 * @param $JobId
	 * @return string
	 */
	private function buildJobLockId($JobData) {
		if(isset($JobData->JobId))
			return $this->Facility.'::'.APP_STATE.'::'.sha1($JobData->JobId);
		else
			return $this->Facility.'::'.APP_STATE.'::'.sha1(json_encode($JobData));
	}

	/**
	 * creates a job lock and returns true if it was successful created, otherwise false after 100 tries
	 *
	 * @param $JobId
	 * @return mixed
	 */
	private function CreateJobLock($JobData) {
		$LockId = $this->buildJobLockId($JobData);
		$TryCounter = 0;
		\slc\MVC\Debug::Write('try to create job lock with LockId '.$LockId.'...', null, Debug::MESSAGE_NEWLINE_BEGIN, 1, __CLASS__);
		while(!($LockCreated = $this->DataCache->add($LockId, $JobData, 86400)) && $TryCounter++ < 5) {
			usleep(10000);
		}
		if($LockCreated)
			\slc\MVC\Debug::Write('done.', null, Debug::MESSAGE_NEWLINE_END, 2, __CLASS__);
		else
			\slc\MVC\Debug::Write('failed.', null, Debug::MESSAGE_NEWLINE_END, 2, __CLASS__);

		return $LockCreated;
	}

	/**
	 * deletes a job lock
	 *
	 * @param $JobId
	 * @return bool
	 */
	private function DeleteJobLock($JobData) {
		$LockId = $this->buildJobLockId($JobData);
		\slc\MVC\Debug::Write('delete job lock with LockId '.$LockId.'...', null, Debug::MESSAGE_NEWLINE_BEGIN, 1, __CLASS__);
		$result = $this->DataCache->delete($LockId);
		if($result)
			\slc\MVC\Debug::Write('done.', null, Debug::MESSAGE_NEWLINE_END, 2, __CLASS__);
		else
			\slc\MVC\Debug::Write('failed.', null, Debug::MESSAGE_NEWLINE_END, 2, __CLASS__);
		return $result;
	}

	/**
	 * executed a single job from jobqueue and returns true for success, otherwise false
	 * if false was returned the job is released but not deleted so that the job will be executed again
	 *
	 * @param $Job
	 * @return bool
	 */
	abstract protected function executeJob(\slc\MVC\Beanstalkd\Driver_Job $Job, $JobData);

	/**
	 * returns if the given job is executable right now or if it have to be released or deleted
	 *
	 * @param BeanstalkDriver_Job $Job
	 * @param $JobData
	 * @return delete, release or execute
	 */
	abstract protected function isJobExecutable(\slc\MVC\Beanstalkd\Driver_Job $Job, $JobData);

	/**
	 * we have to take care about beanstalkd connections, otherwise the script hangs unlimited
	 */
	protected function onExit() {
		$this->Beanstalk->getDriver()->disconnect(true);
	}
}


class Consumer_Beanstalk_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 6000400;
	const MISSING_STATECHECK_BEANSTALK_CONFIG_ID_CONFIGURATION = 1;

}

?>

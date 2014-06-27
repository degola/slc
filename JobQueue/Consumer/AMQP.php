<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 02.08.13
 * Time: 18:17
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC\JobQueue;

abstract class Consumer_AMQP extends Consumer {
	const DEBUG = true;

	/**
	 * @var AMQP_Provider
	 */
	protected $AMQP = null;
	protected $VHost = null;
	protected $QueueName = null;
	protected $ConsumerTag = null;
	protected $DurableQueues = true;
	protected $DurableExchange = true;
	/**
	 * @var \PhpAmqpLib\Channel\AMQPChannel
	 */
	protected $Channel = null;

	/**
	 * setups amqp object
	 *
	 * @throws JobQueue_Consumer_AMQP_Exception
	 */
	protected function Setup() {
		if(!isset($this->getFacilityConfig()->StateCheckProperties->AMQPConfigId))
			throw new Consumer_AMQP_Exception(
				'MISSING_STATECHECK_AMQP_CONFIG_ID_CONFIGURATION',
				array(
					'Facility' => $this->Facility,
					'Property' => 'AMQPConfigId'
				)
			);

		\slc\MVC\Debug::Write('setting up amqp connection...', null, 1, 1, __CLASS__);

		$AMQPConfig = new \ConnectionConfig('AMQP', $this->getFacilityConfig()->StateCheckProperties->AMQPConfigId);
		$Config = $AMQPConfig->getConfig();

		$this->AMQP = \AMQP_Provider::Factory(new \ConnectionConfig('AMQP', $this->getFacilityConfig()->StateCheckProperties->AMQPConfigId), $this->getFacilityConfig()->StateCheckProperties->AMQPConfigId);
		$this->AMQP->setVHost($this->VHost);
		$this->AMQP->initQueue(
			(object)array(
				'name' => ($this->Facility.'.'.DEPLOYMENT_STATE.'.'.($this->QueueName?$this->QueueName:(isset($Config->QueueName)?$Config->QueueName:'no-queue-name-specified'))),
				'durable' => $this->DurableQueues,
				'auto_delete' => !$this->DurableQueues,
			),
			(object)array(
				'name' => $this->Facility.'.'.DEPLOYMENT_STATE,
				'type' => 'fanout',
				'durable' => $this->DurableExchange,
				'auto_delete' => !$this->DurableExchange,
			),
			(object)array(
				'consumer_tag' => $this->Facility.'.'.DEPLOYMENT_STATE.'.'.($this->ConsumerTag?$this->ConsumerTag:'no-consumer-tag-specified'),
				'callback' => array(&$this, 'handleJob')
			)
		);
		$this->Channel = $this->AMQP->getChannel();
		$this->Channel->basic_qos(0, 10, false);
		\slc\MVC\Debug::Write('done.', null, 2, 1, __CLASS__);
	}
	protected function reconnect() {
		$AMQPConfig = new \ConnectionConfig('AMQP', $this->getFacilityConfig()->StateCheckProperties->AMQPConfigId);
		$Config = $AMQPConfig->getConfig();

		$this->AMQP = \AMQP_Provider::Factory(new \ConnectionConfig('AMQP', $this->getFacilityConfig()->StateCheckProperties->AMQPConfigId), $this->getFacilityConfig()->StateCheckProperties->AMQPConfigId);
		$this->AMQP->disconnect();
		$this->AMQP->setVHost($this->VHost);
		$this->Channel = $this->AMQP->getChannel();
		$this->Channel->basic_qos(0, 10, false);
	}

	/**
	 * starts the main loop of the job consumption process
	 * handles locked jobs based on the configuration file
	 *
	 * @return mixed|void
	 */
	protected final function Run() {
		\slc\MVC\Debug::Write('starting job consumption...', null, 3, 1, __CLASS__);

		while($this->isStopped() !== true) {
			pcntl_signal_dispatch();
			/**
			 * we can't use the wait method because we need a non-blocking call to not interrupt the masters main functionality
			 * so we have to build it by our own, therefore we use basic_get and check afterwards if there was a valid response
			 * if not, don't do anything and just return, otherwise wait to let the amqp lib doing the usual stuff...
			 */
			$read   = array($this->AMQP->getConnection()->getSocket()); // add here other sockets that you need to attend
			$write  = null;
			$except = null;
			if (false === ($num_changed_streams = stream_select($read, $write, $except, 60))) {
				echo "AMQP ERROR FROM STREAM SELECT\n";
				/* Error handling */
			} elseif ($num_changed_streams > 0) {
				$this->Channel->wait();
			}
/*
			if(sizeof($this->Channel->method_queue) > 0) {
				$this->Channel->wait();
			}
*/
		}

		\slc\MVC\Debug::Write('job consumption stopped.', null, 3, 1, __CLASS__);
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
			return $this->Facility.'::'.DEPLOYMENT_STATE.'::'.sha1($JobData->JobId);
		else
			return $this->Facility.'::'.DEPLOYMENT_STATE.'::'.sha1(json_encode($JobData));
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
		while(!($LockCreated = $this->DataCache->add($LockId)) && $TryCounter++ < 100) {
			usleep(10000);
		}
		return $LockCreated;
	}

	/**
	 * deletes a job lock
	 *
	 * @param $JobId
	 */
	private function DeleteJobLock($JobData) {
		return $this->DataCache->delete($this->buildJobLockId($JobData));
	}

	/**
	 * handles a job and acknowledged it if the executeJob method returns (bool)true, otherwise the job is nacked and the job
	 * is also nacked if an exception was thrown
	 *
	 * @param \PhpAmqpLib\Message\AMQPMessage $Job
	 */
	public final function handleJob(\PhpAmqpLib\Message\AMQPMessage $Job) {
		try {
			\slc\MVC\Debug::Write("job received, start handling...", null, \slc\MVC\Debug::MESSAGE_NEWLINE_BEGIN, 1, __CLASS__);
			if($this->executeJob($Job) === true) {
				$Job->delivery_info['channel']->basic_ack($Job->delivery_info['delivery_tag']);
				\slc\MVC\Debug::Write("done, job handled successful.", null, \slc\MVC\Debug::MESSAGE_NEWLINE_BEGIN, 1, __CLASS__);
			} else {
				$Job->delivery_info['channel']->basic_nack($Job->delivery_info['delivery_tag']);
                \slc\MVC\Debug::Write('job execution failed, message not acknowledged, executeJob() returns false instead of true.', null, \slc\MVC\Debug::MESSAGE_NEWLINE_END, 2, __CLASS__);
			}
		} catch(\Exception $ex) {
			try {
				$Job->delivery_info['channel']->basic_nack($Job->delivery_info['delivery_tag']);
			} catch(\PhpAmqpLib\Exception\AMQPConnectionException $ex) {
				$this->reconnect();
				$this->Channel->basic_recover(false);
			}
            \slc\MVC\Debug::Write('job execution failed, message not acknowledged, exception caught: '.$ex->getMessage()."\n".$ex->getTraceAsString(), null, \slc\MVC\Debug::MESSAGE_NEWLINE_END, 2, __CLASS__);
			$this->onCrashSoft($ex);
		}
	}

	/**
	 * executed a single job from jobqueue and returns true for success, otherwise false
	 * if false was returned the job is released but not deleted so that the job will be executed again
	 *
	 * @param $Job
	 * @return bool
	 */
	abstract protected function executeJob(\PhpAmqpLib\Message\AMQPMessage $Job);

	/**
	 * returns if the given job is executable right now or if it have to be released or deleted
	 *
	 * @param BeanstalkDriver_Job $Job
	 * @param $JobData
	 * @return delete, release or execute
	 */
	abstract protected function isJobExecutable($Job, $JobData);

	/**
	 * we have to take care about beanstalkd connections, otherwise the script hangs unlimited
	 */
	protected function onExit() {
		$this->Channel->basic_cancel($this->ConsumerTag);
		$this->Channel->close();
		$this->AMQP->disconnect();
	}
}


class Consumer_AMQP_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 6000600;
	const MISSING_STATECHECK_AMQP_CONFIG_ID_CONFIGURATION = 1;

}

?>
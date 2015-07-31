<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 29.07.13
 * Time: 18:19
 * To change this template use File | Settings | File Templates.
 */
namespace slc\MVC\JobQueue;

class Master_AMQP extends Master {
	const DEBUG = false;

	/**
	 * @var \PhpAmqpLib\Connection\AMQPConnection
	 */
	protected $Connection = null;

	/**
	 * @var AMQPChannel
	 */
	protected $Channel = null;

	/**
	 * amqp queue name for master commands
	 *
	 * @var String
	 */
	protected $Queue = null;

	/**
	 * build amqp connections and bindings
	 */
	protected function Setup() {
		$AMQPConfig = new \ConnectionConfig('AMQP', isset($this->getFacilityConfig()->StateCheckProperties->AMQPConfigId)?$this->getFacilityConfig()->StateCheckProperties->AMQPConfigId:'default');
		$Config = $AMQPConfig->getConfig();

		$this->Connection = new \PhpAmqpLib\Connection\AMQPConnection(
			$Config->hostname,
			$Config->port,
			$Config->username,
			$Config->password,
			$Config->vhost
		);

		$this->Queue = $this->getMasterToken().'.'.$this->getUniqueId().'.commands';
		$this->Channel = $this->Connection->channel();

		// define amqp exchange, use fanout as exchange
		$exchange = $this->Facility.'.'.DEPLOYMENT_STATE.'.commands.fanout';
		$this->Channel->exchange_declare($exchange, 'fanout', false, true, true);

		if(isset($Config->QueueName)) {
			$this->Channel->exchange_declare($this->Facility.'.'.DEPLOYMENT_STATE, 'fanout', false, true, false);
			$this->Channel->queue_declare($this->Facility.'.'.DEPLOYMENT_STATE.'.'.$Config->QueueName, false, true, false, false, false, false);
			$this->Channel->queue_bind($this->Facility.'.'.DEPLOYMENT_STATE.'.'.$Config->QueueName, $this->Facility.'.'.DEPLOYMENT_STATE);
		}

		// build and bind an own queue to get messages immediately
		$this->Channel->queue_declare($this->Queue);
		$this->Channel->queue_bind($this->Queue, $exchange);
		$this->Channel->basic_consume(
			$this->Queue, // queue
			$this->getMasterToken(), // name, @todo check what it means...
			false,
			false,
			false,
			false,
			array($this, 'ConsumeCommand')
		);

	}

	/**
	 * consume command, e.g. kill and set property
	 *
	 * @param $message
	 */
	public function ConsumeCommand($message) {
		\slc\MVC\Debug::Write('received AMQP message...', 'R', 1, 1, __CLASS__);

		if(strpos($message->body, ' ') !== false) {
			list($Command, $Data) = explode(' ', $message->body, 2);

			switch($Command) {
				case 'kill':
					if($this->CheckRecipient($Data)) {
						\slc\MVC\Debug::Write('handle kill command...', 'k', 1, 2, __CLASS__);
						$this->setMasterData(array('Status' => 'offline'));
						\slc\MVC\Debug::Write('done.', null, 2, 2, __CLASS__);
					} else {
						\slc\MVC\Debug::Write('command ignored, wrong recipient '.$this->getLocalRecipientAddress().' vs. '.$Data, null, 2, 1, __CLASS__);
					}
					break;
				case 'stop':
					list($Recipient, $NumberOfConsumers) = @explode(' ', $Data, 2);
					if($this->CheckRecipient($Recipient)) {
						if($NumberOfConsumers == 'all')
							$NumberOfConsumers = $this->getMasterData('ExpectedConsumers');

						\slc\MVC\Debug::Write('handle stop consumer command, stop '.$NumberOfConsumers.' consumers...', 'K', 1, 1, __CLASS__);
						$this->setMasterData(array('ExpectedConsumers' => $this->getMasterData('ExpectedConsumers') - $NumberOfConsumers));
					}
					break;
				case 'start':
					list($Recipient, $NumberOfConsumers) = @explode(' ', $Data, 2);
					if($this->CheckRecipient($Recipient)) {
						\slc\MVC\Debug::Write('handle start consumer command, create '.$NumberOfConsumers.' consumers...', 's', 1, 1, __CLASS__);
						// it's easy, just increase the number of expected consumers and let the master main loop do the job...
						$this->setMasterData(array('ExpectedConsumers' => $NumberOfConsumers + $this->getMasterData('ExpectedConsumers')));
					}
					break;
				case 'set':
					list($Property, $Recipient, $value) = @explode(' ', $Data, 3);
					if($this->CheckRecipient($Recipient)) {
						\slc\MVC\Debug::Write('set '.$Property.' to '.$value.'...', 'p', 0, 1, __CLASS__);
						switch($Property) {
							case 'MaxConsumers':
							case 'MinConsumers':
								$this->setMasterData(array($Property => $value));
								break;
							default:
								\slc\MVC\Debug::Write('invalid property.', null, 0, 1, __CLASS__);
						}
					} else {
						\slc\MVC\Debug::Write('command ignored, wrong recipient '.$this->getLocalRecipientAddress().' vs. '.$Recipient, null, 0, 1, __CLASS__);
					}
					break;
			}
		} else {
			\slc\MVC\Debug::Write('wrong format / message', null, 2, 1, __CLASS__);
		}
		$this->Channel->basic_ack($message->delivery_info['delivery_tag']);
		\slc\MVC\Debug::Write('done.', null, 2, 1, __CLASS__);
	}

	/**
	 * check if a command is waiting and execute ConsumeCommand method
	 *
	 * this method is called from JobQueue_Master::Run() very often, be careful with implementing too much stuff here, avoid
	 * too much cpu usage with rand() return;
	 */
	protected function CheckOperation() {
		// \slc\MVC\Debug::Write('master is still alive.', '.', 0, 0, true, __CLASS__);

		if(rand(0, 30) === 0) { // only execute every 20 calls (if check interval is 50ms this means every 1,5 seconds)

			try {
				/**
				 * we can't use the wait method because we need a non-blocking call to not interrupt the masters main functionality
				 * so we have to build it by our own, therefore we use basic_get and check afterwards if there was a valid response
				 * if not, don't do anything and just return, otherwise wait to let the amqp lib doing the usual stuff...
				 */
				$this->Channel->basic_get($this->Queue);

				if(sizeof($this->Channel->method_queue) > 0)
					$this->Channel->wait();

				$this->checkConsumerAliveness();
			} catch(Exception $ex) {
				if(preg_match('/Error sending data/i', $ex->getMessage())) {
					\slc\MVC\Debug::Write('failed to fetch data from AMQP, try to reconnect...', null, 1, 1, __CLASS__);
					try {
						@$this->Connection->close();
					} catch(Exception $ex) {
						// just in case...
					}
					try {
						$this->Setup();
						\slc\MVC\Debug::Write('done.', null, 2, 1, __CLASS__);
					} catch(Exception $ex) {
						\slc\MVC\Debug::Write('failed...try again later.', null, 2, 1, __CLASS__);
					}
				} else throw $ex;

			}
		}
	}
}

?>
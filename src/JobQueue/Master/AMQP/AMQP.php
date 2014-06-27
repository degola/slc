<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 31.07.13
 * Time: 15:56
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC\JobQueue;

class Master_AMQP_AMQP extends Master_AMQP {

	/**
	 * @var null|AMQP_Provider
	 */
	protected $AMQP = null;

	/**
	 * defines how often the api of the rabbitmq server should be requested
	 *
	 * @var int
	 */
	protected $CheckInterval = 8;

	/**
	 * setups amqp object
	 *
	 * @throws JobQueue_Master_AMQP_AMQP_Exception
	 */
	protected function Setup() {
		parent::Setup();

		if(!isset($this->getFacilityConfig()->StateCheckProperties->AMQPConfigId))
			throw new Master_AMQP_AMQP_Exception(
				'MISSING_STATECHECK_AMQP_CONFIG_ID_CONFIGURATION',
				array(
					'Facility' => $this->Facility,
					'Property' => 'AMQPConfigId'
				)
			);

		$this->AMQP = \AMQP_Provider::Factory(new \ConnectionConfig('AMQP', $this->getFacilityConfig()->StateCheckProperties->AMQPConfigId));
		$this->CheckInterval = isset($this->getFacilityConfig()->StateCheckProperties->CheckInterval)?$this->getFacilityConfig()->StateCheckProperties->CheckInterval:8;
	}

	/**
	 * sets the number of expected consumers based on the configured tube status
	 */
	protected function CheckOperation() {
		// first call the parent.
		parent::CheckOperation();

		if(rand(0, $this->CheckInterval) === 1) { // only execute every 8 calls (if check interval is 50ms this means every 400ms)
			try {
				$QueueResult = $this->AMQP->getAPIResult('/api/queues');
				$QueueNumbers = array();
				foreach($QueueResult AS $Queue) {
					if(preg_match('/^'.$this->Facility.'\.'.DEPLOYMENT_STATE.'\.([a-z0-9\-]{1,})$/i', $Queue->name, $result) && isset($Queue->messages)) {
						$QueueNumbers[$result[1]] = $Queue->messages;
					}
				}
				$this->calculateExpectedConsumers($QueueNumbers);
			} catch(\AMQP_Provider_Exception $ex) {
				\slc\MVC\Debug::Write('CheckOperation failed, caught AMQP_Provider_Exception '.$ex->getMessage().' ('.$ex->getCode().')', 'F', 3, 1, __CLASS__);
			}
		}
	}
}

class Master_AMQP_AMQP_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 6000500;
	const MISSING_STATECHECK_AMQP_CONFIG_ID_CONFIGURATION = 1;

}

?>
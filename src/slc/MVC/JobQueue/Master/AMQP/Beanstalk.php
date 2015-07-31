<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 31.07.13
 * Time: 15:56
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC\JobQueue;

class Master_AMQP_Beanstalk extends Master_AMQP {
	const DEBUG = true;
	/**
	 * @var null|Beanstalk_Provider
	 */
	protected $Beanstalk = null;
	/**
	 * defines how often the beanstalkd server should be requested
	 *
	 * @var int
	 */
	protected $CheckInterval = 8;

	/**
	 * setups beanstalkd object
	 *
	 * @throws Master_AMQP_Beanstalk_Exception
	 */
	protected function Setup() {
		parent::Setup();

		if(!isset($this->getFacilityConfig()->StateCheckProperties->BeanstalkConfigId))
			throw new Master_AMQP_Beanstalk_Exception(
				'MISSING_STATECHECK_BEANSTALK_CONFIG_ID_CONFIGURATION',
				array(
					'Facility' => $this->Facility,
					'Property' => 'BeanstalkConfigId'
				)
			);

		$this->Beanstalk = \Beanstalk_Provider::Factory(null, $this->getFacilityConfig()->StateCheckProperties->BeanstalkConfigId);
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
				$result = $this->Beanstalk->getDriver()->getStatsTube($this->Beanstalk->getTube());

				if(get_class($result) === 'slc\MVC\Beanstalkd\Driver_StatsTubeResult') {
					// set the expected consumers amount...
					$this->calculateExpectedConsumers(array('tube-'.$this->Beanstalk->getTube() => $result->get('current-jobs-urgent') + $result->get('current-jobs-ready')));
				}
			} catch(\slc\MVC\Beanstalkd\Driver_Connection_Exception $ex) {
				Debug::Write('CheckOperation failed, caught BeanstalkDriver_Connection_Exception '.$ex->getMessage().' ('.$ex->getCode().'), restarting beanstalkd connection...', 'F', 3, 1, __CLASS__);
				$this->Beanstalk->destroyInstance();

				$this->Beanstalk = Beanstalk_Provider::Factory($this->getFacilityConfig()->StateCheckProperties->BeanstalkConfigId);
				Debug::Write('beanstalkd connection successfully re-established after BeanstalkDriver_Connection_Exception...done.', null, Debug::MESSAGE_NEWLINE_BEGIN + Debug::MESSAGE_NEWLINE_END, 1, __CLASS__);
			} catch(\slc\MVC\Beanstalkd\Driver_Exception $ex) {
				Debug::Write('CheckOperation failed, caught BeanstalkDriver_Exception '.$ex->getMessage().' ('.$ex->getCode().')', 'F', 3, 1, __CLASS__);
			}
		}
	}

}

class Master_AMQP_Beanstalk_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 6000100;
	const MISSING_STATECHECK_BEANSTALK_CONFIG_ID_CONFIGURATION = 2;

}

?>
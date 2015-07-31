<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 17.10.13
 * Time: 15:21
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC\JobQueue;

class Job_Beanstalk extends Job {
	/**
	 * @var \Beanstalk_Provider
	 */
	protected $Beanstalk = null;
	public $JobId = null;
	public $JobData = null;

	public function Setup() {
		if(!isset($this->getFacilityConfig()->StateCheckProperties->BeanstalkConfigId))
			throw new JobQueue_Job_Beanstalk_Exception(
				'MISSING_STATECHECK_BEANSTALK_CONFIG_ID_CONFIGURATION',
				array(
					'Facility' => $this->Facility,
					'Property' => 'BeanstalkConfigId'
				)
			);

		$this->Beanstalk = \Beanstalk_Provider::Factory(null, $this->getFacilityConfig()->StateCheckProperties->BeanstalkConfigId);
	}
	public function __sleep() {
		return array(
			'JobId',
			'JobData'
		);
	}
	public function create($data, $JobId = null, $priority = 0, $delay = 0) {
		$this->JobData = base64_encode(serialize($data));
		if(is_null($JobId))
			$this->JobId = sha1(serialize($this->JobData));
		else
			$this->JobId = $JobId;

		return $this->Beanstalk->getDriver()->publishMessage($this, $this->Beanstalk->getTube(), $priority, $delay);
	}
}

?>
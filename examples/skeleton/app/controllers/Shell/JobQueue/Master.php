<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 14.11.13
 * Time: 20:59
 */

namespace HoneyTracks\Controller {
	class Shell_JobQueue_Master extends \slc\MVC\Application_Controller_Shell {
		public function Start($params = null) {
			global $argv;
			if(!isset($params->Facility) || !isset($params->MaxProcs))
				die('php '.$argv[0].' Facility=<FACILITY> MaxProcs=<Maximum amount of procs for this master> [Class=<jobqueue master class, default: JobQueue_Master_AMQP>'."\n");

			if(isset($params->Class)) $class = $params->Class;
			else {
				$Config = \slc\MVC\Base::Factory()->getConfig('JobQueueConsumers', $params->Facility); // $this->getConfigAsObject('JobQueueConsumers', 'Consumer', $params->Facility);

				if(!$Config) die('php '.$argv[0].' Facility='.$params->Facility.' failed, invalid configuration'."\n");

				if(!isset($Config->MasterClass))
					$class = '\slc\MVC\JobQueue\Master_AMQP_AMQP';
				else
					$class = $Config->MasterClass;
			}

			$Master = new $class($params->Facility, $params->MaxProcs);

			$Master->Start(isset($params->Force)&&$params->Force==='true'?true:false);
		}
		public function Stop($params = null) {
			global $argv;
			if(!isset($params->Facility))
				die('php '.$argv[0].' Facility=<FACILITY> [Class=<jobqueue master class, default: JobQueue_Master_AMQP>'."\n");

			if(isset($params->Class)) $class = $params->Class;
			else {
				$Config = \slc\MVC\Base::Factory()->getConfig('JobQueueConsumers'); // $this->getConfigAsObject('JobQueueConsumers', 'Consumer', $params->Facility);

				if(!$Config) die('php '.$argv[0].' Facility='.$params->Facility.' failed, invalid configuration'."\n");

				if(!isset($Config->MasterClass))
					$class = '\slc\MVC\JobQueue\Master_AMQP_AMQP';
				else
					$class = $Config->MasterClass;
			}

			$Master = new $class($params->Facility, null);
			$Master->stop();
		}
	}
}

?>
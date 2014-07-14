<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 14.11.13
 * Time: 20:54
 */

namespace HoneyTracks\Controller {
	class Shell_JobQueue_Consumer extends \slc\MVC\Application_Controller_Shell {
		public function Start($params) {
			global $argv;
			$params = (object)$params;
			if(!isset($params->Facility)) {
				die($argv[0].' Facility=<FACILITY> MasterId=<MASTER ID> [Pid=<PID>]'."\n");
			}

			// default for pid is null which links to the pid of the current php process
			if(!isset($params->Pid)) $params->Pid = null;

			$Config = \slc\MVC\Base::Factory()->getConfig('JobQueueConsumers', $params->Facility);

			if(isset($Config->Execute->Configuration->Class)) {
				$class = $Config->Execute->Configuration->Class;
				$obj = new $class($params->Facility, $params->MasterId, $params->Pid, $params);
				$obj->Start();
			} else {
				throw new \Exception('invalid jobqueue configuration');
			}


		}
	}
}

?>
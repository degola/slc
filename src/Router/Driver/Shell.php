<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 24.02.13
 * Time: 16:08
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC;

class Router_Driver_Shell extends Router_Driver {
	/**
	 * contains an argument to name mapper array (e.g. -c is internally used as controller)
	 *
	 * @var array
	 */
	protected $argumentAssignment = array(
		'-c' => 'controller'
	);

	protected function parseRoute() {
		$originalRawData = $this->getQueryString();
		$parsedData = array('controlData' => array(), 'params' => array());
		for($i = 1; $i < sizeof($originalRawData); $i++) {
			if(array_key_exists($originalRawData[$i], $this->argumentAssignment) && array_key_exists($i + 1, $originalRawData) && $originalRawData[$i + 1]) {
				$parsedData['controlData'][$this->argumentAssignment[$originalRawData[$i]]] = $originalRawData[$i + 1];
				$i++;
			} elseif(($seperatorPosition = strpos($originalRawData[$i], '='))) {
				$parsedData['params'][substr($originalRawData[$i], 0, $seperatorPosition)] = substr($originalRawData[$i], $seperatorPosition + 1);
			}
		}

		$this->StartRoute = $parsedData['controlData']['controller'];
		$this->setViewArguments($parsedData['params']);
		if($result = $this->findControllerAndViewByRouteString($this->StartRoute)) {
			$this->setController($result->FilePath, $result->Class);
			$this->setView((object)array(
				'View' => $result->View,
				'Path' => $result->ViewPath
			));
			return true;
		}

		return false;
	}
}

?>
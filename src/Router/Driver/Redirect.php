<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 24.02.13
 * Time: 16:08
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC;

class Router_Driver_Redirect extends Router_Driver {
	protected function parseRoute() {
		$QueryString = $this->getQueryString();
		if(isset($QueryString["View"])) {
			if($result = $this->findControllerAndViewByRouteString($QueryString["View"])) {
				$this->setController($result->FilePath, $result->Class);
				$this->setView((object)array(
					'View' => $result->View,
					'Path' => $result->ViewPath,
				));
				if(isset($QueryString["Arguments"]))
					$this->setViewArguments($QueryString["Arguments"]);
				return true;
			}
		}
		return false;
	}
}

?>
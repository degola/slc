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
    /**
     * @var Router_Driver
     */
    private $originalRouter;
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
    public function setOriginalRouterDriver(Router_Driver $router) {
        $this->originalRouter = $router;
        return $this;
    }
    public function link($link, array $arguments = null, $includeDomain = false) {
        if($this->originalRouter instanceof Router_Driver) {
            return $this->originalRouter->link($link, $arguments, $includeDomain);
        }
        throw new Router_Driver_Exception('ORIGINAL_ROUTER_DRIVER_NOT_SET', array());
    }
}

?>
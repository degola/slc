<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 24.02.13
 * Time: 16:08
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC;

class Router_Driver_QueryString extends Router_Driver {
	protected function parseRoute() {
		parse_str(preg_replace('/^\/(index\.php|)\?|^\/|^\?/', '', $this->getQueryString()), $QueryStringArray);

		$this->setViewArguments($QueryStringArray);

		if(isset($QueryStringArray['r'])) {
			$Route = preg_replace('/,/', '::', $QueryStringArray['r']);
		} else {
			$Route = Base::Factory()->getConfig('Application', 'DefaultRoute');
		}
		$this->StartRoute = $Route;
		if($result = $this->findControllerAndViewByRouteString($Route)) {
			$this->setController($result->FilePath, $result->Class);
			$this->setView((object)array(
				'View' => $result->View,
				'Path' => $result->ViewPath
			));
			return true;
		}
		return false;
	}
	public function link($link, array $arguments = null, $includeDomain = false) {
		$prefix = '';
		if($includeDomain)
			$prefix = ((isset($_SERVER['SSL'])&&$_SERVER['SSL'])||(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS'])?'https://':'http://').(isset($_SERVER['HOST'])?$_SERVER['HOST']:$_SERVER['HTTP_HOST']);
		$args = array();
		if(is_array($arguments)) {
			foreach ($arguments AS $key => $value) {
				if(is_scalar($value)) {
					$args[] = urlencode($key) . '=' . urlencode($value);
				}
			}
		}
		return $prefix.$_SERVER['PHP_SELF'].'?r='.implode(',', explode('::', $link)).(sizeof($arguments)>0?'&'.implode('&', $args):'');
	}

}

?>
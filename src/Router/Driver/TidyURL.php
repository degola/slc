<?php
	/**
	 * Created by JetBrains PhpStorm.
	 * User: degola
	 * Date: 24.02.13
	 * Time: 16:08
	 * To change this template use File | Settings | File Templates.
	 */

	namespace slc\MVC;

	class Router_Driver_TidyURL extends Router_Driver {
		protected function parseRoute() {
			if(preg_match('/^([a-zA-Z0-9\/\-_]{1,})(|\?(.*))$/', $this->getQueryString(), $matchResult)) {
				$arguments = array_pop($matchResult);
				$QueryStringArray = array();
				parse_str($arguments, $QueryStringArray);

				$Route = preg_replace('/\//', '::', $matchResult[1]);
				if (!$Route || $Route == '::') {
					$Route = Base::Factory()->getConfig('Application', 'DefaultRoute');
				}
				$Route = trim($Route, '::');
				$this->StartRoute = $Route;
				if ($result = $this->findControllerAndViewByRouteString($Route)) {
					$this->setController($result->FilePath, $result->Class);
					$this->setView((object)array(
						'View' => $result->View,
						'Path' => $result->ViewPath
					));
					$QueryStringArray['__AdditionalViewParameters'] = $result->AdditionalViewParameters;
					$this->setViewArguments($QueryStringArray);

					return true;
				}
			}
			return false;
		}
		public function link($link, array $arguments = array(), $includeDomain = false) {
			if($includeDomain)
				$prefix = ($_SERVER['SSL']?'https://':'http://').$_SERVER['HOST'];
			$args = array();
			foreach($arguments AS $key => $value) {
				$args[] = urlencode($key).'='.urlencode($value);
			}
			return $_SERVER['PHP_SELF'].'?r='.implode(',', explode('::', $link)).(sizeof($arguments)>0?'&'.implode('&', $args):'');
		}

	}

	?>
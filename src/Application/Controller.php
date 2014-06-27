<?php

namespace slc\MVC;

class Application_Controller {
	private $FRAMEWORK = null;
	private $RENDER_ENGINE = null;
	private $ASSIGNMENTS = array();
	public final function __construct(Router_Driver $Router) {
		$this->FRAMEWORK = (object)array(
			'Router' => $Router
		);

	}
	public final function Render() {
		$cls = $this->RENDER_ENGINE;
		if(is_null($cls))
			$cls = Base::Factory()->getConfig('Framework', 'DefaultRenderEngine');

		$obj = null;
		if(class_exists($cls)) {
			/**
			 * @var RenderEngine
			 */
			$obj = new $cls($this->FRAMEWORK->Router, $this);
			if(is_a($obj, '\slc\MVC\RenderEngine')) {
				$obj->setTemplateValues($this->ASSIGNMENTS);
				return $obj;
			}
			else
				throw new Application_Controller_Exception(Application_Controller_Exception::INVALID_RENDER_ENGINE, array('RenderEngine' => $cls));
		}
		throw new Application_Controller_Exception(Application_Controller_Exception::INVALID_RENDER_ENGINE_NOT_FOUND, array('RenderEngine' => $cls));
	}
	protected final function setRenderEngine($class) {
		$this->RENDER_ENGINE = $class;
	}

	public function useHTTPRequestMethodInViewName() {
		return false;
	}

	protected function assign($key, $value) {
		$this->ASSIGNMENTS[$key] = $value;
	}
	protected function assignArray(array $array = array()) {
		$this->ASSIGNMENTS = array_merge($this->ASSIGNMENTS, $array);
	}
	protected function getFrameworkVars() {
		return $this->FRAMEWORK;
	}

}

class Application_Controller_Exception extends Application_Exception {
	const INVALID_RENDER_ENGINE = 'Invalid render engine (class RenderEngine not inherited)';
	const INVALID_RENDER_ENGINE_NOT_FOUND = 'Invalid render engine (not found)';
}

?>
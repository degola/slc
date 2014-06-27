<?php

namespace slc\MVC;

abstract class RenderEngine {
	protected $Router = null;
	protected $Controller = null;
	protected $TemplateValues = null;
	public function __construct(Router_Driver $Router, Application_Controller $Controller = null) {
		$this->Router = $Router;
		$this->Controller = $Controller;

		$this->initialize();
	}
	protected final function getTemplateValues() {
		return array_merge(
			array(
				'Router' => $this->Router,
				'Controller' => $this->Controller
			),
			$this->TemplateValues
		);
	}
	public final function setTemplateValues($TemplateValues) {
		$this->TemplateValues = $TemplateValues;
	}

	protected function getViewPath() {
		$View = $this->Router->getView();
		return preg_replace('/::/', DIRECTORY_SEPARATOR, $View->Path.'::'.$View->View.Base::Factory()->getConfig('FileExtensions', 'View'));
	}

	abstract protected function initialize();
	abstract public function Fetch();
}

/**
 * Class RenderEngine_Exception
 *
 * @package slc\MVC
 */
class RenderEngine_Exception extends Application_Exception {
	const INVALID_VIEW = 'invalid view path, check route';
}

?>
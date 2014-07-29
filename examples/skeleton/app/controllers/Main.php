<?php

namespace Application\Controller;

use slc\MVC\Router_Driver_Redirect;

class Main extends \slc\MVC\Application_Controller {
	/**
	 * returns if the current request has access to the controller / view
	 * (bool)true = has access, everything else access is denied and router throws an Router_Exception
	 *
	 * @params array ViewArguments
	 * @return bool
	 */
	public function __hasAccess($ViewArguments) {
		return false;
	}

	/**
	 * executed before the view method in controller class is called
	 *
	 * @param array $ViewArguments
	 */
	public function __before($ViewArguments) {
	}

	/**
	 * executed after the view method in controller class was called
	 */
	public function __after($ViewArguments) {
	}

	/**
	 * if overwritten from \slc\MVC\Application_Controller and if returns true the $_SERVER['REQUEST_METHOD'] is prefixed
	 * to the requested view name, very useful if you want to offer RESTful APIs
	 *
	 * @return bool
	 */
	public function useHTTPRequestMethodInViewName() {
		return true;
	}

	/**
	 * a view method where the assignments for the view should happen
	 * see optional inline calls to get some useful hints
	 *
	 * @return Router_Driver_Redirect
	 */
	public function GET_Start() {
		$this->assign('VariableName', 'Value');

		/**
		 * setRenderEngine()-method from \slc\MVC\Application_Controller may be called to change the default
		 * render engine configured in Main.ini, may be also called in __before or __after methods
		 *
		 * if you use Twig as render engine VariableName will be assigned to the template, if you use JSON as render engine
		 * VariableName is available within the ViewData property of the JSON object
		 */
		$this->setRenderEngine('\slc\MVC\RenderEngine_JSON');

		// if an instance of Router_Driver is returned the router will execute the returned route afterwards
		return new Router_Driver_Redirect(array('View' => 'Main::RedirectedView'));
    }

}

?>
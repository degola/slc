<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 24.02.13
 * Time: 16:08
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC;

class Router {
	static private $__OWN_OBJECT = null;
	private $Hooks = array(
		'onBeforeExecute' => array()
	);
	public static function Factory() {
		if(is_null(static::$__OWN_OBJECT))
			static::$__OWN_OBJECT = new self();
		return static::$__OWN_OBJECT;
	}
	protected function validateViewAccess(Router_Driver $Driver, Application_Controller $Controller, $View) {
		$ProtectedViews = array(
			'Render',
			'MergeAssignements',
			'__hasAccess'
		);
		if(!preg_match('/^([a-z0-9\_]{1,})$/i', $View->View))
			throw new Router_Exception('ACCESS_DENIED_INVALID_VIEW', array('View' => $View->View, 'Controller' => get_class($Controller)));

		if(substr($View->View, 2) == '__')
			throw new Router_Exception('ACCESS_DENIED_RESERVED_NAME', array('View' => $View->View, 'Controller' => get_class($Controller)));

		if(in_array($View->View, $ProtectedViews) || (method_exists($Controller, $View->View) && !is_callable(array($Controller, $View->View))))
			throw new Router_Exception('ACCESS_DENIED', array('View' => $View->View, 'Controller' => get_class($Controller)));
			
		if(method_exists($Controller, '__hasAccess') && $Controller->__hasAccess($Driver->getViewArguments()) !== true)
			throw new Router_Exception('ACCESS_DENIED_CONTROLLER_HOOK', array('View' => $View->View, 'Controller' => get_class($Controller)));

		return true;
	}
	public final function addHook($type, $id, $func) {
		$this->Hooks[$type][$id] = $func;
	}
	public final function deleteHook($type, $id) {
		unset($this->Hooks[$type][$id]);
	}
	public function Execute(Router_Driver $Driver, $PreviousController = null) {
		$controller = $Driver->getControllerInstance();
		$View = $Driver->getView();
		$ViewArguments = $Driver->getViewArguments();
		
		$this->validateViewAccess($Driver, $controller, $View);

		foreach($this->Hooks['onBeforeExecute'] AS $hookId => $hookFunction) {
			$hookFunction($Driver);
		}

		if(
			is_a($Driver, 'Router_Driver_InternalRedirect') &&
			is_object($PreviousController) &&
			is_a($PreviousController, 'Application_Controller')) {
			$controller->MergeAssignements($PreviousController);
		}
		$mView = $View->View;
		if($controller->useHTTPRequestMethodInViewName() && isset($_SERVER['REQUEST_METHOD']))
			$mView = strtoupper($_SERVER['REQUEST_METHOD']).'_'.$mView;

		if(method_exists($controller, $mView)) {
			if(method_exists($controller, '__before')) {
				$beforeResult = $controller->__before($Driver->getViewArguments());
				if(is_subclass_of($beforeResult, 'slc\\MVC\\Router_Driver')) {
					return $this->Execute($beforeResult, $controller);
				}
				unset($beforeResult);
			}

			$result = $controller->$mView($Driver->getViewArguments());

			if(method_exists($controller, '__after'))
				$controller->__after($Driver->getViewArguments());

			if(is_subclass_of($result, 'slc\\MVC\\Router_Driver')) {
				return $this->Execute($result, $controller);
			}
		}
		return $controller;
	}
}

class Router_Exception extends Application_Exception {
	const ACCESS_DENIED = 'access to view was denied';
	const ACCESS_DENIED_CONTROLLER_HOOK = 'access to view was denied by controller';
	const ACCESS_DENIED_RESERVED_NAME = 'access to view was denied, reserved name';
	const ACCESS_DENIED_INVALID_VIEW = 'access to view was denied, view doesn\'t match naming convention';
}

?>
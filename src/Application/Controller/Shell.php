<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 15.11.13
 * Time: 03:56
 */

namespace slc\MVC;

class Application_Controller_Shell extends Application_Controller {
	public function __before() {
		if(isset($_SERVER['HTTP_HOST']) || isset($_SERVER['HTTP_CONNECTION']) || isset($_SERVER['REMOTE_ADDR']))
			die('This controller is not available for remote browsers');
	}
	public function __after() {
		$this->setRenderEngine('\slc\MVC\RenderEngine_Shell');
	}
}

?>
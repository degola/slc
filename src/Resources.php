<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 26.10.13
 * Time: 00:36
 */

namespace slc\MVC;

class Resources {
	private static $ResourceInstances = array();
	public static function Factory($Type, $Id) {
		if(!isset(static::$ResourceInstances[$Type][$Id])) {
			Base::Factory()->importFile(array('Resources::'.$Type));
			$cls = 'slc\MVC\Resources\\'.$Type;
			static::$ResourceInstances[$Type][$Id] = new $cls($Id);
		}
		return static::$ResourceInstances[$Type][$Id];
	}
}

?>
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
	public static function Factory($Type, $Id, $logger = null)
    {
        if (!isset(static::$ResourceInstances[$Type][$Id])) {
            Base::Factory()->importFile(array('Resources::'.$Type));
			$cls = 'slc\MVC\Resources\\' . $Type;
            static::$ResourceInstances[$Type][$Id] = new $cls($Id);
        }
        if (method_exists(static::$ResourceInstances[$Type][$Id], 'setLogger')) {
            static::$ResourceInstances[$Type][$Id]->setLogger($logger);
        }
        return static::$ResourceInstances[$Type][$Id];
	}
}

?>
<?php
/**
 * Bootstrap file which should included from everywhere where an script entrypoint is, e.g. shell/exec.php, public/index.php, etc.
 *
 */

date_default_timezone_set('UTC');

error_reporting(E_ALL);
ini_set("display_errors", "on");
ini_set("display_startup_errors", "on");

if(!defined('BASE_PATH')) define('BASE_PATH', '../');

require BASE_PATH.'config/LOCAL_CONSTANTS.PHP';
require BASE_PATH.'config/ConfigurationParser.php';
require BASE_PATH.'vendor/autoload.php';

if(!defined('CONFIGURATION_SUB_PATH')) {
    if(isset($_SERVER['CONFIGURATION_SUB_PATH']))
        define('CONFIGURATION_SUB_PATH', $_SERVER['CONFIGURATION_SUB_PATH']);
    else
        die('constant and environment variable CONFIGURATION_SUB_PATH not set, please set environment variable or define the constant'."\n");
}

$Configuration = ConfigurationParser::Factory(BASE_PATH.'config/'.CONFIGURATION_SUB_PATH.'/Main.ini')->Run();

require_once $Configuration->Paths->Framework.'Base.php';

// initialize base class
\slc\MVC\Base::Factory($Configuration);

?>
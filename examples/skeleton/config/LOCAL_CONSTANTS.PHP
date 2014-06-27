<?php
/**
 * local constants should not be added to VCS but for example cases we have that here, each developer should
 * have it locally and should commit their configurations files, very helpful if something breaks on developer machine ;-)
 *
 * don't forgot, sensitive credentials and everything should be defined in this file as constants which are used as placeholders
 * configuration files
 *
 * we have also own configuration files for each developer even if we have local constants, this is required to avoid
 * conflicting changes during development process and we also may put stable and testing environment configurations to
 * the repository but we need some kind of configuration file synchronization script later on if required
 */

if(!defined('CONFIGURATION_SUB_PATH'))
	define('CONFIGURATION_SUB_PATH', 'Dev/developer1@company.com');

if(!defined('MYSQL_HOST'))
	define('MYSQL_HOST', 'localhost');
if(!defined('MYSQL_DATABASE'))
	define('MYSQL_DATABASE', 'mysql-database');
if(!defined('MYSQL_USER'))
	define('MYSQL_USER', 'mysql-user');
if(!defined('MYSQL_PASSWORD'))
	define('MYSQL_PASSWORD', 'mysql-password');

?>
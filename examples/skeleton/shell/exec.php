<?php

require_once '../app/Bootstrap.php';

$Router = \slc\MVC\Router::Factory();

// load gettext extension
/*
$Router->addHook('onBeforeExecute', 'Gettext', function (\slc\MVC\Router_Driver $Router) {
	$ViewArguments = $Router->getViewArguments();
	\slc\MVC\Gettext::Factory(isset($ViewArguments->Language)?$ViewArguments->Language:null);
});
*/

echo $Router->Execute(
	\slc\MVC\Router_Driver::Factory($argv, '\slc\MVC\Router_Driver_Shell')
)->Render()->Fetch();

?>
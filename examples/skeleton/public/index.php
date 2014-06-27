<?php

require_once '../app/Bootstrap.php';
$Router = \slc\MVC\Router::Factory();

/**
 * load gettext extension
 * @todo move that later to a more flexible way
 */
$Router->addHook('onBeforeExecute', 'Gettext', function (\slc\MVC\Router_Driver $Router) {
	$ViewArguments = $Router->getViewArguments();
	if(isset($ViewArguments->Language)) {
		if(!defined('LANGUAGE')) define('LANGUAGE', $ViewArguments->Language);

		\slc\MVC\Gettext::Factory($ViewArguments->Language);
		setcookie('Language', LANGUAGE, time() + 60 * 60 * 24 * 30, '/');
	} else 	if(isset($_COOKIE['Language'])) {
		if(!defined('LANGUAGE')) define('LANGUAGE', $_COOKIE['Language']);

		\slc\MVC\Gettext::Factory($_COOKIE['Language']);
	} else if(isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
		$language = 'en_US.UTF-8';
		$SupportLanguages = array(
			'en' => 'en_US.UTF-8',
			'es' => 'es_ES.UTF-8',
			'de' => 'de_DE.UTF-8',
		) ;
		$BrowserLanguages = explode(';', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
		foreach($BrowserLanguages AS $element) {
			if(isset($SupportLanguages[substr($element, 0, 2)])) {
				$language = $SupportLanguages[substr($element, 0, 2)];
				break;
			}
		}

		if(!defined('LANGUAGE')) define('LANGUAGE', $language);
		\slc\MVC\Gettext::Factory($language);
	} else {
		if(!defined('LANGUAGE')) define('LANGUAGE', 'en_US.UTF-8');

		\slc\MVC\Gettext::Factory(null);
	}

});

// render output
echo $Router->Execute(
	\slc\MVC\Router_Driver::Factory($_SERVER['REQUEST_URI'])
)->Render()->Fetch();

?>
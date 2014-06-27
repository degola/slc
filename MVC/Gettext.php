<?php

namespace slc\MVC;

class Gettext {
	protected static $Singleton = null;
	protected $Locale = null;
	public static function Factory($Locale) {
		return new self($Locale);
	}
	public function __construct($Locale = null) {
		if(is_null($Locale)) $Locale = Base::Factory()->getConfig('Application', 'DefaultLanguage');
		if(is_null($Locale)) $Locale = 'en_US.UTF-8';

		$this->Locale = $Locale;

		$this->validateExtension();

		\putenv(sprintf('LC_ALL=%1$s', $this->Locale));
		\setlocale(LC_ALL, $this->Locale);
		\bindtextdomain('messages', Base::Factory()->getConfig('Paths', 'Locales'));
		\textdomain('messages');

		static::$Singleton = $this;
	}
	protected function validateExtension() {
		if(!function_exists('gettext')) {
			$path = Base::Factory()->getConfig('Paths', 'Libraries').DIRECTORY_SEPARATOR.'php-gettext'.DIRECTORY_SEPARATOR.'gettext.inc';
			require $path;
			\T_setlocale(LC_ALL, $this->Locale);

		}

	}
	public function getLocalePath($domain = 'messages') {
		$path = Base::Factory()->getConfig('Paths', 'Locales');
		return $path.$this->Locale.'/'.$domain.'.po';
	}

	/**
	 * returns singleton instace
	 *
	 * @return \slc\MVC\Gettext
	 * @throws Gettext_Exception
	 */
	public static function Singleton() {
		if(is_null(static::$Singleton))
			throw new Gettext_Exception('NO_INSTANCE_AVAILABLE', array());
		return static::$Singleton;
	}
}

class Gettext_Exception extends Application_Exception {

}

?>
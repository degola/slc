<?php
/**
 * MVC base class
 *
 * User: Sebastian Lagemann <lagemann@spiritlabs.de>
 * Date: 23.02.13
 * Time: 01:34
 */

namespace slc\MVC;

class Base {
	static private $__OWN = null;
	/**
	 * @var \ConfigurationParser
	 */
	static private $Configuration = null;
	public function __construct($Configuration = null) {
		if(!is_null($Configuration)) {
			self::$Configuration = $Configuration;
			if(!defined('DEPLOYMENT_STATE'))
				define('DEPLOYMENT_STATE', ($this->getConfig('Application', 'DeploymentState')?$this->getConfig('Application', 'DeploymentState'):'stable'));
			$this->initializeAutoload();
		}
	}
	protected function initializeAutoload() {
		if($funcs = spl_autoload_functions() === false || !@in_array('Base::ClassLoader', $funcs))
			spl_autoload_register(__NAMESPACE__.'\Base::ClassLoader');
	}
	public static function Factory($Configuration = null) {
		if(is_null(self::$__OWN))
			self::$__OWN = new self($Configuration);
		return self::$__OWN;
	}
	static public function ClassLoader($class) {
		$Base = Base::Factory();

		if(preg_match('/\\\\/i', $class)) {
			// enable namespace handling
			$namespace = substr($class, 0, strrpos($class, '\\')); // $match[1];
			$classWithoutNamespace = substr($class, strrpos($class, '\\') + 1);

			$list = array(
				// check models
				'Model::'.str_replace('\\', '::', $namespace).'::'.str_replace('_', '::', $classWithoutNamespace),
				// check framework
				'Framework::'.str_replace('\\', '::', str_replace('slc\\MVC\\', '', $namespace)).'::'.str_replace('_', '::', $classWithoutNamespace),
				'Framework::'.str_replace(array('_', '\\'), array('::', '::'), $classWithoutNamespace),
			);

			if(preg_match('/_Exception$/', $class))
				$list[] = preg_replace('/_Exception$/i', '', $class);

			$Base->importFile($list, false);
		} else {
			$Base->importFile(array(
				'Model::'.str_replace('_', '::', $class),
				'Framework::'.str_replace('_', '::', $class),
				'Model::'.str_replace('_', '::', str_replace('_Exception', '', $class))
			), false);
		}
	}

	public function getConfig($type, $key = null) {
		return static::$Configuration->getConfig($type, $key);
	}

	public function getTempDir($prefix) {
		$tmpDir = $this->getConfig('Paths', 'TmpDir');
		if(substr($tmpDir, -1) != '/') {
			$tmpDir .= '/';
		}
		$tmpDir .= $prefix.'/';

		if(!file_exists($tmpDir)) {
			if(!@mkdir($tmpDir, 0777, true)) {
				throw new Base_Exception('ACCESS_DENIED_TEMP_DIRECTORY', array('TmpDir' => $tmpDir));
			}
		}
		if(!is_dir($tmpDir)) {
			throw new Base_Exception('TEMP_DIRECTORY_NOT_A_DIRECTORY', array('TmpDir' => $tmpDir));
		}
		return $tmpDir;
	}
	public function importFile($list, $force = true) {
		$loaded = false;

		foreach($list AS $file) {
			if(strpos($file, '::') !== false) {
				list($type, $file) = explode('/', str_replace('::', '/', $file), 2);
				$fn = $this->getConfig('Paths', $type).$file.$this->getConfig('FileExtensions', $type);

				if(file_exists($fn)) {
					require_once $fn;
					$loaded = true;
					return true;
				}
			}
		}
		if($force === true && $loaded === false) {
			throw new Base_Exception('FILE_NOT_FOUND', array($list));
		}
	}
	public static function import($list) {
		self::Factory($list);
	}
}

class Base_Exception extends \Exception {
	protected $exceptionDetails = array();
	protected $exceptionName = null;
	public function __construct($exceptionName, $details = null) {
		$this->exceptionName = $exceptionName;
		$code = static::EXCEPTION_BASE + constant('static::'.$exceptionName);
		$message = 'EXCEPTION_'.$exceptionName;
		if(!is_null($details)) {
			$this->exceptionDetails = $details;
			foreach($details AS $var => $value)
				$message .= "\n".$var.'='.(is_scalar($value)?$value:json_encode($value));
		}

		return parent::__construct($message, $code);
	}
	public function getExceptionCode() {
		return $this->getCode() - static::EXCEPTION_BASE;
	}
	public function getExceptionName() {
		return $this->exceptionName;
	}
	public function matchExceptionCode($expectedException) {
		return static::EXCEPTION_BASE + constant('static::'.$expectedException) === $this->getCode();
	}
	public function getDetailInformation($var) {
		if(isset($this->exceptionDetails[$var]))
			return $this->exceptionDetails[$var];
		return null;
	}
	public function getDetails() {
		return $this->exceptionDetails;
	}
}

?>
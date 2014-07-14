<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 14.11.13
 * Time: 22:00
 */

class ConfigurationParser {
	protected $Configuration =null;
	protected $file = null;
	public function __construct($file) {
		$this->file = $file;
	}
	public static function Factory($file) {
		return new self($file);
	}
	public function Run() {
		if(is_null($this->Configuration)) {
			if(!file_exists($this->file)) throw new Exception('invalid configuration file: '.$this->file);
			$ConfigurationContent = file_get_contents($this->file);
			$dir = dirname($this->file);
			if(substr($dir, -1) != '/') $dir .= '/';

			$ConfigurationContent = preg_replace_callback('/%%INCLUDE (.*?)$/im', function ($match) use ($dir) {
				if(file_exists($dir.$match[1]))
					return "\n".file_get_contents($dir.$match[1])."\n";
				if(file_exists($match[1]))
					return "\n".file_get_contents($match[1])."\n";
				throw new Exception('invalid included configuration file: '.$match[0]);
			}, $ConfigurationContent);
			$ConfigurationContent = preg_replace('/%%BASE_PATH/', BASE_PATH, $ConfigurationContent);
			$this->Configuration = $this->convert2object($this->parseTreeStructure(parse_ini_string($ConfigurationContent, true)));
		}
		return $this;
	}
	protected function parseTreeStructure($Configuration, $separator = '.') {
		ksort($Configuration);
		foreach($Configuration AS $key => $value) {
			if(strpos($key, $separator) !== false) {
				unset($Configuration[$key]);
				list($key, $rest) = explode($separator, $key, 2);
				$Configuration[$key] = array_merge_recursive(isset($Configuration[$key])?(array)$Configuration[$key]:array(), $this->parseTreeStructure(array($rest => $value), $separator));
			} elseif(is_array($value)) {
				$Configuration[$key] = $this->parseTreeStructure($value, $separator);
			}
		}
		return $Configuration;
	}
	protected function convert2object(array $data) {
		foreach($data AS $k => $v) {
			if(is_array($v))
				$data[$k] = $this->convert2object($v);
		}
		return (object)$data;
	}
	protected function replaceConstants($value) {
		if(is_scalar($value)) {
			if(defined('DEPLOYMENT_STATE')) {
				$value = preg_replace('/%%DEPLOYMENT_STATE/', DEPLOYMENT_STATE, $value);
			}
			if(preg_match_all('/%%CONSTANT_([A-Z0-9_]{1,})/', $value, $matches)) {
				foreach($matches[1] AS $constant) {
					if(defined($constant)) {
						$value = preg_replace('/%%CONSTANT_' . $constant . '/', $value, constant($constant));
					}
				}
			}
		} else {
			foreach($value AS $key => $rValue) {
				$value->$key = $this->replaceConstants($rValue);
			}
		}
		return $value;

	}
	public function getConfig($type, $key = null) {
		if(is_null($key) && isset($this->Configuration->{$type}))
			return $this->replaceConstants($this->Configuration->{$type});
		if(isset($this->Configuration->{$type}->{$key}))
			return $this->replaceConstants($this->Configuration->{$type}->{$key});
		return null;
	}

	public function __get($varname) {
		return $this->getConfig($varname);
	}
}

?>
<?php
/**
 * 
 *
 * User: degola
 * Date: 09.03.15
 * Time: 19:59
 */

namespace slc\MVC;

class Application_Password {
	/**
	 * password generation configuration (min values)
	 *
	 * @var array
	 */
	protected $PASSWORD_GENERATION_CONFIG = array(
		"numbers" => 2,
		"uppercase" => 2,
		"lowercase" => 2,
		"specialcharacters" => 0
	);

	/**
	 * @return Application_Password
	 */
	public static function Factory() {
		return new static();
	}

	/**
	 * sets password generation config
	 *
	 * @param int $numbers
	 * @param int $uppercase
	 * @param int $lowercase
	 * @param int $specialcharacters
	 */
	public function setPasswordGenerationConfig($numbers = 2, $uppercase = 2, $lowercase = 2, $specialcharacters = 0) {
		$this->PASSWORD_GENERATION_CONFIG = array(
			'numbers' => $numbers,
			'uppercase' => $uppercase,
			'lowercase' => $lowercase,
			'specialcharacters' => $specialcharacters
		);
	}

	/**
	 * returns an password based on the configured generation
	 *
	 * @param $length
	 * @return mixed|string
	 */
	public function generatePassword($length) {
		$list["lowercase"] = array("from" => 97, "till" => 122);
		$list["uppercase"] = array("from" => 65, "till" => 90);
		$list["numbers"] = array("from" => 48, "till" => 57);
		$list["specialcharacters"] = array("from" => 33, "till" => 47);

		srand(time());
		$seen = array();
		foreach($this->PASSWORD_GENERATION_CONFIG AS $key => $value) {
			$counter = 0;
			while($counter < $value) {
				$pos = rand(0, $length);
				if(!array_key_exists($pos, $seen) || !$seen[$pos]) {
					$poslist[$pos] = $key;
					$seen[$pos] = true;
					$counter++;
				}
			}
		}
		ksort($poslist);
		$string = "";
		$seenSigns = array();
		foreach($poslist AS $value) {
			$sign = rand($list[$value]["from"], $list[$value]["till"]);
			while(array_key_exists($sign, $seenSigns) && $seenSigns[$sign] === true) {
				$sign = rand($list[$value]["from"], $list[$value]["till"]);
			}
			$seenSigns[$sign] = true;
			$string .= chr($sign);
		}
		$changeList = array("0" => "h", "O" => "H", "I" => "f", "l" => "F", "1" => "T");
		foreach($changeList AS $key => $value) $string = str_replace($key, $value, $string);
		return $string;
	}
}

?>
<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 16.11.13
 * Time: 03:30
 */

namespace slc\MVC;

class UUID {

	public static function generate($typeId = 1) {
		$t=explode(" ",microtime());
		return sprintf( '%04x-%08s-%08s-%04s-%04x%04x',
			$typeId,
			static::clientIPToHex(),
			substr("00000000".dechex($t[1]),-8),   // get 8HEX of unixtime
			substr("0000".dechex(round($t[0]*65536)),-4), // get 4HEX of microtime
			mt_rand(0,0xffff), mt_rand(0,0xffff));
	}

	public static function uuidDecode($uuid) {
		$rez=Array();
		$u=explode("-",$uuid);
		if(is_array($u)&&count($u)==5) {
			$rez=(object)array(
				'typeId' => $u[0],
				'ip' => static::clientIPFromHex($u[1]),
				'unixtime' => hexdec($u[2]),
				'micro' => (hexdec($u[3])/65536)
			);
		}
		return $rez;
	}

	protected static function clientIPToHex($ip = null) {
		$hex = "";
		$ip = static::getIp();

		$part=explode('.', $ip);
		for ($i=0; $i<=count($part)-1; $i++) {
			$hex.=substr("0".dechex($part[$i]),-2);
		}
		return $hex;
	}
	protected static function getIp() {
		if(isset($_SERVER['HTTP_X_REAL_IP'])) {
			return $_SERVER['HTTP_X_REAL_IP'];
		}
		if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			return $_SERVER['HTTP_X_FORWARDED_FOR'];
		}
		if(isset($_SERVER['REMOTE_ADDR'])) {
			return $_SERVER['REMOTE_ADDR'];
		}
		return '0.0.0.0';
	}

	protected static function clientIPFromHex($hex) {
		$ip="";
		if(strlen($hex)==8) {
			$ip.=hexdec(substr($hex,0,2)).".";
			$ip.=hexdec(substr($hex,2,2)).".";
			$ip.=hexdec(substr($hex,4,2)).".";
			$ip.=hexdec(substr($hex,6,2));
		}
		return $ip;
	}
	public static function generateShort() {
		return sprintf('%03x-%03x%03x', mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand(0, 0xfff));
	}

}

?>
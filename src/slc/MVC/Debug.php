<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 30.06.13
 * Time: 02:21
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC;

class Debug {
	const DEBUG = true;
	const MESSAGE_NEWLINE_BEGIN = 1;
	const MESSAGE_NEWLINE_END = 2;

	/**
	 * internal debug variable to hold time usage statistics
	 *
	 * @var array
	 */
	protected static $TIME_USAGE = array();
	protected static $MEMORY_USAGE = array();

	public static $DEBUG_SHORT_MESSAGE_MAP = array(
		'F' => array("color" => "\033[5m\033[38;5;196m", "label" => "exception caught"),
		'f' => array("color" => "\033[5m\033[38;5;9m", "label" => "something failed"),
	);

	protected static $DEBUG_LAST_MSG_INCLUDES_ENDLINE = false;
	protected static $DEBUG_LAST_MSG_WAS_SHORT = false;
	protected static $MAX_PADDING = 20;

	protected static function isBrowserDebugEnabled($returnWithBrowserRequestDetection = false) {
		if(!$returnWithBrowserRequestDetection)
			return (defined('DEBUG_BROWSER_ENABLED') && DEBUG_BROWSER_ENABLED === true);
		return static::isBrowserDebugEnabled(false) && isset($_SERVER['HTTP_HOST']);
	}
	public static function Write($msg, $short, $newlines, $tablevel = 0, $sourceClass = __CLASS__, $onlyShort = false, $mapClass = '\slc\MVC\Debug') {
		if((!isset($_SERVER['HTTP_HOST']) || static::isBrowserDebugEnabled(false)) && !isset($_SERVER['CRON'])) {
			if($onlyShort === false && static::DEBUG === true && defined($sourceClass.'::DEBUG') && constant($sourceClass.'::DEBUG')) {
				$begin = 1;
				$end = 2;
				if(
					(!static::$DEBUG_LAST_MSG_INCLUDES_ENDLINE && ($begin & $newlines) == $begin)
				) {
					if(static::isBrowserDebugEnabled(true))
						echo "<pre class='debug_p'>";
					echo "\n";
				}

				if(static::$DEBUG_LAST_MSG_INCLUDES_ENDLINE && ($begin & $newlines) == $begin && static::isBrowserDebugEnabled(true))
					echo "<pre class='debug_p'>";

				if(static::$DEBUG_LAST_MSG_INCLUDES_ENDLINE || ($begin & $newlines) == $begin) {
					if(strlen($cnt = sprintf('[%1$s]', $sourceClass)) + 1 > static::$MAX_PADDING) static::$MAX_PADDING = strlen($cnt) + 1;
					$c = str_pad($cnt, static::$MAX_PADDING, " ", STR_PAD_RIGHT);
					if(static::isBrowserDebugEnabled(true))
						echo "<span style='color: #a00;'>".sprintf('[%1$s]', gmdate('Y-m-d H:i:s'))."</span>".str_replace(' ', '&nbsp;', $c)." ";
					else
						echo "\033[38;5;241m".sprintf('[%1$s]', gmdate('Y-m-d H:i:s'))."\033[1m\033[38;5;3m".$c."\033[1;0m ";
					unset($c);

					if($tablevel > 0) echo str_repeat("\t", $tablevel);
				}

				if(preg_match('/failed/', $msg)) {
					if(static::isBrowserDebugEnabled(true))
						echo "<span style='color: #f00;'>";
					else
						echo "\033[5m\033[38;5;9m";
				}
				if(preg_match('/done|ok/', $msg)) {
					if(static::isBrowserDebugEnabled(true))
						echo "<span style='color: #0a0;'>";
					else
						echo "\033[4m\033[38;5;10m";
				}

				if(static::isBrowserDebugEnabled(true))
					echo $msg.'</span>';
				else
					echo $msg."\033[0m";

				if(($end & $newlines) == $end) {
					if(static::isBrowserDebugEnabled(true))
						echo "</pre>";
					echo "\n";
					static::$DEBUG_LAST_MSG_INCLUDES_ENDLINE = true;
				} else
					static::$DEBUG_LAST_MSG_INCLUDES_ENDLINE = false;

				static::$DEBUG_LAST_MSG_WAS_SHORT = false;
			} else {
				if(!static::$DEBUG_LAST_MSG_INCLUDES_ENDLINE && !static::$DEBUG_LAST_MSG_WAS_SHORT) {
					if(static::isBrowserDebugEnabled(true))
						echo "</pre>";
					echo "\n";
				}

				if(!static::isBrowserDebugEnabled(true)) {
					if(isset($mapClass::$DEBUG_SHORT_MESSAGE_MAP[$short]))
						echo $mapClass::$DEBUG_SHORT_MESSAGE_MAP[$short]['color'];

					echo $short."\033[1;0m";
				}
				static::$DEBUG_LAST_MSG_WAS_SHORT = true;
				static::$DEBUG_LAST_MSG_INCLUDES_ENDLINE = false;
			}
			flush();
		}
	}


	public static function TimeUsageStart($class, $method, $key) {
		if(is_null($key)) $key = 'NULL';
		self::$TIME_USAGE[$class][$method][$key]['start'] = microtime(true);
		if(!key_exists('usage', self::$TIME_USAGE[$class][$method][$key]))
			self::$TIME_USAGE[$class][$method][$key]['usage'] = 0;
		if(!key_exists('count', self::$TIME_USAGE[$class][$method][$key]))
			self::$TIME_USAGE[$class][$method][$key]['count'] = 0;
	}
	public static function TimeUsageEnd($class, $method, $key) {
		if(is_null($key)) $key = 'NULL';

		$usage = microtime(true) - self::$TIME_USAGE[$class][$method][$key]['start'];
		self::$TIME_USAGE[$class][$method][$key]['usage'] += $usage;
		self::$TIME_USAGE[$class][$method][$key]['count']++;
		return $usage;
	}
	public static function TimeUsageGetStats() {
		return self::$TIME_USAGE;
	}

	public static function MemoryUsageStart($class, $method, $key) {
		if(is_null($key)) $key = 'NULL';

		self::$MEMORY_USAGE[$class][$method][$key]['start'] = memory_get_usage(true);
		if(!key_exists('usage', self::$MEMORY_USAGE[$class][$method][$key]))
			self::$MEMORY_USAGE[$class][$method][$key]['usage'] = 0;
		if(!key_exists('count', self::$MEMORY_USAGE[$class][$method][$key]))
			self::$MEMORY_USAGE[$class][$method][$key]['count'] = 0;
	}
	public static function MemoryUsageEnd($class, $method, $key) {
		if(is_null($key)) $key = 'NULL';

		self::$MEMORY_USAGE[$class][$method][$key]['usage'] += memory_get_usage(true) - self::$MEMORY_USAGE[$class][$method][$key]['start'];
		self::$MEMORY_USAGE[$class][$method][$key]['count']++;
	}
	public static function MemoryUsageGetStats() {
		return self::$MEMORY_USAGE;
	}

}

?>
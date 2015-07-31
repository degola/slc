<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 04.07.12
 * Time: 15:33
 * To change this template use File | Settings | File Templates.
 */

/*
require_once BASE_LIB.'Monolog/Handler/HandlerInterface.php';
require_once BASE_LIB.'Monolog/Handler/AbstractHandler.php';
require_once BASE_LIB.'Monolog/Handler/AbstractProcessingHandler.php';
require_once BASE_LIB.'Monolog/Handler/FirePHPHandler.php';
require_once BASE_LIB.'Monolog/Handler/ChromePHPHandler.php';
require_once BASE_LIB.'Monolog/Handler/GelfHandler.php';
require_once BASE_LIB.'Monolog/Formatter/FormatterInterface.php';
require_once BASE_LIB.'Monolog/Formatter/NormalizerFormatter.php';
require_once BASE_LIB.'Monolog/Formatter/WildfireFormatter.php';
require_once BASE_LIB.'Monolog/Formatter/ChromePHPFormatter.php';
require_once BASE_LIB.'Monolog/Formatter/GelfMessageFormatter.php';
require_once BASE_LIB.'Gelf/MessagePublisher.php';
*/

namespace slc\MVC;

use Bankiru\MonologLogstash\LumberjackFormatter;
use Bankiru\MonologLogstash\LumberjackHandler;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\StreamHandler;

class Logger extends \Monolog\Logger {
    protected static $LogObjects = array();
    protected $ConfigData = null;
    public function __construct($Facility)
    {
        parent::__construct(DEPLOYMENT_STATE.': '.$Facility);

		$tmp = Base::Factory()->getConfig('Log', DEPLOYMENT_STATE);
        if($tmp)
        	$this->ConfigData = $tmp->{$Facility}; // (object)Base::getConfigStatic('Log', DEPLOYMENT_STATE, 'Facility', $Facility);
        $this->initialize();
    }

    protected function initialize()
    {
        if(isset($this->ConfigData->Handler)) {
			foreach($this->ConfigData->Handler AS $Config) {
				$Config = (object) $Config;

                $noProcessors = false;
                if (isset($Config->NoProcessors) && $Config->NoProcessors) {
                    $noProcessors = true;
                }
                switch($Config->Type) {
					case 'File':
						$file_directory = dirname($Config->File);
                        if (!file_exists($file_directory)) {
							mkdir($file_directory, 0777, true);
						}
						$handler = new \Monolog\Handler\StreamHandler($Config->File, $Config->Severity);
                        break;
					case 'FirePHP':
						$handler = new \Monolog\Handler\FirePHPHandler();
						break;
					case 'ChromePHP':
						$handler = new \Monolog\Handler\ChromePHPHandler();
						break;
					case 'Logstash':
                        if(isset($Config->Host) && isset($Config->CertificatePath)) {
                            $handler = new LumberjackHandler(Logger::INFO, true);
                            $handler->init(
                                $Config->Host,
                                (isset($Config->Port) ? $Config->Port : 5000),
                                $Config->CertificatePath,
                                [
                                    'window_size' => 5000,
                                ]
                            );
                            $handler->setFormatter(
                                new LumberjackFormatter(
                                    Base::Factory()->getConfig('Application', 'Name')
                                )
                            );
                        } else {
                            throw new Logger_Exception('INVALID_TYPE_CONFIG', array('Type' => $Config->Type));
                        }
                        break;
					case 'Graylog':
						if(isset($Config->Host))
							$handler = new \Monolog\Handler\GelfHandler(new \Gelf\Publisher(new \Gelf\Transport\UdpTransport($Config->Host, isset($Config->Port)?$Config->Port:\Gelf\Transport\UdpTransport::DEFAULT_PORT)), constant('Monolog\Logger::'.$Config->Severity));
						else throw new Logger_Exception('INVALID_TYPE_CONFIG', array('Type' => $Config->Type));
						break;
					default:
						throw new Logger_Exception('INVALID_TYPE', array('Type' => $Config->Type));
				}

                $this->pushHandler($handler);
                if (!$noProcessors) {
                    $this->pushProcessor(new \Monolog\Processor\IntrospectionProcessor());
                    $this->pushProcessor(new \Monolog\Processor\MemoryUsageProcessor());
                    $this->pushProcessor(new \Monolog\Processor\WebProcessor());
                    $this->pushProcessor(new \Monolog\Processor\MemoryPeakUsageProcessor());
                }
            }
		}
    }

    /**
     * returns an Monolog\Logger object
     *
     * @static
     * @param $Facility name of the facility
     * @return Logger
     */
    public static function Factory($Facility)
    {
        if(!isset(static::$LogObjects[$Facility])) {
            $class = get_called_class();
            static::$LogObjects[$Facility] = new $class($Facility);
        }
        return static::$LogObjects[$Facility];
    }

    public function __call($method, $args) {
        if(preg_match('/^add/', $method))
            $this->Log->$method($args[0], isset($args[1])?$args[1]:null);

    }

}

class Logger_Exception extends Application_Exception {
    const EXCEPTION_BASE = 4300000;
    const INVALID_TYPE = 1;
    const INVALID_TYPE_CONFIG = 2;
}

?>
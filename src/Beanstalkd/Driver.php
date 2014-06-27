<?php

namespace slc\MVC\Beanstalkd;

/**
 *
 * User: Sebastian Lagemann <sl@honeytracks.com>
 * Date: 13.11.2012
 * Time: 15:40
 *
 * Small beanstalk driver class which allows fetching messages from the queue faster in high
 * latency environments through multiple connections and socket stream selects.
 */

class Driver {
	const DEBUG = false;
	protected $config = null;
	protected $BeanstalkConnection = null;
	protected $tube = null;

	public function __construct(array $config) {
		$this->config = (object) $config;

		if(!isset($this->config->Host))
			throw new Driver_Exception('CONFIGURATION_MISMATCH', array('Configuration' => $this->config));

		if (!isset($this->config->Port)) {
			$this->config->Port = 11300;
		}
		if (!isset($this->config->Connections)) {
			$this->config->Connections = 10;
		}
	}

	/**
	 * Fetches the Beanstalk connection if it exists and creates it otherwise.
	 * @return Driver_Connection
	 */
	protected function getConnection() {
		if (is_null($this->BeanstalkConnection)) {
			$this->BeanstalkConnection = new Driver_Connection(
				$this->config->Host,
				$this->config->Port,
				$this->config->Connections
			);
			if($this->tube) {
				$this->BeanstalkConnection->setOnReconnect(create_function(
						'$connection, $handler, $lastCommand',
						'try {'.
						'$connection->send(sprintf("watch %s", "'.$this->tube.'"), $handler);'.
						'$connection->send($lastCommand, $handler);'.
						'} catch(Exception $ex) {'.
						'die($ex->getTraceAsString);'.
						'}'
					)
				);
			}
		}
		return $this->BeanstalkConnection;
	}

	/**
	 * @param $tube - The tube where the messages will be fetched from.
	 * @return bool
	 */
	public function watch($tube) {
		$this->tube = $tube;
		$conn = $this->getConnection();
		if (static::DEBUG) {
			echo "watch ".$tube."\n";
		}
		$conn->send(sprintf('watch %s', $tube));
		$counter = 0;
		do {
			$package = $conn->receive();
			if(static::DEBUG) echo "receive\n";
			foreach ($package AS $packet) {
				if (preg_match('/WATCHING/', $packet->getData()) && $packet->getType() == 'watching status') {
					if(static::DEBUG) echo "watching\n";
					$counter++;
				}
			}
		} while ($counter < $this->config->Connections);
		return true;
	}

	public function publishMessage($message, $tube = 'DefaultTube', $priority = 0, $delay = 0, $timeToRun = 600) {
		$handlers = $this->getConnection()->useTube($tube, false);
		$this->getConnection()->addJob($message, $handlers, $priority, $delay, $timeToRun);
	}
	/**
	 * Starts
	 */
	public function startReserve() {
		$this->startReserveCalled = time();
		$this->getConnection()->send('reserve-with-timeout 10');
	}

	/**
	 * Fetches reserved messages and points out whenever a message has been deleted, released or
	 * timed out.
	 * @param $callback - If provided, it will be provided as an argument for each call of the
	 * Driver_Connection::receive() method. Is used in order to stop a beanstalkd
	 * consumer.
	 * @return Driver_Job[]|mixed
	 */
	public function fetchReserved($callback=null) {
		$return = array();
		$response = null;
		$conn = $this->getConnection();
		do {
			$package = $conn->receive($callback);
			if ($package == 'true') {
				return $package;
			}
			foreach($package AS $packet) {
				switch($packet->getType()) {
					case 'package':
						$return[] = $packet->getData();
						if (Driver::DEBUG) {
							echo ".";
						}
						break;
					case 'deleted':
						$conn->send('reserve-with-timeout 10', $packet->getHandler());
						if (Driver::DEBUG) {
							echo "d";
						}
						break;
					case 'released':
						$conn->send('reserve-with-timeout 10', $packet->getHandler());
						if (Driver::DEBUG) {
							echo "r";
						}
						break;
					case 'timeout':
						$conn->send('reserve-with-timeout 10', $packet->getHandler());
						if (Driver::DEBUG) {
							echo "t";
						}
						break;
					case 'not found':
						/**
						 * be careful with this, not found means that a delete operation failed and the job is still in
						 * the queue, ensure that timeout to run value is high enough during publishing the message
						 */
						$conn->send('reserve-with-timeout 10', $packet->getHandler());
						if(Driver::DEBUG)
							echo "N";
						break;
				}
			}
		} while(sizeof($return) == 0);
		return $return;
	}

	/**
	 * returns statistics from beanstalkd
	 *
	 * @return Driver_StatsResult
	 * @throws Driver_StatsResultException
	 */
	public function getStats() {
		return $this->getConnection()->getStats();
	}

	/**
	 * returns tube statistics from beanstalkd
	 *
	 * @param $tube name of tube
	 * @return Driver_StatsTubeResult
	 * @throws Driver_StatsResultException
	 */
	public function getStatsTube($tube) {
		return $this->getConnection()->getStatsTube($tube);
	}

	/**
	 * disconnects get connection
	 *
	 * @param $forceHarshDisconnect tells the disconnect method to not wait for received data and to kill the connection
	 * immediately otherwise it could happen that we hang in an endless loop
	 * @return bool
	 */
	public function disconnect($forceHarshDisconnect = false) {
		$conn = $this->getConnection();
		$remainingConnections = 1;
		if($forceHarshDisconnect === false) {
			do {
				$package = $conn->receive();
				foreach($package AS $packet) {
					$remainingConnections = $conn->disconnect($packet->getHandler());
				}
			} while($remainingConnections > 0);
		} else {
			$conn->disconnect();
		}
		$this->shutdown = true;
	}
}

/**
 * Handles all connections to the beanstalk server.
 */
class Driver_Connection {
	const DEBUG = false;
	const CRLF  = "\r\n";
	protected $host;
	protected $port         = 11300;
	protected $handlers     = array();
	protected $lastCommands = array();
	protected $onReconnect  = null;

	public function __construct($host, $port=11300, $connections=10) {
		$this->host = $host;
		$this->port = $port;
		for($i = 0; $i < $connections; $i++) {
			if(Driver_Connection::DEBUG) {
				echo "open connection ".($i)."...";
			}
			$this->connect($i);
			if(Driver_Connection::DEBUG) {
				echo "done.\n";
			}
		}
	}

	/**
	 * Sets the onReconnect property.
	 * @param $method - The method which will be called whenever a connection cannot be established.
	 */
	public function setOnReconnect($method) {
		$this->onReconnect = $method;
	}

	/**
	 * Opens a new socket connection with the given ID, if it does not already exist.
	 * @param $id - The ID which will correspond to the newly opened socket connection.
	 * @param int $reconnectCounter - The number of connections calls that have failed.
	 * @return bool
	 * @throws Driver_Exception - If more than 3 connection calls failed or
	 * if either the host or the port are not defined..
	 */
	protected function connect($id, $reconnectCounter=0) {
		if (!$this->isConnected($id)) {
			if (!isset($this->host) || !isset($this->port)) {
				throw new Driver_Exception('CONFIGURATION_MISMATCH', array(
					'host' => isset($this->host) ? $this->host : 'undefined',
					'port' => isset($this->port) ? $this->port : 'undefined',
				));
			}
			$this->handlers[$id] = @fsockopen($this->host, $this->port, $errorNumber, $errorString, 5);
			if($this->isConnected($id)) {
				stream_set_blocking($this->handlers[$id], 0);
			} else {
				if ($reconnectCounter > 3) {
					throw new Driver_Exception('CONNECTION_FAILED', array(
						'host' => $this->host,
						'port' => $this->port,
					));
				}
				usleep(100000);
				$this->connect($id, ++$reconnectCounter);
			}
		}
		return true;
	}

	/**
	 * Checks whether the given ID corresponds to an already open socket connection.
	 * @param $id
	 * @return bool
	 */
	protected function isConnected($id) {
		if (!isset($this->handlers[$id]) || !$this->handlers[$id] || feof($this->handlers[$id])) {
			return false;
		}
		return true;
	}

	/**
	 * Sends a message to either all of the handlers or just a subset.
	 * @param $string - The message that will be sent.
	 * @param null $handlers
	 */
	public function send($string, $handlers=null) {
		if (is_null($handlers)) {
			$handlers = $this->handlers;
		}
		foreach($handlers AS $i => $handler) {
			if(Driver_Connection::DEBUG) {
				echo "sending to connection ".($i)." (".$string.")...";
				$s = microtime(true);
			}
			// check connection first to avoid connection issues
			if (!$this->isConnected($i) || feof($handler)) {
				$this->connect($i);
			}

			@stream_set_blocking($handler, 1);

			if(!fputs($handler, $string.static::CRLF))
				throw new Driver_Connection_Exception('WRITE_FAILED', array('String' => $string));

			fflush($handler);
			@stream_set_blocking($handler, 0);

			$this->lastCommands[$i] = $string;
			if (Driver_Connection::DEBUG) {
				echo "done. (".number_format(microtime(true) - $s, 5)."s)\n";
			}
		}
	}

	/**
	 * Wait for (and then return) packages from all of the currently open socket connections.
	 * @param $callback - If provided, it will be called at the start of each loop. Whenever
	 * the callback provides a return value, the execution of fetchReserved is stopped, so you
	 * need to be careful with this.
	 * @return BeanstalkConnection_Packet[]
	 */
	public function receive($callback=null, $handlers = null, $expectedType = null) {
		if(is_null($handlers)) $handlers = $this->handlers;
		$result = array();
		$fetchTries = array();
		$null = null;
		do {
			if (isset($callback)) {
				$response = call_user_func($callback);
			}
			if (isset($response)) {
				return $response;
			}
			foreach ($handlers AS $connId => $handler) {
				if (!is_bool($handler) && $this->isConnected($connId) && !feof($handler)) {
					if ($line = fgets($handler, 8192)) {
						if (Driver_Connection::DEBUG) {
							echo "read data from connection ".$connId."...";
							$s = microtime(true);
						}
						$result[] = new Driver_Packet(
							$this,
							array($connId => $handler),
							$line,
							$expectedType
						);
						if (Driver_Connection::DEBUG) {
							echo "done. (".number_format(microtime(true) - $s, 5)."s)\n";
						}
					} else {
						//track the number of fetch tries to do a reconnect if it
						//is exceeding
						if (!isset($fetchTries[$connId])) {
							$fetchTries[$connId] = 1;
						} else {
							$fetchTries[$connId]++;
						}
					}
				} else {
					$this->connect($connId);
					if($this->onReconnect) {
						$func = $this->onReconnect;
						$func($this, array($connId => $this->handlers[$connId]), $this->lastCommands[$connId]);
					}
//                    $this->send($this->lastCommands[$connId], array($connId => $handler));
				}
			}
			if(($retSize = sizeof($result)) == 0) {
				usleep(10000);
			}
		} while($retSize == 0);
		return $result;
	}

	/**
	 * defines the tube which should be used
	 *
	 * @param $tube
	 * @param bool $allHandlers
	 * @return array
	 * @throws Driver_Exception
	 */
	public function useTube($tube, $allHandlers = true) {
		if(!$this->validateTubeName($tube))
			throw new Driver_Exception('INVALID_TUBE_NAME', array('Tube' => $tube));

		if($allHandlers === true)
			$handlers = $this->handlers;
		else {
			$handlers = array(
				$this->handlers[array_rand($this->handlers)]
			);
		}
		$this->send(
			sprintf('use %s', $tube),
			$handlers
		);
		$result = $this->receive(null, $handlers, 'using');
		return $handlers;
	}

	/**
	 * adds a single job to beanstalkd
	 *
	 * @param $jobData
	 * @param array $handlers
	 * @param int $priority
	 * @param int $delay
	 * @param int $timeToRun
	 */
	public function addJob($jobData, array $handlers = null, $priority = 0, $delay = 0, $timeToRun = 600) {
		if(is_null($handlers)) $handlers = $this->handlers;

		$jobData = json_encode($jobData);
		$message = sprintf('put %d %d %d %d', $priority, $delay, $timeToRun, strlen($jobData));
		$this->send($message, $handlers);
		$this->send($jobData, $handlers);
		$result = $this->receive(null, $handlers, 'inserted');
	}

	/**
	 * returns statistics from beanstalkd
	 *
	 * @return Driver_StatsResult
	 * @throws Driver_StatsResultException
	 */
	public function getStats() {
		$handler = array($this->handlers[array_rand($this->handlers)]);
		$this->send('stats', $handler);
		$result = $this->receive(null, $handler, 'stats');
		return array_shift($result)->getData();
	}

	/**
	 * returns tube statistics from beanstalkd
	 *
	 * @param $tube name of tube
	 * @return Driver_StatsTubeResult
	 * @throws Driver_StatsResultException
	 * @throws Driver_Exception
	 */
	public function getStatsTube($tube) {
		if(!$this->validateTubeName($tube))
			throw new Driver_Exception('INVALID_TUBE_NAME', array('Tube' => $tube));

		$handler = array($this->handlers[array_rand($this->handlers)]);
		$this->send(sprintf('stats-tube %s', $tube), $handler);
		$tmp = $this->receive(null, $handler, 'stats-tube');
		$result = array_shift($tmp);
		unset($tmp);
		switch($result->getType()) {
			case 'stats-tube':
				return $result->getData();
				break;
			case 'not found':
				return null;
				break;
			case 'bad format':
			default:
				throw new Driver_Exception('INVALID_STATS_RESULT', array('Type' => $result->getType(), 'Data' => $result->getData()));
		}
	}

	/**
	 * returns if the given tube name is valid or not
	 *
	 * @param $tube
	 * @return bool
	 */
	protected function validateTubeName($tube) {
		return (bool)preg_match('/^([a-z0-9+;\$\/\.\(\)]{1})([a-z0-9+;\$\/\-\_\.\(\)]{3,199})$/i', $tube);
	}

	public function disconnect(array $handlers = array()) {
		if(sizeof($handlers) == 0) $handlers = $this->handlers;
		try {
			$this->send('quit', $handlers);
		} catch(Driver_Connection_Exception $ex) {
			if($ex->getCode() !== 50002001)
				throw $ex;
		}
		$this->close($handlers);
		return sizeof($this->handlers);
	}
	protected function close(array $handlers = array()) {
		if(sizeof($handlers) == 0) $handlers = $this->handlers;
		foreach($handlers AS $handlerId => $handler) {
			@fclose($handler);
			unset($this->handlers[$handlerId]);
		}
	}
}

/**
 * Packets which were received from the receive method.
 */
class Driver_Packet {
	protected $beanstalkConnection;
	protected $handler;
	protected $header;
	protected $type;
	protected $data;
	protected $expectedType = null;
	public function __construct(Driver_Connection $beanstalkConnection, $handler, $header, $expectedType = null) {
		$this->beanstalkConnection = $beanstalkConnection;
		$this->handler = $handler;
		$this->header = explode(' ', trim($header));
		$this->expectedType = $expectedType;
		$this->parse();
	}

	/**
	 * Parses the packets received by the receive method depending on the their header:
	 * 'RESERVED' packets are read from the socket connection, 'DELETED', 'TIMED_OUT', 'RELEASED'
	 * and 'WATCHED' packets are just marked accordingly.
	 */
	protected function parse() {
		list($handlerId) = array_keys($this->handler);
		$handler = $this->handler[$handlerId];
		switch($this->header[0]) {
			case 'RESERVED':
				if (Driver_Connection::DEBUG) {
					echo "reading data for reserved packet...";
				}
				$jobId = $this->header[1];
				$size = $this->header[2];
				$data = '';
				$startReadTime = time();

				if(Driver_Connection::DEBUG)
					echo "jobId ".$jobId.", size ".$size."...";

				do {
					$data .= fread($handler, $size);
					$data .= fread($handler, strlen(Driver_Connection::CRLF));
					if(Driver_Connection::DEBUG)
						echo "read (".strlen($data).")...";
				} while(substr($data, strlen(Driver_Connection::CRLF) * -1) != Driver_Connection::CRLF && (time() - $startReadTime) < 20);
				if((time() - $startReadTime) > 19) {
					echo "\nproblem detected while reading reserved data, after 20 seconds no CRLF, stopped reading for jobId $jobId.\n";
				}
				if (Driver_Connection::DEBUG)  {
					echo "done: ".$jobId.' => '.number_format($size, 0, '.', '.')."b\n";
				}
				$this->type = 'package';
				$this->data = new Driver_Job(
					$this->beanstalkConnection,
					$this->handler,
					$jobId,
					$data,
					$size
				);
				break;
			case 'OK':
				$this->type = $this->expectedType;
				$size = $this->header[1];
				$data = '';
				do {
					$data .= fread($handler, $size);
					$data .= fread($handler, strlen(Driver_Connection::CRLF));
				} while(substr($data, strlen(Driver_Connection::CRLF) * -1) != Driver_Connection::CRLF);

				switch($this->expectedType) {
					case 'stats':
						$this->data = new Driver_StatsResult($data);
						break;
					case 'stats-tube':
						$this->data = new Driver_StatsTubeResult($data);
						break;
				}
				break;
			case 'DELETED':
				$this->type = 'deleted';
				break;
			case 'TIMED_OUT':
				$this->type = 'timeout';
				break;
			case 'RELEASED':
				$this->type = 'released';
				break;
			case 'WATCHING':
				$this->type = 'watching status';
				$this->data = implode(' ', $this->header);
				break;
			case 'NOT_FOUND':
				$this->type = 'not found';
				break;
			case 'INSERTED':
				$this->type = 'inserted';
				break;
			case 'BAD_FORMAT':
				throw new Driver_Packet_Exception('BAD_FORMAT', array(
					'Header' => $this->header
				));
				break;
			default:
				if(Driver_Connection::DEBUG)
					echo "unknown result: ".print_r($this->header, true)."\n";
				$this->data = implode(' ', $this->header);
		}
	}

	/**
	 * Fetches the packet's data.
	 *
	 * @return mixed
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * Fetches the packet's type.
	 *
	 * @return mixed
	 */
	public function getType() {
		return $this->type;
	}

	/**
	 * Fetches the packet's handler.
	 *
	 * @return mixed
	 */
	public function getHandler() {
		return $this->handler;
	}
}

/**
 * A single beanstalk job, fetched with reserve.
 */
class Driver_Job {
	protected $BeanstalkConnection;
	protected $handler;
	protected $jobId;
	protected $data;
	protected $size;

	public function __construct($BeanstalkConnection, $handler, $jobId, $data, $size) {
		$this->BeanstalkConnection = $BeanstalkConnection;
		$this->handler             = $handler;
		$this->jobId               = $jobId;
		$this->data                = $data;
		$this->size                = $size;
	}

	/**
	 * Fetches the $data property.
	 * @return mixed
	 */
	public function getData() {
		return $this->data;
	}

	public function getJobId() {
		return $this->jobId;
	}

	/**
	 * Deletes a job.
	 */
	public function delete() {
		$this->BeanstalkConnection->send(sprintf('delete %s', $this->jobId), $this->handler);
		if(Driver::DEBUG) echo "D";
	}

	/**
	 * Releases a job.
	 * @param $delay
	 * @param $priority
	 */
	public function release($delay=1, $priority=1000) {
		$this->BeanstalkConnection->send(sprintf('release %s %d %d', $this->jobId, $priority, $delay), $this->handler);
	}
}

/**
 * Class Driver_Stats
 *
 * parses stats results from beanstalkd
 */
class Driver_Stats {
	protected $data;
	public function __construct($data) {
		$this->data = $this->parseYaml($data);
	}
	protected function parseYaml($data) {
		$retArray = array();
		foreach(explode("\n", $data) AS $line) {
			if(strpos($line, ':') !== false) {
				list($field, $value) = explode(":", $line, 2);
				$retArray[$field] = trim($value);
			}
		}
		return $retArray;
	}
	protected function validateData($expectedKeys, array $data) {
		if(sizeof(array_diff($expectedKeys, array_keys($data))) > 0) {
			return false;
		}
		return true;
	}
	public function get($varname) {
		if(isset($this->data[$varname]))
			return $this->data[$varname];
		return null;
	}

}

/**
 * Class Driver_StatsResult
 *
 * allows access to stats command to beanstalkd
 */
class Driver_StatsResult extends Driver_Stats {
	public function __construct($data) {
		parent::__construct($data);
		if(!$this->validateData(array(
			'current-jobs-urgent',
			'current-jobs-ready',
			'current-jobs-reserved',
			'current-jobs-delayed',
			'current-jobs-buried',
			'cmd-put',
			'cmd-peek',
			'cmd-peek-ready',
			'cmd-peek-delayed',
			'cmd-peek-buried',
			'cmd-reserve',
			'cmd-reserve-with-timeout',
			'cmd-delete',
			'cmd-release',
			'cmd-use',
			'cmd-watch',
			'cmd-ignore',
			'cmd-bury',
			'cmd-kick',
			'cmd-touch',
			'cmd-stats',
			'cmd-stats-job',
			'cmd-stats-tube',
			'cmd-list-tubes',
			'cmd-list-tube-used',
			'cmd-list-tubes-watched',
			'cmd-pause-tube',
			'job-timeouts',
			'total-jobs',
			'max-job-size',
			'current-tubes',
			'current-connections',
			'current-producers',
			'current-workers',
			'current-waiting',
			'total-connections',
			'pid',
			'version',
			'rusage-utime',
			'rusage-stime',
			'uptime',
			'binlog-oldest-index',
			'binlog-current-index',
			'binlog-max-size',
		), $this->data)) throw new Driver_StatsResultException('INVALID_FORMAT', array('Data' => $data));
	}
}

/**
 * Class Driver_StatsTubeResult
 *
 * allows access to stats-tube command to beanstalkd
 */
class Driver_StatsTubeResult extends Driver_Stats {
	public function __construct($data) {
		parent::__construct($data);
		if(!$this->validateData(array(
			'name',
			'current-jobs-urgent',
			'current-jobs-ready',
			'current-jobs-reserved',
			'current-jobs-delayed',
			'current-jobs-buried',
			'total-jobs',
			'current-using',
			'current-watching',
			'current-waiting',
			'cmd-delete',
			'cmd-pause-tube',
			'pause',
			'pause-time-left',
		), $this->data)) throw new Driver_StatsResultException('INVALID_FORMAT', array('Data' => $data));
	}
}

class Driver_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 50000000;
	const CONNECTION_FAILED = 10;
	const CONFIGURATION_MISMATCH = 1;
	const INVALID_STATS_RESULT = 2;
	const INVALID_TUBE_NAME = 3;
}
class Driver_StatsResultException extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 50001000;
	const INVALID_FORMAT = 1;
}
class Driver_Connection_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 50002000;
	const WRITE_FAILED = 1;
}
class Driver_Packet_Exception extends \slc\MVC\Application_Exception {
	const EXCEPTION_BASE = 50003000;
	const BAD_FORMAT = 1;
}
?>

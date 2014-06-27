<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 13.09.13
 * Time: 21:48
 * To change this template use File | Settings | File Templates.
 */

namespace slc\MVC;

class Socket_Server extends Base {
	const DEBUG = true;
	protected $address = null;
	protected $port = null;
	protected $socket = null;
	protected $Clients = array();

	public function __construct($port, $address = null) {
		$this->address = !is_null($address)?$address:'0.0.0.0';
		$this->port = $port;
		$this->createSocket();
	}
	public function __destruct() {
		$this->Close();
	}
	public function Close() {

		if(sizeof($this->Clients) > 0) {
			foreach($this->Clients AS $key => $socket) {
				socket_set_block($socket);
				socket_shutdown($socket, 1);
				usleep(500);
				socket_close($socket);
				unset($this->Clients[$key]);
			}
		}
		if(!is_null($this->socket)) {
			socket_set_block($this->socket);
			usleep(500);
			socket_close($this->socket);
			$this->socket = null;
		}
	}
	protected function createSocket() {
		Debug::Write('listening to '.$this->address.':'.$this->port.'...', null, Debug::MESSAGE_NEWLINE_BEGIN, 0, __CLASS__);

		$this->socket = socket_create(AF_INET, SOCK_STREAM, 0);
		socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

		socket_bind($this->socket, $this->address, $this->port) or $this->throwException('CANT_BIND_SOCKET', array('Address' => $this->address, 'Port' => $this->port));
		socket_listen($this->socket);

		$arrOpt = array('l_onoff' => 1, 'l_linger' => 1);
		socket_set_option($this->socket, SOL_SOCKET, SO_LINGER, $arrOpt);

		socket_set_nonblock($this->socket);

		Debug::Write('done.', null, Debug::MESSAGE_NEWLINE_END, 0, __CLASS__);
	}
	protected function throwException($exception, $args) {
		throw new Socket_Server_Exception($exception, $args);
	}
	public function dispatch($dispatchMethod) {
		$client = @socket_accept($this->socket);
		if($client > 0) {
			socket_set_nonblock($client);
			$this->Clients[] = $client;
		} else {
			$lastError = socket_last_error($this->socket);
			unset($lastError);
		}

		if(sizeof($this->Clients) > 0) {
			foreach($this->Clients AS $socketId => $socket) {
				$read = socket_read($socket, 1024);
				if($read) {
					call_user_func($dispatchMethod, $socketId, $socket, $read);
				}
			}
		}

	}
	public function disconnectClient($socketId) {
		socket_set_nonblock($this->Clients[$socketId]);
		socket_shutdown($this->Clients[$socketId], 2);
		usleep(500);
		socket_close($this->Clients[$socketId]);
		unset($this->Clients[$socketId]);
	}
	public function send($message, $socketId = null) {
		if(is_null($socketId)) {
			foreach($this->Clients AS $socket) {
				socket_write($socket, $message, strlen($message));
			}
		} else
			socket_write($this->Clients[$socketId], $message, strlen($message));
	}
}

class Socket_Server_Exception extends Application_Exception {
	const EXCEPTION_BASE = 21120000;
	const CANT_BIND_SOCKET = 1;
}

?>
<?php

namespace slc\MVC;

/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 16.01.14
 * Time: 02:08
 * To change this template use File | Settings | File Templates.
 */

class Lock_Manager {
	const DEBUG = false;

	/**
	 * the type of locks
	 *
	 * @var string
	 */
	protected $LockType = null;

	/**
	 * the connection configuration id
	 *
	 * @var string
	 */
	protected $ConnectionConfigId = null;

	/**
	 * the memcache connection (could be replaced with another service later)
	 *
	 * @var Memcached
	 */
	protected $Connection = null;

	/**
	 * contains a list of all acquired locks
	 *
	 * @var array
	 */
	protected $Locks = array();

	/**
	 * instances of lock manager based on the lock type
	 *
	 * @var array
	 */
	protected static $Instances = array();

	/**
	 * @param bool $LockType
	 * @param bool $ConnectionConfigId
	 */
	protected function __construct($LockType, \Memcached $Connection = null) {
		$this->LockType = $LockType;

		if(is_null($Connection))
			$this->Connection = Resources::Factory('Memcached', 'default');
		else
			$this->Connection = $Connection;
	}

	/**
	 * removes all locks after destruction
	 */
	public function __destruct() {
		if($this->DeleteAll('destructor') === false)
			throw new Lock_Manager_Exception('REMAINING_LOCKS_AFTER_DELETE_ALL', array($this->Locks));
	}

	/**
	 * returns an instance of Lock_Manager for the given LockType, it's not possible to work with private instances
	 *
	 * @param $LockType
	 * @param null $ConnectionConfigId
	 * @return Lock_Manager
	 */
	public static function Factory($LockType, \Memcached $Connection = null) {
		if(!isset(static::$Instances[$LockType]))
			static::$Instances[$LockType] = new self($LockType, $Connection);
		return static::$Instances[$LockType];
	}

	/**
	 * creates the lock for the given time in seconds and returns an (stdClass)object with the lock informations if it
	 * was successful
	 *
	 * @param $LockId
	 * @param int $Duration
	 * @return bool|object
	 * @throws Lock_Manager_Exception
	 */
	public function CreateLock($LockId, $AutoDelete = true, $Duration = 86400, $Tries = 1) {
		if(isset($this->Locks[$LockId]))
			throw new Lock_Manager_Exception('LOCK_ALREADY_EXISTS', array('LockId' => $LockId, 'LockType' => $this->LockType, 'LockDuration' => $Duration));

		$Lock = (object)array(
			'Id' => $LockId,
			'Type' => $this->LockType
		);
		$Lock->Hash = $this->getLockHash($LockId);
		$Lock->AutoDelete = $AutoDelete;
		$Lock->InsertDate = time();
		$Lock->AvailableUntil = time() + $Duration;

		if($this->acquireLock($Lock, $Tries))
			return $Lock;

		return false;
	}

	/**
	 * returns the hash for a single lock
	 *
	 * @param $LockId
	 * @param null $Lock
	 * @return string
	 */
	public function getLockHash($LockId, $Lock = null) {
		if(is_null($Lock)) {
			$Lock = (object)array(
				'Id' => $LockId,
				'Type' => $this->LockType
			);
		}
		return sha1(json_encode($Lock));
	}

	/**
	 * acquires the lock, if it fails it tries to acquire the lock the given amount of tries, afterwards it returns false
	 *
	 * @param $Lock
	 * @param int $Tries
	 * @param int $iteration
	 * @return bool
	 */
	protected function acquireLock($Lock, $Tries = 100, $iteration = 0) {
		if($this->Connection->add(
				'LOCK_MANAGER::'.$Lock->Hash,
				json_encode($Lock),
				$Lock->AvailableUntil - time()
			) === true) {
			$this->Locks[$this->LockType.'::'.$Lock->Id] = $Lock;
			return true;
		}
		if($iteration <= 10)
			return $this->acquireLock($Lock, $Tries, ++$iteration);
		else if($iteration < $Tries) {
			usleep(5000);
			return $this->acquireLock($Lock, $Tries, ++$iteration);
		}
		return false;
	}

	/**
	 * deletes given lock id, the method tries to delete the lock, returns true for successful deleted lock, otherwise it throws an exception or returns (bool)false
	 *
	 * @param $LockId
	 * @param bool $ThrowExceptionOnError
	 * @return bool
	 * @throws Lock_Manager_Exception
	 */
	public function DeleteLock($LockId, $ThrowExceptionOnError = true) {
		$result = false;
		if(isset($this->Locks[$this->LockType.'::'.$LockId])) {
			$Lock = $this->Locks[$this->LockType.'::'.$LockId];


			$result = $this->Connection->delete('LOCK_MANAGER::'.$Lock->Hash);
			if($ThrowExceptionOnError === false || $result === true) {
				unset($this->Locks[$this->LockType.'::'.$Lock->Id]);
			} else
				throw new Lock_Manager_Exception('LOCK_NOT_DELETED', array('LockId', $LockId, 'LockType' => $this->LockType));
		} else if($ThrowExceptionOnError === true)
			throw new Lock_Manager_Exception('LOCK_NOT_CREATED_IN_INSTANCE', array('LockId' => $LockId, 'LockType' => $this->LockType));

		return $result;
	}

	/**
	 * removes all remaining locks for the current lock type if they were set to AutoDelete = true
	 *
	 * @return bool
	 */
	public function DeleteAll($origin = null) {
		$allDeleted = true;
		foreach($this->Locks AS $Lock) {
			if($origin != 'destructor' || $Lock->AutoDelete === true) {
				if(!$this->DeleteLock($Lock->Id, false))
					$allDeleted = false;
			}
		}
		return $allDeleted;
	}
}

class Lock_Manager_Exception extends Application_Exception {
	const EXCEPTION_BASE = 5100000;
	const LOCK_ALREADY_EXISTS = 1;
	const LOCK_NOT_DELETED = 2;
	const LOCK_NOT_CREATED_IN_INSTANCE = 3;
	const REMAINING_LOCKS_AFTER_DELETE_ALL = 4;
}

?>
<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 29.11.13
 * Time: 00:30
 */

namespace slc\MVC\Storage;

use slc\MVC\Resources;

class Engine_Driver_Redis extends Engine_Base {
	public function __construct($StorageEngine = null) {
		if(!is_null($StorageEngine))
			parent::__construct($StorageEngine);
		else
			parent::__construct(Resources::Factory('Redis', 'default'));
	}
	public function get($objectId) {
		$result = $this->getStorageEngine()->get($objectId);
		return $result?unserialize($result):false;
	}
	public function getMulti(array $objectIds = array()) {
		// redis mget returns not the keys, it just returns the values in the same order as the keys were requested...
		$keys = array_values($objectIds);
		$result = $this->getStorageEngine()->mget($keys);
		if($result) {
			$newResult = array();
			foreach($result AS $key => $value) {
				$newResult[$keys[$key]] = unserialize($value);
			}
			return $newResult;
		}
		return false;
	}

	public function set($objectId, $value) {
		$this->getStorageEngine()->set($objectId, serialize($value));
	}
	public function setMulti(array $values = array()) {
		throw new Engine_Base_Exception('NOT_IMPLEMENTED', array('Values' => $values));
	}

	public function create($objectId, $value) {
		if(!$this->getStorageEngine()->exists($objectId))
			return $this->getStorageEngine()->set($objectId, serialize($value));
		throw new Engine_Driver_Redis_Exception('OBJECT_ID_ALREADY_EXISTS', array('ObjectId' => $objectId, 'Value' => $value));
	}
	public function delete($objectId) {
		$this->getStorageEngine()->del($objectId);
	}

}

class Engine_Driver_Redis_Exception extends Engine_Driver_Exception {

}


?>
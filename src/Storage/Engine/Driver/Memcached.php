<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 29.11.13
 * Time: 00:30
 */

namespace slc\MVC\Storage;

use slc\MVC\Resources;

class Engine_Driver_Memcached extends Engine_Base {
	public function __construct($StorageEngine = null) {
		if(!is_null($StorageEngine))
			parent::__construct($StorageEngine);
		else
			parent::__construct(Resources::Factory('Memcached', 'default'));
	}
	public function get($objectId) {
		$result = $this->getStorageEngine()->get($objectId);
		return $result?$result:false;
	}
	public function getMulti(array $objectIds = array()) {
		$result = $this->getStorageEngine()->getMulti($objectIds);
		return $result?$result:false;
	}

	public function set($objectId, $value) {
		$this->getStorageEngine()->set($objectId, $value);
	}
	public function setMulti(array $values = array()) {
		return $this->getStorageEngine()->setMulti($values);
	}

	public function create($objectId, $value) {
		if($this->getStorageEngine()->add($objectId, $value))
			return true;
		throw new Engine_Driver_Memcached_Exception('OBJECT_ID_ALREADY_EXISTS', array('ObjectId' => $objectId, 'Value' => $value));
	}
	public function delete($objectId) {
		$this->getStorageEngine()->delete($objectId);
	}
}

class Engine_Driver_Memcached_Exception extends Engine_Driver_Exception {

}

?>
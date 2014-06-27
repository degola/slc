<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 26.10.13
 * Time: 01:01
 */

namespace slc\MVC\Application\BasicModels;

abstract class SetterGetterBase {
	const OBJECT_ID_PREFIX = 0x1000;
	const TABLE = null;
	const PRIMARY_FIELD_NAME = 'userId';
	const RESOURCE_TYPE = 'Database';

	protected $uniqueId = null;
	private $DatabaseFields = array();
	protected $ForcedDatabaseFields = array();

	public function __construct($uniqueId = null) {
		if(is_scalar($uniqueId))
			$this->uniqueId = $uniqueId;
		else $this->uniqueId = implode('_', $uniqueId);

		if($this->uniqueId)
			$this->load();
	}

	public function __sleep() {
		return array('uniqueId');
	}
	public function __wakeup() {
		$this->load();
	}

	public function __get($var) {
		if(method_exists($this, 'get'.$var))
			return $this->{'get'.$var}();

		if(property_exists($this, $var))
			return $this->$var;
		return null;
	}
	public function __set($var, $value) {
		if(method_exists($this, 'set'.$var))
			return $this->{'set'.$var}($value);
		if(property_exists($this, $var)) {
			$this->$var = $value;
			return true;
		}
		return false;
	}

	/**
	 * @param array $data
	 * @return \Farspace\User
	 */
	public static function search(array $data = array()) {
		$cls = get_called_class();
		if(sizeof($data) > 0) {
			$fields = array();
			foreach($data AS $key => $value) {
				if(property_exists($cls, $key)) {
					$fields[] = '`'.$key.'`=:'.$key;
					$searchData[':'.$key] = $value;
				}
			}
			if(sizeof($fields) > 0) {
				$query = \slc\MVC\Resources::Factory(static::RESOURCE_TYPE, 'SetterGetterBase::'.get_called_class())->prepare('SELECT * FROM '.static::TABLE.' WHERE '.implode(' AND ', $fields).' LIMIT 1');
				$query->execute($searchData);
				if($query->rowCount() > 0) {
					$obj = new $cls($query->fetchObject()->{static::PRIMARY_FIELD_NAME});
					return $obj;
				}
			}
			return false;
		}
	}

	/**
	 * loads data from storage
	 *
	 * @throws SetterGetterBase_Exception
	 */
	protected function load() {
		$result = static::getStorageCollector()->get($this->getUniqueId(true));
		if($result) {
			foreach($result AS $key => $value) {
				if(property_exists($this, $key)) {
					$this->DatabaseFields[$key] = $key;
					$this->$key = $value;
				}
			}
		} else
			throw new SetterGetterBase_Exception('NO_DATA_FOUND', array(
				'UniqueId' => $this->getUniqueId(true)
			));
	}
	protected function deleteEntry() {
		static::getStorageCollector()->delete($this->getUniqueId(true));
		return true;
	}

	/**
	 * @return \slc\MVC\Storage\Collector
	 */
	public static function getStorageCollector() {
		return \slc\MVC\Storage\Collector::Factory();
	}

	public function delete() {
		return $this->deleteEntry();
	}
	public static function create(array $data, array $defaultData = array(), $objectId = null) {
		if(sizeof($defaultData) == 0 && isset(static::$DefaultCreateData) && is_array(static::$DefaultCreateData) && sizeof(static::$DefaultCreateData) > 0)
			$defaultData = static::$DefaultCreateData;

		$targetData = array();
		$cls = get_called_class();
		foreach($defaultData AS $key => $value) {
			if(property_exists($cls, $key))
				$targetData[$key] = $value;
		}
		foreach($data AS $key => $value) {
			if(property_exists($cls, $key))
				$targetData[$key] = $value;
		}

		if(sizeof($targetData) > 0) {
			if(is_null($objectId))
				$objectId = \slc\MVC\UUID::generate(static::OBJECT_ID_PREFIX);
			if(!is_scalar($objectId))
				$objectId = implode('_', $objectId);

			static::getStorageCollector()->create(get_called_class().'::'.$objectId, $targetData);
			return new $cls($objectId);
		}
		throw new SetterGetterBase_Exception('INVALID_CREATE_PROPERTIES', array('CreateData' => $data, 'DefaultCreateData' => $defaultData));
	}

	public function getUniqueId($fullyQualified = false) {
		return ($fullyQualified===true?get_called_class().'::':'').$this->uniqueId;
	}

	protected function sync() {
		$data = array(
			static::PRIMARY_FIELD_NAME => $this->getUniqueId(false)
		);
		foreach($this->DatabaseFields AS $field) {
			if(property_exists($this, $field)) {
				$data[$field] = $this->$field;
			}
		}
		foreach($this->ForcedDatabaseFields AS $field) {
			if(property_exists($this, $field)) {
				$data[$field] = $this->$field;
			}
		}

		static::getStorageCollector()->set($this->getUniqueId(true), $data);
	}

	public function Commit() {
		return $this->sync();
	}

	protected static function getObjectDataFromObjectId($objectId) {
		if(strpos($objectId, '::') === false)
			throw new SetterGetterBase_Exception('INVALID_OBJECT_ID', array('ObjectId' => $objectId, 'Class' => get_called_class()));

		return \slc\MVC\Storage\Engine_Base::getObjectDataFromObjectId($objectId);
	}
	public static function FactoryByObjectId($objectId) {
		$data = static::getObjectDataFromObjectId($objectId);
		$cls = $data->Class;
		return new $cls($data->Id);
	}
}

class SetterGetterBase_Exception extends \slc\MVC\Application_Exception {

}

?>
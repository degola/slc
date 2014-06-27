<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 28.11.13
 * Time: 19:10
 */

namespace slc\MVC\Storage;

class Engine_Base {
	private $StorageEngine;

	public function __construct($StorageEngine = null) {
		$this->setStorageEngine($StorageEngine);
	}

	public static function Factory($StorageEngine = null) {
		$cls = get_called_class();
		return new $cls($StorageEngine);
	}

	protected final function setStorageEngine($StorageEngine = null) {
		$this->StorageEngine = $StorageEngine;
	}
	protected final function getStorageEngine() {
		return $this->StorageEngine;
	}
	protected function splitObjectId($objectId) {
		return static::getObjectDataFromObjectId($objectId);
	}
	public static function getObjectDataFromObjectId($objectId) {
		if(strpos($objectId, '::') === false)
			throw new Engine_Base_Exception('INVALID_OBJECT_ID', array('ObjectId' => $objectId));

		list($class, $id) = explode('::', $objectId, 2);
		return (object)array(
			'Class' => $class,
			'Id' => $id
		);
	}
}

class Engine_Base_Exception extends \slc\MVC\Application_Exception {

}

?>
<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 29.11.13
 * Time: 00:30
 */

namespace slc\MVC\Storage;

use slc\MVC\Resources;

class Engine_Driver_MySQL extends Engine_Base {
	public function __construct($StorageEngine) {
		if(!is_null($StorageEngine))
			parent::__construct($StorageEngine);
		else
			parent::__construct(Resources::Factory('Database', 'default'));
	}
	public function get($objectId) {
		$objectData = $this->splitObjectId($objectId);
		if(defined($objectData->Class.'::TABLE'))
			$table = constant($objectData->Class.'::TABLE');
		else throw new Engine_Driver_MySQL_Exception('INVALID_CLASS_FOR_STORAGE_DRIVER', array('Class' => $objectData->Class, 'Id' => $objectData->Id));

		$primaryKey = constant($objectData->Class.'::PRIMARY_FIELD_NAME');

		$sql = 'SELECT
			*
		FROM
			`'.$table.'`
		WHERE
			`'.$primaryKey.'`='.$this->getStorageEngine()->quote($objectData->Id);

		$result = $this->getStorageEngine()->query($sql)->fetchObject();

		return $result?$result:false;
	}
	public function getMulti(array $objectIds = array()) {
		$idsByTable = array();
		$tables = array();
		$resultData = array();
		foreach($objectIds AS $objectId) {
			$objectData = $this->splitObjectId($objectId);
			if(defined($objectData->Class.'::TABLE'))
				$table = constant($objectData->Class.'::TABLE');
			else throw new Engine_Driver_MySQL_Exception('INVALID_CLASS_FOR_STORAGE_DRIVER', array('Class' => $objectData->Class, 'Id' => $objectData->Id));

			if(!isset($idsByTable[$table])) {
				$idsByTable[$table] = array();
				$tables[$table] = (object)array(
					'PrimaryField' => constant($objectData->Class.'::PRIMARY_FIELD_NAME'),
					'Class' => $objectData->Class
				);
			}
			$idsByTable[$table][] = $this->getStorageEngine()->quote($objectId);
		}
		foreach($idsByTable AS $table => $ids) {
			$sql = 'SELECT
				*
			FROM
				`'.$table.'`
			WHERE
				`'.$tables[$table]->PrimaryField.'` IN ('.implode(', ', $ids).')';
			$result = $this->getStorageEngine()->query($sql);
			while($row = $result->fetchObject()) {
				$resultData[$tables[$table]->Class.'::'.$row->{$tables[$table]->PrimaryField}] = $row;
			}
		}
		return $resultData;
	}

	public function set($objectId, $value) {
		$objectData = $this->splitObjectId($objectId);
		if(defined($objectData->Class.'::TABLE'))
			$table = constant($objectData->Class.'::TABLE');
		else throw new Engine_Driver_MySQL_Exception('INVALID_CLASS_FOR_STORAGE_DRIVER', array('Class' => $objectData->Class, 'Id' => $objectData->Id));

		$onDuplicateKey = array();
		foreach($value AS $k => $v) {
			$onDuplicateKey[] = '`'.$k.'`=VALUES(`'.$k.'`)';
			$value[$k] = $this->getStorageEngine()->quote($v);
		}

		$sql = 'INSERT INTO
			`'.$table.'` (`'.implode('`, `', array_keys($value)).'`) VALUES ('.implode(', ', $value).')
		ON DUPLICATE KEY UPDATE
			'.implode(', ', $onDuplicateKey);
		$this->getStorageEngine()->query($sql);
	}
	public function setMulti(array $values = array()) {
		throw new Engine_Base_Exception('NOT_IMPLEMENTED', array('Values' => $values));
	}

	public function create($objectId, $value) {
		$objectData = $this->splitObjectId($objectId);
		if(defined($objectData->Class.'::TABLE'))
			$table = constant($objectData->Class.'::TABLE');
		else throw new Engine_Driver_MySQL_Exception('INVALID_CLASS_FOR_STORAGE_DRIVER', array('Class' => $objectData->Class, 'Id' => $objectData->Id));

		if(defined($objectData->Class.'::PRIMARY_FIELD_NAME'))
			$primaryKey = constant($objectData->Class.'::PRIMARY_FIELD_NAME');
		else throw new Engine_Driver_MySQL_Exception('INVALID_PRIMARY_FIELD_NAME_FOR_STORAGE_DRIVER', array('Class' => $objectData->Class, 'Id' => $objectData->Id));

		$value[$primaryKey] = $objectData->Id;

		$insertData = array();
		foreach($value AS $key => $value) {
			$insertData[':'.$key] = $value;
			$fields[':'.$key] = $key;
		}

		$sql = 'INSERT INTO
			`'.$table.'`
			('.implode(', ', $fields).') VALUES ('.implode(', ', array_keys($fields)).')';

		$createdOwnTransaction = false;
		// check if we're already in a transaction, if not, start one, otherwise don't do anything since the root will commit everything after work is done.
		if(!$this->getStorageEngine()->inTransaction()) {
			$this->getStorageEngine()->beginTransaction();
			$createdOwnTransaction = true;
		}

		$query = $this->getStorageEngine()->prepare($sql);
		$query->execute($insertData);

		if($createdOwnTransaction)
			$this->getStorageEngine()->commit();


	}
	public function delete($objectId) {
		$objectData = $this->splitObjectId($objectId);
		if(defined($objectData->Class.'::TABLE'))
			$table = constant($objectData->Class.'::TABLE');
		else throw new Engine_Driver_MySQL_Exception('INVALID_CLASS_FOR_STORAGE_DRIVER', array('Class' => $objectData->Class, 'Id' => $objectData->Id));

		$primaryKey = constant($objectData->Class.'::PRIMARY_FIELD_NAME');

		$sql = 'DELETE FROM
			`'.$table.'`
		WHERE
			`'.$primaryKey.'`='.$this->getStorageEngine()->quote($objectData->Id);
		$this->getStorageEngine()->query($sql);
	}

}

class Engine_Driver_MySQL_Exception extends Engine_Driver_Exception {

}


?>
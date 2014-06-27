<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 07.12.13
 * Time: 01:05
 */

namespace slc\MVC\Storage;

use slc\MVC\UUID;

class Collector {
	const DEBUG = true;
	private static $Data = array();
	private $Engines = array();

	public function __construct(array $StorageEngines = null) {
		if(is_null($StorageEngines))
			$this->setEngines(array(
				'\slc\MVC\Storage\Engine_Driver_Memcached',
				'\slc\MVC\Storage\Engine_Driver_Redis',
				'\slc\MVC\Storage\Engine_Driver_MySQL'
			));
		else
			$this->setEngines($StorageEngines);
	}
	public static function Factory(array $StorageEngines = null) {
		return new Collector($StorageEngines);
	}

	public function setEngines(array $Engines) {
		$this->Engines = array();
		foreach($Engines AS $Engine) {
			if(is_scalar($Engine))
				$this->Engines[] = $Engine::Factory();
			else
				$this->Engines[] = $Engine;
		}
	}

	public function get($objectId, $storeResultToPreviousEngines = true) {
		if(!isset(static::$Data[$objectId])) {
			ksort($this->Engines);
			$result = null;
			$cacheEngines = array();
			foreach($this->Engines AS $Engine) {
				$result = $Engine->get($objectId);
				if($result) {
					static::$Data[$objectId] = $result;
					break;
				} else
					$cacheEngines[] = $Engine;
			}
			if($storeResultToPreviousEngines === true && $result) {
				foreach($cacheEngines AS $Engine) {
					$Engine->set($objectId, $result);
				}
			}

		}
		if(isset(static::$Data[$objectId]))
			return static::$Data[$objectId];
		return false;
	}
	public function set($objectId, $value) {
		static::$Data[$objectId] = $value;

		/**
		 * we sort the engines reversed since the order should be temporarily storage -> permanent storage, so we ensure that we
		 * store the data to the permanent storage first and throwing an exception while doing that is able to handle the rewrite earlier
		 */
		krsort($this->Engines);
		foreach($this->Engines AS $Engine) {
			$Engine->set($objectId, $value);
		}

	}

	/**
	 * creates a new key/value
	 *
	 * @param $objectId
	 * @param $value
	 * @return mixed
	 */
	public function create($objectId, $value) {
		krsort($this->Engines);
		reset($this->Engines);
		// just use the very last engine in list which should be the ultimatively
		$Engine = current($this->Engines);
		return $Engine->create($objectId, $value);
	}

	/**
	 * delete given object id from all engines...
	 *
	 * @param $objectId
	 */
	public function delete($objectId) {
		krsort($this->Engines);
		foreach($this->Engines AS $Engine) {
			$Engine->delete($objectId);
		}
	}

	/**
	 * get multiple keys
	 *
	 * @param $objectIds
	 * @param bool $storeResultToPreviousEngines
	 * @return array
	 */
	public function getMulti($objectIds, $storeResultToPreviousEngines = true, $ensureLoading = false) {
		$result = array();
		$remainingObjectIds = $objectIds;
		foreach($remainingObjectIds AS $key => $objectId) {
			if(isset(static::$Data[$objectId])) {
				$result[$objectId] = static::$Data[$objectId];
				unset($remainingObjectIds[$key]);
			}
		}
		if(sizeof($remainingObjectIds) > 0) {
			$writeBack = array();
			ksort($this->Engines);
			\slc\MVC\Debug::Write("get ".sizeof($remainingObjectIds)." in total from engines...", null, \slc\MVC\Debug::MESSAGE_NEWLINE_BEGIN, 5, __CLASS__);
			foreach($this->Engines AS $engineId => $Engine) {
				// fetch all remaining object ids
				\slc\MVC\Debug::Write("requesting ".sizeof($remainingObjectIds)." elements from engine ".get_class($Engine)."...", null, \slc\MVC\Debug::MESSAGE_NEWLINE_BEGIN, 6, __CLASS__);
				$engineResult = $Engine->getMulti($remainingObjectIds);
				if(is_array($engineResult) && sizeof($engineResult) > 0) {
					\slc\MVC\Debug::Write("done. (got ".sizeof($engineResult)." elements)", null, \slc\MVC\Debug::MESSAGE_NEWLINE_END, 6, __CLASS__);
					/**
					 * if we have a result iterate thru it and copy it to the result array and writeback array with the
					 * current engine id
					 * we also remove the object id from the remainingObjectIds array
					 */
					foreach($engineResult AS $objectId => $value) {
						$result[$objectId] = $value;
						static::$Data[$objectId] = $value;
						$writeBack[$engineId][$objectId] = $value;
						$key = array_search($objectId, $remainingObjectIds);
						unset($remainingObjectIds[$key]);
					}
				}
				if(sizeof($remainingObjectIds) == 0)
					break;
			}
			\slc\MVC\Debug::Write("done. (".sizeof($remainingObjectIds)." elements left)", null, \slc\MVC\Debug::MESSAGE_NEWLINE_END, 5, __CLASS__);

			/**
			 * store results back to previous storage engines
			 * we store in writeBack the data we got from the earliest storage engine which holds the data,
			 * to avoid writing and probably overwriting to all engines we store the engine id and check in the following
			 * foreach loop if the current engine id is lower than the first seen storage engine
			 */
			if($storeResultToPreviousEngines === true && sizeof($writeBack) > 0) {
				foreach($writeBack AS $sourceEngineId => $data) {
					foreach($this->Engines AS $engineId => $Engine) {
						if($engineId < $sourceEngineId) {
							$Engine->setMulti($data);
						}
					}
				}
				unset($writeBack);
			}
		}
		return $result;
	}

	public function clean() {
		static::$Data = array();
		$this->Engines = array();
	}

}

?>
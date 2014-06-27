<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 28.11.13
 * Time: 19:06
 */

namespace slc\MVC\Storage;

interface Engine_Interface {

	public function get($objectId);
	public function getMulti(array $objectIds = array());

	public function set($objectId, $value);
	public function setMulti(array $objectIds = array());

	public function create($objectId, $value);
	public function delete($objectId);
}

?>
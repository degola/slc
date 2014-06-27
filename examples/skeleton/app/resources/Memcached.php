<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 26.10.13
 * Time: 00:44
 */

namespace slc\MVC\Resources;

class Memcached extends \Memcached {
	private $Configuration = null;

	/**
	 * prepares the pdo instance
	 *
	 * @param $id contains the connection id, right now not in use but later we can use that for splitting users, maps, etc., usually the caller class is the id
	 */
	public function __construct($id) {
		parent::__construct($id);

		$this->Configuration = (object)\slc\MVC\Base::Factory()->getConfig('Memcached');
		$this->addServer($this->Configuration->hostname, $this->Configuration->port);
	}
}

class Memcached_Exception extends \slc\MVC\Application_Exception {

}

?>
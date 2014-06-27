<?php
/**
 * redis resource connector
 *
 * important: add composers Predis as dependency, it's not added by default
 *
 * @author Sebastian Lagemann <mail@degola.de>
 * @uses Predis
 */

namespace slc\MVC\Resources;

class Redis extends \Predis\Client {
	private $Configuration = null;

	/**
	 * prepares the pdo instance
	 *
	 * @param $id contains the connection id, right now not in use but later we can use that for splitting users, maps, etc., usually the caller class is the id
	 */
	public function __construct($id) {
		$this->Configuration = (object)\slc\MVC\Base::Factory()->getConfig('Redis');
		parent::__construct('tcp://'.$this->Configuration->hostname.':'.$this->Configuration->port);
	}
}

class Redis_Exception extends \slc\MVC\Application_Exception {

}

?>
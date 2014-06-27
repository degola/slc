<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 26.10.13
 * Time: 00:44
 */

namespace slc\MVC\Resources;

class Database extends \PDO {
	private $Configuration = null;

	/**
	 * prepares the pdo instance
	 *
	 * @param $id contains the connection id, right now not in use but later we can use that for splitting users, maps, etc., usually the caller class is the id
	 */
	public function __construct($id) {
		$this->Configuration = (object)\slc\MVC\Base::Factory()->getConfig('Database_PDO');
		parent::__construct($this->Configuration->uri, $this->Configuration->user, $this->Configuration->password);
		$this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, array('\slc\MVC\Resources\Database_Statement'));
		$this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	}
	public function query($statement) {
		$result = parent::query($statement);
		if($result === false) throw new Database_Exception('INVALID_QUERY_EXECUTION', array('Query' => $statement, 'Error' => $this->errorInfo()));
		return $result;
	}
}
class Database_Statement extends \PDOStatement {
	public function execute($array = null) {
		if(parent::execute($array))
			return true;
		throw new Database_Exception('INVALID_QUERY_EXECUTION', array('Query' => $this->queryString, 'Error' => $this->errorInfo()));
	}
}

class Database_Exception extends \slc\MVC\Application_Exception {

}

?>
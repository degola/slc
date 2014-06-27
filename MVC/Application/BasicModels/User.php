<?php
/**
 * Created by PhpStorm.
 * User: degola
 * Date: 26.10.13
 * Time: 01:01
 */

namespace slc\MVC\Application\BasicModels;

class User extends SetterGetterBase {
	const TABLE = 'users';
	const PASSWORD_SALT = '%_SECRET_FARSPACE_SALT_%';
	const EMAIL_SALT = '%_SECRET_STATIC_FARSPACE_EMAIL_SALT_%';
	const EMAIL_REGEX = '/^[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])$/i';

	protected $name = null;
	protected $password = null;
	protected $email = null;
	protected $signupDate = null;
	protected $lastActionDate = null;
	protected $status = null;
	protected $balance = null;
	protected $isEmailActivated = null;
	protected $lastMailSent = null;

	/**
	 * password generation configuration (min values)
	 *
	 * @var array
	 */
	protected static $PASSWORD_GENERATION_CONFIG = array("numbers" => 2, "uppercase" => 2, "lowercase" => 2);

	public function getEmail() {
		return $this->email;
	}
	public function setEmail($value) {
		if($this->email != $value) {
			$this->email = strtolower($value);
			$this->setIsEmailActivated(false);
		}
	}
	public function getUsername() {
		return $this->name;
	}
	public function setUsername($value) {
		$this->name = $value;
	}
	public function checkUsername($value) {
		if(!preg_match('/^\S{3,}$/', $value))
			return false;

		$query = \slc\MVC\Resources::Factory(static::RESOURCE_TYPE, 'SetterGetterBase::'.get_called_class())->prepare('SELECT
			COUNT(*) AS Count
		FROM
			'.static::TABLE.'
		WHERE
			`name`=:username AND
			`status`!=\'deleted\' AND
			`'.static::PRIMARY_FIELD_NAME.'`!=:uniqueId');
		$query->execute(array(
			':username' => $value,
			':uniqueId' => $this->getUniqueId()
		));
		if($query->fetchObject()->Count > 0) {
			return false;
		}
		return true;
	}


	public function getStatus() {
		return $this->status;
	}
	public function setStatus($value) {
		$this->status = $value;
	}

	public function setLastMailSent($time) {
		if(is_numeric($time))
			$time = gmdate('Y-m-d H:i:s', $time);
		$this->lastMailSent = $time;
	}
	public function getLastMailSent() {
		return strtotime($this->lastMailSent);
	}

	public function setLastActionDate($time) {
		if(is_numeric($time))
			$time = gmdate('Y-m-d H:i:s', $time);
		$this->lastActionDate = $time;
	}

	public function getLastActionDate() {
		return strtotime($this->lastActionDate);
	}
	public function getPassword() {
		return $this->password;
	}
	public function setPassword($value) {
		$this->password = 'v1$$'.hash('SHA512', static::PASSWORD_SALT.'::'.$value);
	}

	public function createPassword() {
		$password = static::generatePassword(6);
		$this->setPassword($password);
		return $password;
	}
	protected static function generatePassword($length) {
		$list["lowercase"] = array("from" => 97, "till" => 122);
		$list["uppercase"] = array("from" => 65, "till" => 90);
		$list["numbers"] = array("from" => 48, "till" => 57);
		srand(time());
		$seen = array();
		foreach(static::$PASSWORD_GENERATION_CONFIG AS $key => $value) {
			$counter = 0;
			while($counter < $value) {
				$pos = rand(0, $length);
				if(!array_key_exists($pos, $seen) || !$seen[$pos]) {
					$poslist[$pos] = $key;
					$seen[$pos] = true;
					$counter++;
				}
			}
		}
		ksort($poslist);
		$string = "";
		$seenSigns = array();
		foreach($poslist AS $value) {
			$sign = rand($list[$value]["from"], $list[$value]["till"]);
			while(array_key_exists($sign, $seenSigns) && $seenSigns[$sign] === true) {
				$sign = rand($list[$value]["from"], $list[$value]["till"]);
			}
			$seenSigns[$sign] = true;
			$string .= chr($sign);
		}
		$changeList = array("0" => "h", "O" => "H", "I" => "f", "l" => "F", "1" => "T");
		foreach($changeList AS $key => $value) $string = str_replace($key, $value, $string);
		return $string;
	}

	public static function create(array $data, array $defaultData = array(), $objectId = null) {
		if(!isset($data['name']) && !isset($defaultData['name']))
			$data['name'] = 'User '.\slc\MVC\UUID::generate(0x2305);

		if(isset($defaultData['password']) && !isset($data['password']))
			$data['password'] = $defaultData['password'];
		if(!isset($data['password']))
			$data['password'] = static::generatePassword(6);
		if(isset($data['password']))
			$data['password'] = 'v1$$'.hash('sha512', static::PASSWORD_SALT.'::'.$data['password']);

		if(!isset($data['status']) && !isset($defaultData['status']))
			$data['status'] = 'new';
		if(!isset($data['signupDate']) && !isset($defaultData['signupDate']))
			$data['signupDate'] = gmdate('Y-m-d H:i:s');
		if(!isset($data['lastActionDate']) && !isset($defaultData['lastActionDate']))
			$data['lastActionDate'] = gmdate('Y-m-d H:i:s');
		if(!isset($data['lastActionDate']) && !isset($defaultData['lastActionDate']))
			$data['lastActionDate'] = gmdate('Y-m-d H:i:s');
        if(!isset($data['lastMailSent']) && !isset($defaultData['lastMailSent']))
            $data['lastMailSent'] = gmdate('Y-m-d H:i:s');
		$data['isEmailActivated'] = 'false';

		return parent::create($data, $defaultData, null);
	}
	public function validateClearTextPassword($password) {
		$compare = 'v1$$'.hash('sha512', static::PASSWORD_SALT.'::'.$password);
		return $this->getPassword() === $compare;
	}
	public function getEmailHash() {
		return hash('sha512', static::EMAIL_SALT.'::'.$this->getEmail().'::'.$this->getUniqueId());
	}
	public function delete($realDelete = false) {
		if($realDelete === false) {
			$this->setStatus('deleted');
			$this->sync();
			return true;
		} else {
			return $this->deleteEntry();
		}
	}

	public function getIsEmailActivated($returnAsBool = false) {
		if($returnAsBool) {
			if(is_bool($this->isEmailActivated))
				return $this->isEmailActivated;
			return ($this->isEmailActivated==='true'?true:false);
		}

		return $this->isEmailActivated;
	}
	public function setIsEmailActivated($value) {
		if(is_bool($value))
			$this->isEmailActivated = $value?'true':'false';
		else
			$this->isEmailActivated = $value;
	}
	public function checkEmail($email) {
		if(!preg_match(static::EMAIL_REGEX, $email))
			return false;

		$query = \slc\MVC\Resources::Factory(static::RESOURCE_TYPE, 'SetterGetterBase::'.get_called_class())->prepare('SELECT
			COUNT(*) AS Count
		FROM
			'.static::TABLE.'
		WHERE
			`email`=:email AND
			`'.static::PRIMARY_FIELD_NAME.'`!=:uniqueId');
		$query->execute(array(
			':uniqueId' => $this->getUniqueId(),
			':email' => $email
		));
		if($query->fetchObject()->Count > 0) {
			return false;
		}
		return true;
	}
	public function getEmailActivationToken() {
		$query = \slc\MVC\Resources::Factory(static::RESOURCE_TYPE, 'SetterGetterBase::'.get_called_class())->prepare('SELECT
			*
		FROM
			log_email_activations
		WHERE
			`'.static::PRIMARY_FIELD_NAME.'`=:uniqueId AND
			`email`=:email AND
			`activationIP` IS NULL AND
			`activationDate` IS NULL
		LIMIT 1');
		$query->execute(array(
			':uniqueId' => $this->getUniqueId(),
			':email' => $this->getEmail()
		));
		if($query->rowCount() > 0) {
			return $query->fetchObject()->activationCode;
		}

		$token = \slc\MVC\UUID::generateShort();
		$query = \slc\MVC\Resources::Factory(static::RESOURCE_TYPE, 'SetterGetterBase::'.get_called_class())->prepare('INSERT INTO
			log_email_activations
		SET
			`'.static::PRIMARY_FIELD_NAME.'`=:uniqueId,
			`activationCode`=:token,
			`email`=:email,
			`insertDate`=NOW()');
		$query->execute(array(
			':uniqueId' => $this->getUniqueId(),
			':token' => $token,
			':email' => $this->getEmail()
		));
		return $token;
	}
	public function validateEmailActivationToken($email, $token, $ip) {
		if($this->getEmailHash() == $email) {
			if($this->getEmailActivationToken() === $token) {
				$Database = \slc\MVC\Resources::Factory(static::RESOURCE_TYPE, 'SetterGetterBase::'.get_called_class());
				$query = $Database->prepare('UPDATE
					log_email_activations
				SET
					`activationIP`=:ip,
					`activationDate`=NOW()
				WHERE
					`'.static::PRIMARY_FIELD_NAME.'`=:uniqueId AND
					`email`=:email AND
					`activationCode`=:activationCode');
				$query->execute(array(
					':ip' => $ip,
					':uniqueId' => $this->getUniqueId(),
					':email' => $this->getEmail(),
					':activationCode' => $token
				));
				$this->setIsEmailActivated(true);
				$this->Commit();
				return true;
			}
		}
		return false;
	}

	/**
	 * send an html email with given subject and data to the user with the given template path
	 *
	 * @param $subject
	 * @param $templatePath
	 * @param array $Data
	 * @param bool $forceSend
	 * @return bool
	 */
	public function sendEmail($subject, $templatePath, array $Data = array(), $forceSend = false) {
		if($forceSend === true || (time() - 3600 * 4) >= $this->getLastMailSent()) {
			$Router = new \slc\MVC\Router_Driver_Dummy($templatePath);

			$RenderEngine = new \slc\MVC\RenderEngine_Twig($Router, null);
			$RenderEngine->setTemplateValues(array_merge($Data, array(
				'BaseUrl' => \slc\MVC\Base::Factory()->getConfig('Application', 'BaseUrl'),
				'Name' => $this->getUsername(),
				'GameTitle' => 'Farspace'
			)));
			$Mail = new \slc\MVC\SimpleMail();
			$Mail->setFrom(\slc\MVC\Base::Factory()->getConfig('Email', 'From')->Email, \slc\MVC\Base::Factory()->getConfig('Email', 'From')->Name)
				->setTo($this->email, $this->getUsername())
				->setSubject('[Farspace] '.$subject)
				->addMailHeader('Reply-To', 'no-reply@farspace-game.com', 'NO REPLY')
				->addGenericHeader('Content-Type', 'text/html; charset=utf-8')
				->addGenericHeader('X-Mailer', 'Farspace Mailer')
				->setMessage($RenderEngine->fetch())
				->setWrap(80);
			$this->setLastMailSent(time());
			$this->Commit();
			return $Mail->send();
		}
		return false;
	}
}

class User_Exception extends \slc\MVC\Application_Exception {

}

?>
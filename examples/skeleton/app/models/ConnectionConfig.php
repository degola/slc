<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 29.07.13
 * Time: 18:23
 * To change this template use File | Settings | File Templates.
 */

class ConnectionConfig {
    protected $Daemon = null;
    protected $Facility = null;

    protected $Config = null;

    public function __construct($Daemon, $Facility) {
        $this->Daemon = $Daemon;
        $this->Facility = $Facility;

        $default_config = null;
        if(isset(\slc\MVC\Base::Factory()->getConfig($this->Daemon, DEPLOYMENT_STATE)->default))
            $default_config = \slc\MVC\Base::Factory()->getConfig($this->Daemon, DEPLOYMENT_STATE)->default;

        if(is_null($default_config))
            throw new ConnectionConfig_Exception('INVALID_CONFIGURATION', array(
                'Daemon' => $this->Daemon,
                'Facility' => $Facility,
                'DeploymentState' => DEPLOYMENT_STATE,
                'ConfigPath' => implode('->', array($this->Daemon, DEPLOYMENT_STATE, '(default || '.$Facility.')'))
            ));
        $tmp = \slc\MVC\Base::Factory()->getConfig($this->Daemon, DEPLOYMENT_STATE);
        if(isset($tmp->{DEPLOYMENT_STATE}->{$this->Facility}))
            $facility_config = $tmp->{DEPLOYMENT_STATE}->{$this->Facility};
        $this->Config = (object)array_merge((array)$default_config, isset($facility_config)?(array)$facility_config:array());

    }
    public function __get($varname) {
        if(isset($this->Config->{$varname}))
            return $this->Config->{$varname};
        return null;
    }
    public function getConfig($type = null) {
        return $this->Config;
    }
    public function getUniqueId() {
        return sha1(json_encode($this->Config));
    }
}

class ConnectionConfig_Exception extends \slc\MVC\Application_Exception {

}

?>
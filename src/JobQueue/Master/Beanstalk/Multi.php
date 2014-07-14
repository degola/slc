<?php
/**
 * Created by JetBrains PhpStorm.
 * User: degola
 * Date: 29.07.13
 * Time: 18:19
 * To change this template use File | Settings | File Templates.
 */
namespace slc\MVC\JobQueue;

class Master_Beanstalk_Multi extends Master {
    const DEBUG = false;

    /**
     * @var \PhpAmqpLib\Connection\AMQPConnection
     */
    protected $Connection = null;

    /**
     * @var AMQPChannel
     */
    protected $Channel = null;

    /**
     * amqp queue name for master commands
     *
     * @var String
     */
    protected $Queue = null;

    /**
     * @var \slc\MVC\Beanstalkd\Driver[]
     */
    protected $BeanstalkSeverList = array();

    /**
     * build amqp connections and bindings
     */
    protected function Setup() {
        $AppState = DEPLOYMENT_STATE;

        $this->DisconnectAll();

        $FacilityConfiguration = $this->getFacilityConfig();
        $result = \slc\MVC\Resources::Factory('Database', __CLASS__)->query('SELECT
			`hostname`,
			`port`
		FROM
			`'.$FacilityConfiguration->StateCheckProperties->BeanstalkServerTable.'` AS s
		WHERE
			s.`Facility`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($this->Facility).' AND
			s.`AppState`='.\slc\MVC\Resources::Factory('Database', __CLASS__)->quote($AppState).' AND
			s.`Status`=\'online\'');
        while($row = $result->fetchObject(null)) {
            $this->BeanstalkServerList['Host='.$row->hostname.' Port='.$row->port.' Connections=1000'] = new \slc\MVC\Beanstalkd\Driver(array('Host' => $row->hostname, 'Port' => $row->port, 'Connections' => 1));
        }
        $this->LastSetupTime = time();
    }
    protected function DisconnectAll() {
        foreach($this->BeanstalkSeverList AS $instance) {
            $instance->disconnect();
        }
    }

    /**
     * check if a command is waiting and execute ConsumeCommand method
     *
     * this method is called from JobQueue_Master::Run() very often, be careful with implementing too much stuff here, avoid
     * too much cpu usage with rand() return;
     */
    protected function CheckOperation() {
        // \slc\MVC\Debug::Write('master is still alive.', '.', 0, 0, true, __CLASS__);

        if(rand(0, 30) === 0) { // only execute every 20 calls (if check interval is 50ms this means every 1,5 seconds)

            try {
                /**
                 * we can't use the wait method because we need a non-blocking call to not interrupt the masters main functionality
                 * so we have to build it by our own, therefore we use basic_get and check afterwards if there was a valid response
                 * if not, don't do anything and just return, otherwise wait to let the amqp lib doing the usual stuff...
                 */
                if($this->LastSetupTime <= time() - $this->getFacilityConfig()->StateCheckProperties->SetupRefreshTime) {
                    $this->Setup();
                }

                $result = array();
                foreach($this->BeanstalkServerList AS $host => $instance) {
                    $result[$host] = $instance->getStatsTube($this->getFacilityConfig()->StateCheckProperties->BeanstalkTube)->{"current-jobs-urgent"};
                }
                $this->calculateExpectedConsumers($result);

            } catch(Exception $ex) {
                \slc\MVC\Debug::Write('exception caught ('.__METHOD__.'): '.$ex->getCode().' => '.$ex->getMessage(), null, 1, 1, __CLASS__);
                throw $ex;
            }
        }
    }
}

?>
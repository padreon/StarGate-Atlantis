<?php
namespace alemiz\sga\client;

use alemiz\sga\packets\ConnectionInfoPacket;
use alemiz\sga\packets\PingPacket;
use alemiz\sga\StarGateAtlantis;
use alemiz\sga\tasks\ResponseRemoveTask;
use pocketmine\plugin\PluginLogger;
use pocketmine\scheduler\Task;
use pocketmine\Server;

class Client extends Task {

    /** @var StarGateAtlantis */
    private $sga;
    /** @var Server */
    private $server;
    /** @var PluginLogger */
    private $logger;

    /** @var string */
    protected $address;
    /** @var int */
    protected $port;
    /** @var string */
    protected $name;
    /** @var string */
    protected $password;

    /** @var ClientInterface */
    private $interface;

    /**
     * Client constructor.
     * @param StarGateAtlantis $plugin
     * @param string $address
     * @param int $port
     * @param string $name
     * @param string $password
     * @param int $tickInterval
     */
    public function __construct(StarGateAtlantis $plugin, string $address, int $port, string $name, string $password, int $tickInterval){
        $this->sga = $plugin;
        $this->server = $plugin->getServer();
        $this->logger = $plugin->getLogger();

        $this->address = $address;
        $this->port = $port;
        $this->name = $name;
        $this->password = $password;

        $this->interface = new ClientInterface($this, $address, $port, $name, $password);
        $plugin->getScheduler()->scheduleDelayedRepeatingTask($this, 20, $tickInterval);
    }

    public function onRun(int $currentTick){
        if (!$this->interface->process()) return;

        $message = $this->interface->readPacket();
        if (is_null($message)) return;

        //TODO: remove
        $this->logger->info($message);

        if (strpos($message, "GATE_STATUS") !== false){
            $this->logger->info("GATE_STATUS");
            return;
        }

        if (strpos($message, "GATE_PING") !== false){
            $data = explode(":", $message);

            $packet = new PingPacket();
            $packet->pingData = $data[1];
            $packet->client = $this->name;
            $this->interface->gatePacket($packet);
            return;
        }

        if (strpos($message, "GATE_RESPONSE") !== false){
            $data = explode(":", $message);
            $uuid = $data[1];
            $response = $data[2];

            if (($handler = $this->interface->getResponseHandler($uuid)) !== null){
                $handler($response);
            }else{
                $this->interface->setResponse($uuid, $response);
                /* 20*30 is maximum tolerated delay*/
                $this->sga->getScheduler()->scheduleDelayedTask(new ResponseRemoveTask($uuid), 20*30);
            }
            return;
        }

        $packet = $this->sga->processPacket($message);
        if ($packet instanceof ConnectionInfoPacket){
            $reason = $packet->getReason();

            switch ($packet->getPacketType()){
                case ConnectionInfoPacket::CONNECTION_RECONNECT:
                    $this->logger->info("§cWARNING: Reconnecting to StarGate server! Reason: §c".(($reason === null) ? "unknown" : $reason));

                    $this->interface->reconnect();
                    break;
                case ConnectionInfoPacket::CONNECTION_CLOSED:
                    $this->logger->info("§cWARNING: Connection to StarGate server was closed! Reason: §c".(($reason === null) ? "unknown" : $reason));
                    $this->interface->forceClose();
                    break;
            }
        }
    }

    /**
     * @param string $reason
     * @param bool $kill
     */
    public function shutdown(string $reason = "unknown", bool $kill = false){
        if ($kill){
            $this->interface->forceClose();
            return;
        }
        $this->interface->close($reason);
    }


    /**
     * @return ClientInterface
     */
    public function getInterface(): ClientInterface{
        return $this->interface;
    }

    /**
     * @return Server
     */
    public function getServer(): Server{
        return $this->server;
    }

    /**
     * @return StarGateAtlantis
     */
    public function getSga(): StarGateAtlantis{
        return $this->sga;
    }

    /**
     * @return PluginLogger
     */
    public function getLogger(): PluginLogger{
        return $this->logger;
    }
}
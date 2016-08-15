<?php

require(__DIR__ . '/../vendor/autoload.php');

use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * Class WebSocket
 */
class WebSocket extends \PHPDaemon\Core\AppInstance
{
    /**
     * @var bool
     */
    public $enableRPC = TRUE;

    /**
     * @var array
     */
    public $conf;

    /**
     * @var array
     */
    public $sessions = [];

    /**
     * WebSocket constructor.
     */
    public function __construct()
    {
        $this->conf = require(__DIR__ . '/../config/web.php');

        parent::__construct();
    }

    /**
     * If ready connection
     */
    public function onReady()
    {
        $appInstance = $this;

        $this->timerTask($appInstance);

        \PHPDaemon\Servers\WebSocket\Pool::getInstance()->addRoute('websocket', function ($client) use ($appInstance) {
            $session = new WebSocketRoute($client, $appInstance);
            $session->id = uniqid();
            $this->sessions[$session->id] = $session;
            return $session;
        });
    }

    /**
     * @param $appInstance
     */
    function timerTask($appInstance)
    {
        $redis = new Predis\Client([
            'scheme'   => 'tcp',
            'host'     => $this->conf['components']['redis']['hostname'],
            'port'     => $this->conf['components']['redis']['port'],
            'database' => $this->conf['components']['redis']['database']
        ]);
        $data = $redis->get('data');

        foreach ($this->sessions as $id => $session) {
            $session->client->sendFrame($data, 'STRING');
        }

        \PHPDaemon\Core\Timer::add(function ($event) use ($appInstance) {
            $this->timerTask($appInstance);
            $event->finish();
        }, 1e6);
    }
}

class WebSocketRoute extends \PHPDaemon\WebSocket\Route
{
    /**
     * @var $client
     */
    public $client;

    /**
     * @var $appInstance
     */
    public $appInstance;

    /**
     * @var $id
     */
    public $id;

    /**
     * WebSocketRoute constructor.
     * @param $client
     * @param $appInstance
     */
    public function __construct($client, $appInstance)
    {
        $this->client = $client;
        $this->appInstance = $appInstance;
    }

    /**
     * Called when new frame received.
     * @param string  Frame's contents.
     * @param integer Frame's type.
     * @return void
     */
    public function onFrame($data, $type)
    {
        if ($data === 'ping') {
            $this->client->sendFrame('pong', 'STRING');
        }
    }

    /**
     * Unset session
     */
    public function onFinish()
    {
        unset($this->appInstance->sessions[$this->id]);
    }

    /**
     * Uncaught exception handler
     *
     * @param $e
     * @return boolean|null Handled?
     */
    public function handleException($e)
    {
        $this->client->sendFrame('exception ...');
    }
}


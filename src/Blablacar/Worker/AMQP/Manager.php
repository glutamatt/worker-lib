<?php

namespace Blablacar\Worker\AMQP;

use Blablacar\Worker\AMQP\Consumer\ConsumerWrapper;
use Blablacar\Worker\AMQP\Consumer\ConsumerInterface;
use Blablacar\Worker\AMQP\Consumer\Context;

class Manager
{
    protected $connection;

    protected $exchanges = array();
    protected $queues    = array();
    protected $channel;
    protected $startTime;

    public function __construct(\AMQPConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * getConfig
     *
     * @return string
     */
    public function getConfig()
    {
        return sprintf(
            '%s[:%s]@%s%s:%d',
            $this->connection->getLogin(),
            $this->connection->getPassword(),
            $this->connection->getHost(),
            $this->connection->getVhost(),
            $this->connection->getPort()
        );
    }

    /**
     * publish
     *
     * @param string $exchange
     * @param string $message
     * @param string $routingKey
     * @param int    $flags
     * @param array  $attributes
     *
     * @return boolean
     */
    public function publish($exchange, $message, $routingKey, $flags = AMQP_NOPARAM, array $attributes = array())
    {
        $exchange = $this->getExchange($exchange);

        return $exchange->publish($message, $routingKey, $flags, $attributes);
    }

    /**
     * consume
     *
     * @param string  $queue
     * @param mixed   $consumer
     * @param Context $context
     * @param int     $flags
     *
     * @return void
     */
    public function consume($queue, $consumer, Context $context = null, $flags = null)
    {
        $queue = $this->getQueue($queue);

        if ($consumer instanceof ConsumerInterface) {
            $consumer->preProcess($context);
        }

        $this->startTime = time();

        $continue = true;
        while ($continue) {
            $envelope = $queue->get($flags);

            if (!$envelope) {
                usleep($context->getPollInterval());
            } else {
                $continue = $consumer($envelope, $queue, $context);
            }

            if (microtime(true)-$this->startTime >= $context->getMaxExecutionTime()) {
                $context->output(sprintf(
                    '<info>Maximum time exceeded. Exiting after <comment>%.2fs</comment>.</info>',
                    $elapsedTime
                ));

                return false;
            }
        }

        if ($consumer instanceof ConsumerInterface) {
            $consumer->postProcess($context);
        }
    }

    /**
     * createExchange
     *
     * @param string $name
     *
     * @return void
     */
    public function createExchange($name, $type, $flags)
    {
        $ex = $this->getExchange($name);
        $ex->setType(AMQP_EX_TYPE_DIRECT);
        $ex->setFlags($flags);
        $ex->declareExchange();

        return $ex;
    }

    /**
     * createQueue
     *
     * @param string $name
     *
     * @return void
     */
    public function createQueue($name, $flags = AMQP_NOPARAM, array $arguments = array())
    {
        $queue = $this->getQueue($name);
        $queue->setFlags($flags);
        $queue->setArguments($arguments);
        $queue->declareQueue();

        return $queue;
    }

    /**
     * deleteQueue
     *
     * @param strin $name
     *
     * @return boolean
     */
    public function deleteQueue($name)
    {
        if (strlen($name) == 0) {
            return false;
        }

        try {
            $queue = $this->getQueue($name);

            return $queue->delete(AMQP_IFEMPTY|AMQP_IFUNUSED);
        } catch (\AMQPException $e) {
            return false;
        }
    }

    /**
     * getExchange
     *
     * @param string $name
     *
     * @return \AMQPExchange
     */
    protected function getExchange($name)
    {
        if (array_key_exists($name, $this->exchanges)) {
            return $this->exchanges[$name];
        }

        if (null === $this->channel) {
            $this->connect();
        }

        $exchange = new \AMQPExchange($this->channel);
        $exchange->setName($name);

        $this->exchanges[$name] = $exchange;

        return $exchange;
    }

    /**
     * getQueue
     *
     * @param string $name
     *
     * @return \AMQPQueue
     */
    protected function getQueue($name)
    {
        if (array_key_exists($name, $this->queues)) {
            return $this->queues[$name];
        }

        if (null === $this->channel) {
            $this->connect();
        }

        $queue = new \AMQPQueue($this->channel);
        $queue->setName($name);

        $this->queues[$name] = $queue;

        return $queue;
    }

    /**
     * connect
     *
     * @return void
     */
    protected function connect()
    {
        $this->connection->connect();

        $this->channel = new \AMQPChannel($this->connection);
    }

}

<?php

namespace Blablacar\Worker\AMQP;

use Blablacar\Worker\AMQP\Consumer\ConsumerWrapper;
use Blablacar\Worker\AMQP\Consumer\ConsumerInterface;
use Blablacar\Worker\AMQP\Consumer\Context;

class Manager
{
    protected $connection;
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
     * If the AMQP_MANDATORY flag is not passed, and the publication failed, an
     * exception is thrown. Otherwise: it just return false
     *
     * @see http://us3.php.net/manual/en/amqp.constants.php
     *
     * @param string $exchange
     * @param string $message
     * @param string $routingKey
     * @param int    $flags
     * @param array  $attributes
     *
     * @throw \AMQPChannelException
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
    public function consume($queue, $consumer, Context $context = null, $flags = AMQP_NOPARAM)
    {
        if (null === $context) {
            $context = new Context();
        }

        if ($consumer instanceof ConsumerInterface) {
            $consumer->preProcess($context);
        }

        $this->startTime = time();

        $queue = $this->getQueue($queue);

        $continue = true;
        while ($continue) {
            try {
                $envelope = $queue->get($flags);
            } catch ( \Exception $e ) {
                $context->output(sprintf(
                    '<info>Error queue consume <comment>%s</comment>.</info>',
                    $e->getMessage()
                ));
                $envelope = false ;
            }

            if (!$envelope) {
                usleep($context->getPollInterval());
            } else {
                $continue = $consumer($envelope, $queue, $context);
            }

            $elapsedTime = microtime(true)-$this->startTime;
            if ($elapsedTime >= $context->getMaxExecutionTime()) {
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
     * @return integer The number of deleted messages.
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
        $channel = $this->connect();

        $exchange = new \AMQPExchange($channel);
        $exchange->setName($name);

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
        $channel = $this->connect();

        $queue = new \AMQPQueue($channel);
        $queue->setName($name);

        return $queue;
    }

    /**
     * connect
     *
     * @return void
     */
    protected function connect()
    {
        if (!$this->connection->isConnected()) {
            $this->connection->connect();
            $this->channel = null;
        }

        if (null === $this->channel) {
            $this->channel = new \AMQPChannel($this->connection);
        }

        return $this->channel;
    }
}

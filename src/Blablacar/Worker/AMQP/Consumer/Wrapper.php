<?php
declare(ticks = 1);

namespace Blablacar\Worker\AMQP\Consumer;

use Blablacar\Worker\Util\SignalHandler;
use Psr\Log\LoggerInterface;

/**
 * Wrapper
 *
 * @TODO: Refactor the __invoke method !
 */
class Wrapper implements ConsumerInterface
{
    protected $consumer;
    protected $logger;

    protected $nbMessagesProcessed = 0;

    public function __construct($consumer, LoggerInterface $logger = null)
    {
        $this->consumer = $consumer;
        $this->logger   = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public function preProcess(Context $context = null)
    {
        if (null === $context) {
            return;
        }

        $context->output(sprintf(
            '<info>Run worker (pid: <comment>%d</comment>. Consume <comment>%d messages</comment> or stop after <comment>%ds</comment>.</info>',
            getmypid(),
            $context->getMaxMessages(),
            $context->getMaxExecutionTime()
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function postProcess(Context $context = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function __invoke(\AMQPEnvelope $envelope, \AMQPQueue $queue, Context $context = null)
    {
        $currentStartTime = microtime(true);

        if ($context->getUseSigHandler()) {
            SignalHandler::start();
        }

        try {
            $consumer = $this->consumer;
            $consumer($envelope, $queue, $context);

            $queue->ack($envelope->getDeliveryTag());

            $context->output(sprintf(
                '<comment>ACK [%s]. Duration <info>%.2fs</info>. Memory usage: <info>%.2f Mo</info></comment>',
                $envelope->getDeliveryTag(),
                microtime(true)-$currentStartTime,
                round(memory_get_usage()/1024/1024, 2)
            ));
        } catch (\Exception $e) {
            $queue->nack($envelope->getDeliveryTag(), $context->getRequeueOnError()? AMQP_REQUEUE : null);
            $context->output(sprintf(
                '<error>NACK [%s].</error>',
                $envelope->getDeliveryTag()
            ));

            if (null !== $this->logger) {
                $this->logger->error(sprintf(
                    'Error occured with queue "%s". Message: %s. Exception: %s',
                    $queue->getName(),
                    $e->getMessage(),
                    $e->getTraceAsString()
                ));
            }

            if (null !== $context->getOutput() && $context->getOutput()->getVerbosity() >= 2) {
                throw $e;
            }
        }

        if (++$this->nbMessagesProcessed >= $context->getMaxMessages()) {
            $context->output(sprintf(
                '<info>Max messages reached. Exiting after processing <comment>%d messages</comment>.</info>',
                $this->nbMessagesProcessed
            ));

            return false;
        }

        if ($context->getUseSigHandler() && SignalHandler::haveToStop()) {
            $context->output(sprintf(
                '<info>Signal received. Exiting after processing <comment>%d messages</comment>.</info>',
                $this->nbMessagesProcessed
            ));

            return false;
        }

        return true;
    }
}

<?php

namespace Blablacar\Worker\AMQP;

use Blablacar\Worker\AMQP\Manager;
use Blablacar\Worker\AMQP\Consumer\Context;
use Blablacar\Worker\AMQP\Consumer\Wrapper;

class ManagerTest extends \PHPUnit_Framework_TestCase
{
    public function testInstanceOf()
    {
        $manager = new Manager(new \AMQPConnection());
        $this->assertInstanceOf('Blablacar\Worker\AMQP\Manager', $manager);
    }

    public function testGetConfig()
    {
        $manager = new Manager(new \AMQPConnection());
        $this->assertEquals('guest[:guest]@localhost:5672', $manager->getConfig());
    }

    public function testConsumeWithConsumerInterface()
    {
        $manager = new Manager(new \AMQPConnection());

        $consumer = $this->getMock('Blablacar\Worker\AMQP\Consumer\ConsumerInterface');
        $consumer
            ->expects($this->exactly(3))
            ->method('__invoke')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->will($this->onConsecutiveCalls(true, true, false))
        ;

        $manager->consume('blablacar_worker_queue_test', new Wrapper($consumer));
    }
}
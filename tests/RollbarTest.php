<?php

namespace Rollbar\Laravel;

use Rollbar\Laravel\Facades\Rollbar as RollbarFacade;
use Rollbar\Laravel\RollbarServiceProvider;
use Rollbar\Laravel\RollbarLogHandler;
use Rollbar\RollbarLogger;

class RollbarTest extends \Orchestra\Testbench\TestCase
{
    public function setUp()
    {
        $this->access_token = 'B42nHP04s06ov18Dv8X7VI4nVUs6w04X';
        putenv('ROLLBAR_TOKEN=' . $this->access_token);

        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [RollbarServiceProvider::class];
    }

    public function testBinding()
    {
        $client = $this->app->make(RollbarLogger::class);
        $this->assertInstanceOf(RollbarLogger::class, $client);

        $handler = $this->app->make(RollbarLogHandler::class);
        $this->assertInstanceOf(RollbarLogHandler::class, $handler);
    }

    public function testIsSingleton()
    {
        $handler1 = $this->app->make(RollbarLogHandler::class);
        $handler2 = $this->app->make(RollbarLogHandler::class);
        $this->assertEquals(spl_object_hash($handler1), spl_object_hash($handler2));
    }

    public function testFacade()
    {
        $client = RollbarFacade::getFacadeRoot();
        $this->assertInstanceOf(RollbarLogHandler::class, $client);
    }

    public function testPassConfiguration()
    {
        $client = $this->app->make(RollbarLogger::class);
        $config = $client->extend([]);
        $this->assertEquals($this->access_token, $config['access_token']);
    }

    public function testCustomConfiguration()
    {
        $this->app->config->set('logging.channels.rollbar.root', '/tmp');
        $this->app->config->set('logging.channels.rollbar.included_errno', E_ERROR);
        $this->app->config->set('logging.channels.rollbar.environment', 'staging');

        $client = $this->app->make(RollbarLogger::class);
        $config = $client->extend([]);
        
        $this->assertEquals('staging', $config['environment']);
        $this->assertEquals('/tmp', $config['root']);
        $this->assertEquals(E_ERROR, $config['included_errno']);
    }

    public function testAutomaticContext()
    {
        $this->app->session->put('foo', 'bar');
        
        $logger = $this->app->make(RollbarLogger::class);
        
        $handlerMock = \Mockery::mock(RollbarLogHandler::class, [$logger, $this->app]);
        $handlerMock->shouldReceive('log')->passthru();
        $this->app[RollbarLogHandler::class] = $handlerMock;
        
        $handlerMock->log('info', 'Test log message');
        
        $config = $logger->extend([]);

        $this->assertEquals([
            'session' => ['foo' => 'bar'],
            'id'      => $this->app->session->getId(),
        ], $config['person']);
    }

    public function testMergedContext()
    {
        $this->app->session->put('foo', 'bar');
        
        $logger = $this->app->make(RollbarLogger::class);
        
        $handlerMock = \Mockery::mock(RollbarLogHandler::class, [$logger, $this->app]);
        $handlerMock->shouldReceive('log')->passthru();
        $this->app[RollbarLogHandler::class] = $handlerMock;
        
        $handlerMock->log('info', 'Test log message', [
            'tags'   => ['one' => 'two'],
            'person' => ['id'  => "1337", 'email' => 'john@doe.com'],
        ]);
        
        $config = $logger->extend([]);

        $this->assertEquals([
            'session' => ['foo' => 'bar'],
            'id'      => "1337",
            'email'   => 'john@doe.com',
        ], $config['person']);
    }

    public function testLogListener()
    {
        $exception = new \Exception('Testing error handler');

        $clientMock = \Mockery::mock(RollbarLogger::class);
        
        $clientMock->shouldReceive('log')->times(2);
        $clientMock->shouldReceive('log')->times(1)->with('error', $exception, ['foo' => 'bar']);

        $handlerMock = \Mockery::mock(RollbarLogHandler::class, [$clientMock, $this->app]);
        
        $handlerMock->shouldReceive('log')->passthru();
        
        $this->app[RollbarLogHandler::class] = $handlerMock;

        $this->app->log->info('hello');
        $this->app->log->error('oops');
        $this->app->log->error($exception, ['foo' => 'bar']);
    }

    public function testErrorLevels1()
    {
        $this->app->config->set('logging.channels.rollbar.level', 'critical');

        $clientMock = \Mockery::mock(RollbarLogger::class);
        $clientMock->shouldReceive('log')->times(3);
        $this->app[RollbarLogger::class] = $clientMock;

        $this->app->log->debug('hello');
        $this->app->log->info('hello');
        $this->app->log->notice('hello');
        $this->app->log->warning('hello');
        $this->app->log->error('hello');
        $this->app->log->critical('hello');
        $this->app->log->alert('hello');
        $this->app->log->emergency('hello');
    }

    public function testErrorLevels2()
    {
        $this->app->config->set('logging.channels.rollbar.level', 'debug');

        $clientMock = \Mockery::mock(RollbarLogger::class);
        $clientMock->shouldReceive('log')->times(8);
        $this->app[RollbarLogger::class] = $clientMock;

        $this->app->log->debug('hello');
        $this->app->log->info('hello');
        $this->app->log->notice('hello');
        $this->app->log->warning('hello');
        $this->app->log->error('hello');
        $this->app->log->critical('hello');
        $this->app->log->alert('hello');
        $this->app->log->emergency('hello');
    }

    public function testErrorLevels3()
    {
        $this->app->config->set('logging.channels.rollbar.level', 'none');

        $clientMock = \Mockery::mock(RollbarLogger::class);
        $clientMock->shouldReceive('log')->times(0);
        $this->app[RollbarLogger::class] = $clientMock;

        $this->app->log->debug('hello');
        $this->app->log->info('hello');
        $this->app->log->notice('hello');
        $this->app->log->warning('hello');
        $this->app->log->error('hello');
        $this->app->log->critical('hello');
        $this->app->log->alert('hello');
        $this->app->log->emergency('hello');
    }

    public function testPersonFunctionIsCalledWhenSessionContainsAtLeastOneItem()
    {
        $this->app->config->set('logging.channels.rollbar.person_fn', function () {
            return [
                'id' => '123',
                'username' => 'joebloggs',
            ];
        });

        $logger = $this->app->make(RollbarLogger::class);

        $this->app->session->put('foo', 'bar');

        $this->app->log->debug('hello');

        $config = $logger->extend([]);

        $person = $config['person'];

        $this->assertEquals('123', $person['id']);
        $this->assertEquals('joebloggs', $person['username']);
    }
}

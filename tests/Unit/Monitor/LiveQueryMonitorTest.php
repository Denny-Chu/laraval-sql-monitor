<?php

declare(strict_types=1);

namespace LaravelSqlMonitor\Tests\Unit\Monitor;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use LaravelSqlMonitor\Events\N1PatternDetectedEvent;
use LaravelSqlMonitor\Events\QueryCapturedEvent;
use LaravelSqlMonitor\Events\SlowQueryDetectedEvent;
use LaravelSqlMonitor\Lifecycle\QueryRecord;
use LaravelSqlMonitor\Monitor\LiveQueryMonitor;
use Mockery;
use Orchestra\Testbench\TestCase;

class LiveQueryMonitorTest extends TestCase
{
    public function testDisabledMonitorBroadcastsNothingButStillDispatchesEvents(): void
    {
        Event::fake();
        Broadcast::shouldReceive('on')->never();

        $monitor = new LiveQueryMonitor(enabled: false);
        $monitor->broadcastQuery($this->record());
        $monitor->broadcastSlowQuery($this->record());
        $monitor->broadcastN1Pattern('select * from `users` where `id` = ?', 3, 'eager load');

        Event::assertDispatched(QueryCapturedEvent::class);
        Event::assertDispatched(SlowQueryDetectedEvent::class);
        Event::assertDispatched(N1PatternDetectedEvent::class);
    }

    public function testEnabledMonitorBroadcastsAllThreeKinds(): void
    {
        Event::fake();
        $sent = [];
        $pending = Mockery::mock(\Illuminate\Broadcasting\AnonymousEvent::class);
        $pending->shouldReceive('as')->andReturnUsing(function (string $event) use (&$sent, $pending) {
            $sent[] = $event;

            return $pending;
        });
        $pending->shouldReceive('with')->andReturnSelf();
        $pending->shouldReceive('sendNow')->times(3);
        Broadcast::shouldReceive('on')->times(3)->andReturn($pending);

        $monitor = new LiveQueryMonitor(enabled: true);
        $monitor->broadcastQuery($this->record());
        $monitor->broadcastSlowQuery($this->record());
        $monitor->broadcastN1Pattern('select * from `users` where `id` = ?', 3, 'eager load');

        $this->assertSame(['query.captured', 'query.slow', 'query.n1_detected'], $sent);
    }

    public function testPackageConfigDisablesLiveMonitorByDefault(): void
    {
        $config = require __DIR__.'/../../../src/Config/sql-monitor.php';

        $this->assertFalse($config['live_monitor']['enabled']);
    }

    private function record(): QueryRecord
    {
        return new QueryRecord(
            id: 'q_live',
            sql: 'select * from `users` where `id` = ?',
            bindings: [1],
            executionTimeMs: 150.0,
            connection: 'mysql',
            timestamp: 0.0,
            normalizedSql: 'select * from `users` where `id` = ?',
        );
    }
}

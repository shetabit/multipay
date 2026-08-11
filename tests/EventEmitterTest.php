<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\EventEmitter;

class EventEmitterTest extends TestCase
{
    public function testItCallsTheListenerOfADispatchedEvent(): void
    {
        $calls = 0;

        $emitter = new EventEmitter;
        $emitter->addEventListener('purchase', function () use (&$calls): void {
            ++$calls;
        });

        $emitter->dispatch('purchase');

        $this->assertSame(1, $calls);
    }

    public function testItPassesTheDispatchedArgumentsToTheListener(): void
    {
        $received = null;

        $emitter = new EventEmitter;
        $emitter->addEventListener('verify', function ($receipt, $driver) use (&$received): void {
            $received = [$receipt, $driver];
        });

        $emitter->dispatch('verify', 'the-receipt', 'the-driver');

        $this->assertSame(['the-receipt', 'the-driver'], $received);
    }

    public function testItCallsEveryListenerOfAnEvent(): void
    {
        $calls = [];

        $emitter = new EventEmitter;
        $emitter->addEventListener('pay', function () use (&$calls): void {
            $calls[] = 'first';
        });
        $emitter->addEventListener('pay', function () use (&$calls): void {
            $calls[] = 'second';
        });

        $emitter->dispatch('pay');

        $this->assertSame(['first', 'second'], $calls);
    }

    public function testItOnlyCallsTheListenersOfTheDispatchedEvent(): void
    {
        $calls = [];

        $emitter = new EventEmitter;
        $emitter->addEventListener('purchase', function () use (&$calls): void {
            $calls[] = 'purchase';
        });
        $emitter->addEventListener('verify', function () use (&$calls): void {
            $calls[] = 'verify';
        });

        $emitter->dispatch('purchase');

        $this->assertSame(['purchase'], $calls);
    }

    public function testDispatchingAnUnknownEventDoesNothing(): void
    {
        $emitter = new EventEmitter;
        $emitter->dispatch('unknown');

        $this->expectNotToPerformAssertions();
    }

    public function testASingleListenerCanBeRemoved(): void
    {
        $calls = [];

        $removed = function () use (&$calls): void {
            $calls[] = 'removed';
        };
        $kept = function () use (&$calls): void {
            $calls[] = 'kept';
        };

        $emitter = new EventEmitter;
        $emitter->addEventListener('purchase', $removed);
        $emitter->addEventListener('purchase', $kept);

        $emitter->removeEventListener('purchase', $removed);
        $emitter->dispatch('purchase');

        $this->assertSame(['kept'], $calls);
    }

    public function testAllListenersOfAnEventAreRemovedWhenNoListenerIsGiven(): void
    {
        $calls = 0;

        $emitter = new EventEmitter;
        $emitter->addEventListener('purchase', function () use (&$calls): void {
            ++$calls;
        });
        $emitter->addEventListener('purchase', function () use (&$calls): void {
            ++$calls;
        });

        $emitter->removeEventListener('purchase');
        $emitter->dispatch('purchase');

        $this->assertSame(0, $calls);
    }

    public function testRemovingAListenerOfAnUnknownEventDoesNothing(): void
    {
        $emitter = new EventEmitter;
        $emitter->removeEventListener('unknown', function (): void {
        });

        $this->expectNotToPerformAssertions();
    }

    public function testAnEventCanBeDispatchedByCallingItsName(): void
    {
        $received = null;

        $emitter = new EventEmitter;
        $emitter->addEventListener('purchase', function ($arguments) use (&$received): void {
            $received = $arguments;
        });

        $emitter->purchase('first', 'second');

        // __call() forwards its arguments as a single array.
        $this->assertSame(['first', 'second'], $received);
    }
}

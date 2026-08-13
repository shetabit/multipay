<?php

namespace Shetabit\Multipay\Traits;

use Shetabit\Multipay\EventEmitter;

trait HasPaymentEvents
{
    /**
     * Event registerar.
     */
    protected static EventEmitter|null $eventEmitter = null;

    /**
     * Add verification event listener.
     *
     *
     */
    public static function addPurchaseListener(callable $listener): void
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->addEventListener('purchase', $listener);
    }

    /**
     * Remove verification event listener.
     */
    public static function removePurchaseListener(callable|null $listener = null): void
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->removeEventListener('purchase', $listener);
    }

    /**
     * Add pay event listener.
     *
     *
     */
    public static function addPayListener(callable $listener): void
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->addEventListener('pay', $listener);
    }

    /**
     * Remove pay event listener.
     */
    public static function removePayListener(callable|null $listener = null): void
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->removeEventListener('pay', $listener);
    }

    /**
     * Add verification event listener.
     *
     *
     */
    public static function addVerifyListener(callable $listener): void
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->addEventListener('verify', $listener);
    }

    /**
     * Remove verification event listener.
     */
    public static function removeVerifyListener(callable|null $listener = null): void
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->removeEventListener('verify', $listener);
    }

    /**
     * Dispatch an event.
     */
    protected function dispatchEvent(string $event, mixed ...$arguments) : void
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->dispatch($event, ...$arguments);
    }

    /**
     * Add an singletone event registerar.
     */
    protected static function singletoneEventEmitter() : void
    {
        static::$eventEmitter ??= new EventEmitter();
    }
}

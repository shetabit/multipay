<?php

namespace Shetabit\Multipay\Traits;

use Shetabit\Multipay\EventEmitter;

trait HasPaymentEvents
{
    /**
     * Event registerar.
     *
     * @var EventEmitter
     */
    protected static $eventEmitter;

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
     *
     * @param callable|null $listener
     */
    public static function removePurchaseListener(callable $listener = null): void
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
     *
     * @param callable|null $listener
     */
    public static function removePayListener(callable $listener = null): void
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
     *
     * @param callable|null $listener
     */
    public static function removeVerifyListener(callable $listener = null): void
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->removeEventListener('verify', $listener);
    }

    /**
     * Dispatch an event.
     *
     * @param ...$arguments
     * @return void
     */
    protected function dispatchEvent(string $event, ...$arguments)
    {
        static::singletoneEventEmitter();

        static::$eventEmitter->dispatch($event, ...$arguments);
    }

    /**
     * Add an singletone event registerar.
     *
     * @return void
     */
    protected static function singletoneEventEmitter()
    {
        if (static::$eventEmitter instanceof EventEmitter) {
            return;
        }

        static::$eventEmitter = new EventEmitter;
    }
}

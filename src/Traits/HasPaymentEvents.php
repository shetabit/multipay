<?php

namespace Shetabit\Multipay\Traits;

use Shetabit\Multipay\EventEmitter;

trait HasPaymentEvents
{
    /**
     * Event registerer.
     *
     * @var EventEmitter
     */
    protected static $eventEmitter;

    /**
     * Add verification event listener.
     *
     * @param callable $listener
     *
     * @return void
     */
    public static function addPurchaseListener(callable $listener)
    {
        static::singletonEventEmitter();

        static::$eventEmitter->addEventListener('purchase', $listener);
    }

    /**
     * Remove verification event listener.
     *
     * @param callable|null $listener
     *
     * @return void
     */
    public static function removePurchaseListener(callable $listener = null)
    {
        static::singletonEventEmitter();

        static::$eventEmitter->removeEventListener('purchase', $listener);
    }

    /**
     * Add pay event listener.
     *
     * @param callable $listener
     *
     * @return void
     */
    public static function addPayListener(callable $listener)
    {
        static::singletonEventEmitter();

        static::$eventEmitter->addEventListener('pay', $listener);
    }

    /**
     * Remove pay event listener.
     *
     * @param callable|null $listener
     *
     * @return void
     */
    public static function removePayListener(callable $listener = null)
    {
        static::singletonEventEmitter();

        static::$eventEmitter->removeEventListener('pay', $listener);
    }

    /**
     * Add verification event listener.
     *
     * @param callable $listener
     *
     * @return void
     */
    public static function addVerifyListener(callable $listener)
    {
        static::singletonEventEmitter();

        static::$eventEmitter->addEventListener('verify', $listener);
    }

    /**
     * Remove verification event listener.
     *
     * @param callable|null $listener
     *
     * @return void
     */
    public static function removeVerifyListener(callable $listener = null)
    {
        static::singletonEventEmitter();

        static::$eventEmitter->removeEventListener('verify', $listener);
    }

    /**
     * Dispatch an event.
     *
     * @param string $event
     * @param ...$arguments
     *
     * @return void
     */
    protected function dispatchEvent(string $event, ...$arguments)
    {
        static::singletonEventEmitter();

        static::$eventEmitter->dispatch($event, ...$arguments);
    }

    /**
     * Add an singleton event registerer.
     *
     * @return void
     */
    protected static function singletonEventEmitter()
    {
        if (static::$eventEmitter instanceof EventEmitter) {
            return;
        }

        static::$eventEmitter = new EventEmitter;
    }
}

<?php

namespace Shetabit\Multipay;

class EventEmitter
{
    /**
     * List of listeners.
     *
     * @description a pair of $event => [array of listeners]
     */
    private array $listeners = [];

    /**
     * Add new listener fo given event.
     *
     *
     */
    public function addEventListener(string $event, callable $listener): void
    {
        if (empty($this->listeners[$event]) || !is_array($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = $listener;
    }

    /**
     * Remove given listener from a specefic event.
     * if we call this method without listener, it will totaly remove the given event and all of its listeners.
     *
     *
     */
    public function removeEventListener(string $event, callable $listener = null): void
    {
        if (empty($this->listeners[$event])) {
            return;
        }

        // remove the event and all of its listeners
        if (empty($listener)) {
            unset($this->listeners[$event]);

            return;
        }

        // remove only the given listener if exists
        $listenerIndex = array_search($listener, $this->listeners[$event]);

        if ($listenerIndex !== false) {
            unset($this->listeners[$event][$listenerIndex]);
        }
    }

    /**
     * Run event listeners.
     *
     * @param array ...$arguments
     *
     */
    public function dispatch(string $event, ...$arguments): void
    {
        $listeners = $this->listeners;

        if (empty($listeners[$event])) {
            return;
        }

        array_walk($listeners[$event], function ($listener) use ($arguments): void {
            call_user_func_array($listener, $arguments);
        });
    }

    /**
     * Call events by their name.
     *
     * @param array $arguments
     * @return void
     */
    public function __call(string $name, $arguments)
    {
        $this->dispatch($name, $arguments);
    }
}

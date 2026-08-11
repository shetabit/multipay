<?php

namespace Shetabit\Multipay;

class EventEmitter
{
    /**
     * The listeners of every event, as a pair of $event => [listeners].
     *
     * @var array<string, list<callable>>
     */
    private array $listeners = [];

    /**
     * Add new listener fo given event.
     */
    public function addEventListener(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Remove given listener from a specefic event.
     * if we call this method without listener, it will totaly remove the given event and all of its listeners.
     */
    public function removeEventListener(string $event, callable|null $listener = null): void
    {
        if (empty($this->listeners[$event])) {
            return;
        }

        // remove the event and all of its listeners
        if ($listener === null) {
            unset($this->listeners[$event]);

            return;
        }

        // remove only the given listener if exists
        $listenerIndex = array_search($listener, $this->listeners[$event], true);

        if ($listenerIndex !== false) {
            unset($this->listeners[$event][$listenerIndex]);
        }
    }

    /**
     * Run event listeners.
     */
    public function dispatch(string $event, mixed ...$arguments): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$arguments);
        }
    }

    /**
     * Call events by their name.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): void
    {
        $this->dispatch($name, $arguments);
    }
}

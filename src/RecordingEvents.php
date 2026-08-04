<?php

namespace Dynart\Dpress\Test;

use Dynart\Micro\EventServiceInterface;

/**
 * An event service that both records and dispatches
 *
 * Records every emit so a test can assert on the names and the arguments, and still calls the
 * subscribers so a test can act like a plugin.
 */
class RecordingEvents implements EventServiceInterface {

    /** The emitted event names, in order */
    public array $emitted = [];

    /** The arguments of every emit, in [event => args] format, last one wins */
    public array $args = [];

    private array $subscriptions = [];

    public function subscribeWithRef(string $event, &$callable): void {
        $this->subscribe($event, $callable);
    }

    public function subscribe(string $event, mixed $callable): void {
        if (!isset($this->subscriptions[$event])) {
            $this->subscriptions[$event] = [];
        }
        $this->subscriptions[$event][] = $callable;
    }

    public function unsubscribe(string $event, &$callable): bool {
        if (!isset($this->subscriptions[$event])) {
            return false;
        }
        foreach ($this->subscriptions[$event] as $key => $subscribed) {
            if ($subscribed === $callable) {
                unset($this->subscriptions[$event][$key]);
                return true;
            }
        }
        return false;
    }

    public function emit(string $event, array $args = []): void {
        $this->emitted[] = $event;
        $this->args[$event] = $args;
        foreach ($this->subscriptions[$event] ?? [] as $callable) {
            call_user_func_array($callable, $args);
        }
    }

    public function countOf(string $event): int {
        return count(array_filter($this->emitted, fn($e) => $e === $event));
    }
}

<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * A scoped task queue runs tasks per execution scope, such as a coroutine, and
 * cancels pending work tracked in a scope when that scope ends. Code that
 * starts pending work, such as an HTTP transfer, passes its promise to track()
 * when Utils::queue() returns a scoped task queue. The default TaskQueue does
 * not implement this interface.
 *
 * Coroutine applications need a separate queue for each coroutine. Promise
 * callbacks settle promises in the coroutine that drains the queue, and only
 * the coroutine that owns a pending promise may settle it.
 */
interface ScopedTaskQueueInterface extends TaskQueueInterface
{
    /**
     * Tracks pending work started in the current scope.
     *
     * @param PromiseInterface<mixed, mixed> $promise
     */
    public function track(PromiseInterface $promise): void;
}

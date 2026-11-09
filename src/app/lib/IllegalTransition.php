<?php
/**
 * A state change the machine's table does not allow.
 *
 * Extends InvalidArgumentException on purpose: every API route already turns
 * that into a 400 with the message, so a refused transition reaches the player
 * as a sentence rather than a 500.
 */

declare(strict_types=1);

class IllegalTransition extends InvalidArgumentException
{
    public function __construct(
        public readonly string $machine,
        public readonly string $from,
        public readonly string $to,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : "{$machine}: {$from} -> {$to} is not allowed.");
    }
}

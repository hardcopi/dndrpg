<?php
/**
 * One explicit state machine: named states, and the only transitions allowed
 * between them, declared as data.
 *
 * Why this exists. Lifecycles in this game were implicit: a status string
 * written by whichever method needed it, guarded (or not) by an in_array()
 * at the top of that method. Nothing said which moves were legal, so nothing
 * could refuse an illegal one, and an agent reading the code had to rebuild
 * the diagram from a dozen UPDATE statements.
 *
 * Each machine is declared once (see StateMachines::all()). The table is the
 * source of truth for three things that used to be able to disagree:
 *
 *   - the server, which calls guard() before it writes a status;
 *   - the client, which reads the same table from assets/js/state-machines.js;
 *   - docs/STATE_MACHINES.md, generated from the table by
 *     tools/gen_state_machines.php (verify.sh fails if either file is stale).
 *
 * A transition is [from, event, to]. `from` may be '*' for "any non-terminal
 * state". Re-entering the same state is only legal when the table says so.
 */

declare(strict_types=1);

final class StateMachine
{
    /** @var list<array{from:string, event:string, to:string}> */
    private array $edges = [];

    /**
     * @param array<string,string> $states   state => one-line meaning
     * @param list<array{0:string,1:string,2:string}> $transitions
     * @param list<string> $terminal
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $storage,
        public readonly array $states,
        public readonly string $initial,
        array $transitions,
        public readonly array $terminal = [],
        public readonly string $notes = ''
    ) {
        if (!isset($states[$initial])) {
            throw new LogicException("{$name}: initial state '{$initial}' is not declared");
        }
        foreach ($terminal as $t) {
            if (!isset($states[$t])) {
                throw new LogicException("{$name}: terminal state '{$t}' is not declared");
            }
        }
        foreach ($transitions as [$from, $event, $to]) {
            $froms = $from === '*'
                ? array_values(array_diff(array_keys($states), $terminal))
                : (array) $from;
            foreach ($froms as $f) {
                if (!isset($states[$f]) || !isset($states[$to])) {
                    throw new LogicException("{$name}: transition {$f} -{$event}-> {$to} names an undeclared state");
                }
                $this->edges[] = ['from' => $f, 'event' => $event, 'to' => $to];
            }
        }
    }

    /** @return list<string> */
    public function stateNames(): array
    {
        return array_keys($this->states);
    }

    public function has(string $state): bool
    {
        return isset($this->states[$state]);
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, $this->terminal, true);
    }

    /** @return list<array{from:string, event:string, to:string}> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** Is there any event that moves `from` to `to`? */
    public function can(string $from, string $to, ?string $event = null): bool
    {
        foreach ($this->edges as $e) {
            if ($e['from'] === $from && $e['to'] === $to && ($event === null || $e['event'] === $event)) {
                return true;
            }
        }
        return false;
    }

    /** Where `event` takes `from`, or null when the table has no such move. */
    public function next(string $from, string $event): ?string
    {
        foreach ($this->edges as $e) {
            if ($e['from'] === $from && $e['event'] === $event) {
                return $e['to'];
            }
        }
        return null;
    }

    /** @return list<string> events legal from `from` */
    public function eventsFrom(string $from): array
    {
        $out = [];
        foreach ($this->edges as $e) {
            if ($e['from'] === $from) {
                $out[$e['event']] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * The one gate every status write goes through.
     *
     * Logs and throws IllegalTransition on a move the table does not allow.
     * Callers that must not throw mid-request (the combat loop) use allows()
     * instead and keep the old state.
     */
    public function guard(string $from, string $to, ?string $event = null, array $context = []): void
    {
        if (!$this->allows($from, $to, $event, $context)) {
            throw new IllegalTransition($this->name, $from, $to);
        }
    }

    /** guard() without the throw: logs an illegal move and answers false. */
    public function allows(string $from, string $to, ?string $event = null, array $context = []): bool
    {
        if ($this->can($from, $to, $event)) {
            return true;
        }
        self::logIllegal($this->name, $from, $to, $event, $context);
        return false;
    }

    /** @var list<array> the last few refusals, for tests and debugging */
    public static array $refused = [];

    public static function logIllegal(string $machine, string $from, string $to, ?string $event, array $context = []): void
    {
        $entry = ['machine' => $machine, 'from' => $from, 'to' => $to, 'event' => $event, 'context' => $context];
        self::$refused[] = $entry;
        if (count(self::$refused) > 50) {
            array_shift(self::$refused);
        }
        if (!defined('STATE_MACHINE_QUIET')) {
            error_log('[state-machine] illegal transition ' . json_encode($entry, JSON_UNESCAPED_SLASHES));
        }
    }

    /** Machine-readable form, shipped to the client and to agents. */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'title'       => $this->title,
            'storage'     => $this->storage,
            'initial'     => $this->initial,
            'states'      => $this->states,
            'terminal'    => $this->terminal,
            'transitions' => $this->edges,
            'notes'       => $this->notes,
        ];
    }

    /** A mermaid stateDiagram-v2, edges with the same ends merged. */
    public function mermaid(): string
    {
        $lines = ['stateDiagram-v2', "    [*] --> {$this->initial}"];
        $merged = [];
        foreach ($this->edges as $e) {
            $k = $e['from'] . '>' . $e['to'];
            $merged[$k]['from'] = $e['from'];
            $merged[$k]['to'] = $e['to'];
            $merged[$k]['events'][] = $e['event'];
        }
        foreach ($merged as $m) {
            $lines[] = "    {$m['from']} --> {$m['to']} : " . implode(' / ', array_unique($m['events']));
        }
        foreach ($this->terminal as $t) {
            $lines[] = "    {$t} --> [*]";
        }
        return implode("\n", $lines);
    }
}

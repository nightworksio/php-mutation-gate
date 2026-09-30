<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_flip;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function implode;
use function sprintf;
use function var_export;

/**
 * The calls on the PHP builder that write part of a config (ADR-0002): those
 * on `Gate` itself, such as `->runner(Runner::pest())`, and those `with()`
 * takes, such as `Shards::max(4)`.
 */
final readonly class PhpCalls
{
    /**
     * @param list<array{string, list<string>}> $gate each method on `Gate`, with its arguments
     * @param list<string>                      $with
     */
    private function __construct(private array $gate, private array $with)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** A call on `Gate`, such as `runner(Runner::pest())`: the method, and each argument as PHP writes it. */
    public static function onGate(string $method, string ...$arguments): self
    {
        return new self([[$method, array_values($arguments)]], []);
    }

    /** Settings `with()` takes, such as `Shards::max(4)`. */
    public static function inWith(string ...$settings): self
    {
        return new self([], array_values($settings));
    }

    /** A value as PHP writes it. */
    public static function literal(string|int|float|bool $value): string
    {
        return var_export($value, return: true);
    }

    /** Values as PHP writes them, as the arguments of one call. */
    public static function literals(string|int|float|bool ...$values): string
    {
        return implode(', ', array_map(self::literal(...), $values));
    }

    /**
     * An adapter as the builder chooses it: the method of a builder class for a name it has one for, else
     * `uses()` with each option.
     */
    public static function chosen(Choice $choice, string $class, string ...$named): string
    {
        return $choice->options()->isEmpty() && array_key_exists($choice->use(), array_flip($named))
            ? sprintf('%s::%s()', $class, $choice->use())
            : sprintf(
                '%s::uses(%s)',
                $class,
                implode(', ', [self::literal($choice->use()), ...PhpOptions::of($choice->options())]),
            );
    }

    /** These calls, then more. */
    public function and(self $more): self
    {
        return new self([...$this->gate, ...$more->gate], [...$this->with, ...$more->with]);
    }

    /** The calls after `Gate::configure()`, one per line: those on `Gate`, then every other setting in `with()`. */
    public function code(): string
    {
        $calls = array_map(static fn(array $call): string => self::call($call[0], $call[1]), $this->gate);

        return implode('', $this->with === [] ? $calls : [...$calls, self::call('with', $this->with)]);
    }

    /** @param list<string> $arguments */
    private static function call(string $method, array $arguments): string
    {
        $lines = array_map(static fn(string $argument): string => sprintf("\n        %s,", $argument), $arguments);

        return match (count($arguments)) {
            0 => sprintf("\n    ->%s()", $method),
            1 => sprintf("\n    ->%s(%s)", $method, $arguments[0]),
            default => sprintf("\n    ->%s(%s\n    )", $method, implode('', $lines)),
        };
    }
}

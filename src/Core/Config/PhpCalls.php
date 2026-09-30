<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function array_values;
use function implode;
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

    /**
     * Values as PHP writes them, as the arguments of one call.
     *
     * @param list<string|int|float|bool> $values
     */
    public static function literals(array $values): string
    {
        return implode(', ', array_map(self::literal(...), $values));
    }

    /** These calls, then more. */
    public function and(self $more): self
    {
        return new self([...$this->gate, ...$more->gate], [...$this->with, ...$more->with]);
    }

    /** @return list<array{string, list<string>}> the calls on `Gate`, in order, each its method and arguments */
    public function gateCalls(): array
    {
        return $this->gate;
    }

    /** @return list<string> the settings `with()` takes, in order */
    public function withCalls(): array
    {
        return $this->with;
    }
}

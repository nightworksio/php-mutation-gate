<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function is_string;

/**
 * One line of a split stream's results, read (ADR-0117): what kind it is,
 * the mutant and test it names, whether the parent passed, or the child
 * failed, the test.
 *
 * @internal
 */
final readonly class StreamRead
{
    private function __construct(
        public string $kind,
        public string $mutant,
        public string $test,
        public bool $passed,
        public bool $failed,
        public bool $child = false,
        public string $digest = '',
        public bool $touched = false,
    ) {
    }

    /** @param array<mixed> $read a line decoded */
    public static function of(array $read): self
    {
        return new self(
            self::text($read, StreamLine::KIND),
            self::text($read, 'm'),
            self::text($read, 't'),
            array_key_exists('p', $read) && $read['p'] === true,
            array_key_exists('f', $read) && $read['f'] === true,
            array_key_exists('c', $read) && $read['c'] === true,
            self::text($read, 'd'),
            array_key_exists('n', $read) && $read['n'] === true,
        );
    }

    /** How the child came out of the test, where the line says how one did. */
    public function childEnd(): ChildEnd|false
    {
        return match ($this->kind) {
            StreamLine::CHILD => $this->failed ? ChildEnd::Failed : ChildEnd::Passed,
            StreamLine::ENDED => ChildEnd::Ended,
            StreamLine::TIMEOUT => ChildEnd::TimedOut,
            default => false,
        };
    }

    /** @param array<mixed> $read */
    private static function text(array $read, string $key): string
    {
        return array_key_exists($key, $read) && is_string($read[$key]) ? $read[$key] : '';
    }
}

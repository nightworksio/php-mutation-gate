<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_map;
use function implode;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function sort;
use function sprintf;

/**
 * How far a killed mutant's own run went: the position its first failing
 * test held in the order the run took its tests in, counting from one,
 * which is how many tests a run that stops at its first failure ran; and,
 * where the runner can tell, a key a baseline that replays that order
 * unmutated is shared by: the first twelve hex digits of the SHA-256 digest
 * of the test files the run loaded, sorted, a line each, an empty line, then
 * the digest of its order up to its last failing test (OrderDigest).
 */
final readonly class Prefix
{
    /** The pattern a key matches: twelve lowercase hex digits. */
    private const string KEY = '/^[0-9a-f]{12}$/';

    /** How many hex digits of the digest a key keeps. */
    private const int KEPT = 12;

    private function __construct(private int $position, private string|NotGiven $key)
    {
    }

    /** A run whose first failing test held this position, from one, with no key. */
    public static function at(int $position): self
    {
        return new self($position, NotGiven::value());
    }

    /** A run whose first failing test held this position, from one, with this key. */
    public static function keyedAt(int $position, string $key): self
    {
        return new self($position, $key);
    }

    /** A prefix as a record holds it, or none where its position or key cannot be one. */
    public static function read(int $position, string|NotGiven $key): self|NotGiven
    {
        $keyed = $key instanceof NotGiven || preg_match(self::KEY, $key) === 1;

        return $position >= 1 && $keyed ? new self($position, $key) : NotGiven::value();
    }

    /**
     * The key of a run that loaded these test files, or named none, and took
     * its tests in an order of this digest (OrderDigest), in lowercase hex, up
     * to its last failing test.
     */
    public static function keyOf(Paths $files, string $order): string
    {
        $named = array_map(static fn(Path $file): string => $file->value(), [...$files]);
        sort($named);
        $lines = $named === [] ? '' : sprintf("%s\n", implode("\n", $named));

        return mb_substr(Digest::sha256Of(sprintf("%s\n%s", $lines, $order))->value(), 0, self::KEPT);
    }

    /** The position the run's first failing test held, from one. */
    public function position(): int
    {
        return $this->position;
    }

    /** The key a baseline replaying the run's order is shared by, where the runner could tell. */
    public function key(): string|NotGiven
    {
        return $this->key;
    }
}

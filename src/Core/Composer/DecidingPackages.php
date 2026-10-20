<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_any;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The packages whose move in a `composer.lock` decides how the gate runs,
 * as `reach.lockPackages` names them (ADR-0030): every package, unless the
 * config names some, each by its name or a pattern of names, `pestphp/*`.
 */
final readonly class DecidingPackages
{
    private function __construct(private Globs|NotGiven $named)
    {
    }

    /** Every package: any move in a lock decides how the gate runs. */
    public static function every(): self
    {
        return new self(NotGiven::value());
    }

    /** The packages these patterns of names match. */
    public static function named(Glob ...$patterns): self
    {
        return new self(Globs::of(...$patterns));
    }

    /**
     * Whether the change from the lock at the base to the lock now decides how
     * the gate runs: it moved a package these name, or a key besides the
     * package lists, or either lock cannot be read.
     */
    public function decide(Contents $before, Contents $now): bool
    {
        $named = $this->named;

        if ($named instanceof NotGiven) {
            return true;
        }

        $moves = LockMoves::between($before, $now);

        return ! $moves instanceof LockMoves
            || $moves->beyondPackages()
            || array_any($moves->packages(), static fn(string $name): bool => $named->matches(Path::of($name)));
    }
}

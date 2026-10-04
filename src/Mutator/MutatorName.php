<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use NightWorksIO\MutationGate\Core\Mutant\MutatorNamePattern;

use function preg_match;
use function sprintf;

/**
 * A mutator's name, `<set>/<Name>`, such as `laravel/GateAllowsToTrue`: its
 * set's name as a config writes it, and its own in the case a class is.
 */
final readonly class MutatorName
{
    /** A whole name, as a pattern matches it. */
    private const string WHOLE = '/^%s$/D';

    private function __construct(private string $set, private string $own)
    {
    }

    /** @throws NotAMutatorName where either part is not written as a name is */
    public static function of(string $set, string $own): self
    {
        return self::matches(MutatorNamePattern::SET, $set) && self::matches(MutatorNamePattern::OWN, $own)
            ? new self($set, $own)
            : throw NotAMutatorName::of($set, $own);
    }

    /** The set it belongs to, as a config names it. */
    public function set(): string
    {
        return $this->set;
    }

    /** Its own name, within its set. */
    public function own(): string
    {
        return $this->own;
    }

    /** `<set>/<Name>`, as reports, ids and ignores write it. */
    public function value(): string
    {
        return sprintf('%s/%s', $this->set, $this->own);
    }

    private static function matches(string $pattern, string $name): bool
    {
        return preg_match(sprintf(self::WHOLE, $pattern), $name) === 1;
    }
}

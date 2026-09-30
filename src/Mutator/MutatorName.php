<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use function preg_match;
use function sprintf;

/**
 * A mutator's name, `<set>/<Name>`, such as `laravel/GateAllowsToTrue`: its
 * set's name as a config writes it, and its own in the case a class is.
 */
final readonly class MutatorName
{
    /** A set's name: lower case letters and digits, joined by single hyphens. */
    private const string SET = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    /** A mutator's own name: a letter, then letters and digits. */
    private const string OWN = '/^[A-Z][A-Za-z0-9]*$/D';

    private function __construct(private string $set, private string $own)
    {
    }

    /** @throws NotAMutatorName where either part is not written as a name is */
    public static function of(string $set, string $own): self
    {
        return preg_match(self::SET, $set) === 1 && preg_match(self::OWN, $own) === 1
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
}

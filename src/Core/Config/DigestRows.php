<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * How much the digest says of one mutant (ADR-0009): `digest`, which the
 * `Reports` part holds, and `digest --tests=<n>` overrides.
 */
final readonly class DigestRows
{
    /** How many of a mutant's covering tests the digest names, where the config does not say. */
    public const int TESTS = 5;

    private function __construct(private int|Absent $tests)
    {
    }

    public static function of(int|Absent $tests = new Absent()): self
    {
        return new self($tests);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(Absent::laid($this->tests, $later->tests));
    }

    /** `digest.tests`: how many of a mutant's covering tests `digest --detail=<id>` names. */
    public function tests(): int
    {
        return is_int($this->tests) ? $this->tests : self::TESTS;
    }

    /** The `digest` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        return Member::unlessEmpty('digest', Json::object(Member::of('tests', $this->tests)));
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        return is_int($this->tests)
            ? PhpCalls::inWith(sprintf('Digest::tests(%d)', $this->tests))
            : PhpCalls::none();
    }
}

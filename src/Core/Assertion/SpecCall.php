<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use NightWorksIO\MutationGate\Core\Php\Spec;

use function sprintf;

/**
 * What a method call in a PhpSpec spec checks (ADR-0025): a `should…`
 * matcher by the table, unless the spec names it as its own; and nothing
 * for any other call, such as one on `$this` of a method of the object the
 * spec describes, or a collaborator's, but a call of a helper the spec
 * declares, which leaves the example not assessed.
 */
final readonly class SpecCall
{
    /**
     * What a call checks, in a spec, where it calls a helper or not.
     *
     * @return list<Assertion|Unclassified>
     */
    public static function checked(Call $call, Spec $spec, bool $helper): array
    {
        $keyword = AssertionTable::keyword($call);
        $kind = $spec->hasMatcher($keyword) ? Unclassified::assertion() : AssertionTable::phpSpec($call);
        $assertion = $kind instanceof AssertionKind
            ? Assertion::of(sprintf(AssertionScan::CHAINED, $call->name()), $kind, AssertionStyle::PhpSpec)
            : $kind;

        return match (true) {
            $keyword !== '' => [$assertion],
            $helper => [Unclassified::assertion()],
            default => [],
        };
    }
}

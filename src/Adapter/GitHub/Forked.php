<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * Whether a pull request, as an event payload holds it, comes from a fork:
 * its head is in another repository than its base, or in one that is gone, as
 * GitHub says by a head repository of null. A payload that names no head
 * repository names no fork.
 *
 * @internal
 */
final readonly class Forked
{
    /** A pull request's run, marked as from a fork where the payload's pull request comes from one. */
    public static function marking(RunOn|CannotTell $run, Node|CannotTell $payload): RunOn|CannotTell
    {
        return $run instanceof RunOn && $payload instanceof Node && self::pull($payload->field('pull_request'))
            ? $run->fromAFork()
            : $run;
    }

    /** Whether this pull request, the payload's `pull_request`, comes from a fork. */
    public static function pull(Node $pull): bool
    {
        $head = $pull->field('head')->field('repo');
        $base = $pull->field('base')->field('repo');

        try {
            return $head->isPresent() && ($head->kind() === Kind::Null || self::nameOf($head) !== self::nameOf($base));
        } catch (NotInShape) {
            return true;
        }
    }

    /** @throws NotInShape where a repository names itself by no text */
    private static function nameOf(Node $repository): string
    {
        return $repository->field('full_name')->text();
    }
}

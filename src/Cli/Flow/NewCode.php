<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;

use function sprintf;

/**
 * The lines the verdict holds to the floor for new code: those the change
 * added or modified. A pull request's or merge group's new code is judged
 * whatever the run considers, so where the run does not know its lines,
 * as a full run does not, they are those since its base: the branch a
 * pull request merges into, or the commit a merge group was built on.
 *
 * @internal
 */
final readonly class NewCode
{
    private const string UNTOLD = <<<'SAID'
        A pull request's or merge group's new code is judged against %s, and git cannot tell what changed since it,
        so no floor for new code can be held. %s
        Fetch it into the checkout before the plan.
        SAID;

    public function __construct(private Adapters $adapters)
    {
    }

    /** The new code of a run that stands here and reached this; or why a proposed change's cannot be told. */
    public function of(Standing $standing, Reached $reached): Changes|CannotJudge
    {
        $changed = $reached->changed();
        $fetched = $standing->proposedBase();
        $changed = $changed instanceof CannotTell && $standing->runOn()->isProposed()
            ? Reached::linesSince($fetched, $this->adapters)
            : $changed;

        return match (true) {
            $changed instanceof Changes => $changed,
            $standing->runOn()->isProposed() => CannotJudge::because(
                sprintf(self::UNTOLD, $fetched->name(), $changed->why()),
            ),
            default => Changes::none(),
        };
    }
}

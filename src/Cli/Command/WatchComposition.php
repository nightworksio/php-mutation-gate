<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Outbreak\HeldLedgers;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\ServedProofs;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\KeepsProofs;
use Symfony\Component\Console\Input\InputInterface;

/**
 * What each round of `watch` runs with: `local.watchBudget` as its budget,
 * unless `--budget` sets one, and, where `outbreak serve` keeps proofs, the
 * ledgers it reads and writes held between the rounds (ADR-0133).
 *
 * @internal
 */
final readonly class WatchComposition
{
    /** The composition each round runs with, or why there is none. */
    public static function of(Composition $composition, InputInterface $input): Composed|Invalid|CannotJudge
    {
        $composed = $composition->composeForWatch($input);
        $composed = $composed instanceof Composed ? self::budgeted($composed, $input) : $composed;

        return $composed instanceof Composed ? self::holding($composed) : $composed;
    }

    /** The ledgers a composition's rounds hold between them, where `outbreak serve` keeps proofs. */
    public static function held(Composed $composed): HeldLedgers|NotGiven
    {
        $proofs = $composed->adapters->proofs;

        return $proofs instanceof HeldLedgers ? $proofs : NotGiven::value();
    }

    /** The composition with `local.watchBudget` as each round's budget, unless `--budget` sets one. */
    private static function budgeted(Composed $composed, InputInterface $input): Composed|Invalid
    {
        return CommandLine::from($input)->budget instanceof NotGiven
            ? $composed->budgeted($composed->settings->local()->watchBudget())
            : $composed;
    }

    /** The composition reading and writing ledgers held between the rounds, where `outbreak serve` keeps proofs. */
    private static function holding(Composed $composed): Composed
    {
        $state = ServedProofs::state($composed->adapters);

        return $state instanceof KeepsProofs
            ? new Composed(
                $composed->settings,
                $composed->adapters->withProofs(new HeldLedgers($composed->adapters->proofs, $state)),
                $composed->setup,
                $composed->reporting,
                $composed->ruling,
            )
            : $composed;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_diff;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_values;

use Pest\Mutate\MutationTest;

use function strval;

/**
 * What a second pass of split streams streams once the first ended
 * (ADR-0117): the sites of the mutants the first left undecided, and the
 * covering tests it streams for each.
 *
 * @internal
 */
final readonly class SecondPass
{
    /**
     * @param array<string, array{string, int, string, array<string, string>}> $sites    each mutant's file key, switch
     *                                                                                    id, first fork and variables
     * @param array<string, list<string>>                                       $covering the covering tests the shard
     *                                                                                    judges each mutant by
     * @param array<string, list<string>>                                       $held     those of them the shard's
     *                                                                                    part holds
     * @param array<string, true>                                               $cheapest each mutant first forked at
     *                                                                                    its cheapest covering test
     */
    public function __construct(
        private array $sites,
        private array $covering,
        private array $held,
        private array $cheapest,
    ) {
    }

    /**
     * The sites a second pass streams, and the covering tests it streams for
     * each, after a first pass that gave these verdicts and left these sites
     * unrun.
     *
     * @param  array<string, MutationTest>                                      $byId
     * @param  array<string, array{string, int, string, array<string, string>}> $unrun
     * @return array{
     *     array<string, array{string, int, string, array<string, string>}>,
     *     array<string, list<string>>,
     * }
     */
    public function of(array $byId, array $unrun, StreamVerdicts $verdicts, StreamSettings $settings): array
    {
        $again = $this->streamedAgain($this->undecided($byId, $verdicts), $verdicts, $settings)
            + $unrun
            + $this->booted($verdicts);

        return [$again, $this->left($again, $unrun, $verdicts)];
    }

    /**
     * The sites of the mutants whose site ran while a first-pass stream booted
     * its application once, which no test forked from it could reach: a
     * second pass boots the application in every test, as a run without
     * `splitStream.bootOnce` does.
     *
     * @return array<string, array{string, int, string, array<string, string>}>
     */
    private function booted(StreamVerdicts $verdicts): array
    {
        $again = [];

        foreach ($verdicts->booted() as $mutant) {
            if (array_key_exists($mutant, $this->sites)) {
                [$key, $id, , $variables] = $this->sites[$mutant];
                $again[$mutant] = [$key, $id, '', $variables];
            }
        }

        return $again;
    }

    /**
     * The sites of the mutants the first pass left undecided that a second
     * forks at every covering test it has not yet: one forked first at its
     * likely killer alone, one whose covering tests the shard's part does
     * not hold every one of, and one forked first at its cheapest covering
     * test that no child killed there, while it has others (ADR-0187).
     *
     * @param  array<string, MutationTest>                                      $byId
     * @return array<string, array{string, int, string, array<string, string>}>
     */
    private function undecided(array $byId, StreamVerdicts $verdicts): array
    {
        $again = [];

        foreach ($byId as $test) {
            $mutant = $test->mutation->id;
            [$key, $id, $fork, $variables] = $this->sites[$mutant];
            $covering = $this->covering[$mutant];
            $unfinished = array_key_exists($mutant, $this->cheapest)
                ? $this->unforked($mutant, $fork, $verdicts) !== [] && ! $verdicts->kills($mutant, $covering)
                : ($fork !== '' || $this->held[$mutant] !== $covering) && ! $verdicts->decides($mutant, $covering);

            if ($unfinished) {
                $again[$mutant] = [$key, $id, '', $variables];
            }
        }

        return $again;
    }

    /**
     * The covering tests of a mutant the first pass forked at its cheapest
     * one that it did not fork it at: every one but that, or every one
     * where it left the mutant there (ADR-0187).
     *
     * @return list<string>
     */
    private function unforked(string $mutant, string $fork, StreamVerdicts $verdicts): array
    {
        $covering = $this->covering[$mutant];

        return $verdicts->leaves($mutant, $fork) ? $covering : array_values(array_diff($covering, [$fork]));
    }

    /**
     * The sites of these undecided mutants a second pass streams: under
     * `splitStream.fallbackOwnRun`, none whose first fork the first pass
     * fell back on for it, which its own run judges, that test first
     * (ADR-0179).
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $undecided
     * @return array<string, array{string, int, string, array<string, string>}>
     */
    private function streamedAgain(array $undecided, StreamVerdicts $verdicts, StreamSettings $settings): array
    {
        if (! $settings->fallsBackToOwnRuns()) {
            return $undecided;
        }

        $again = [];

        foreach ($undecided as $mutant => $site) {
            $fork = $this->sites[$mutant][2];

            if ($fork === '' || ! $verdicts->leaves(strval($mutant), $fork)) {
                $again[$mutant] = $site;
            }
        }

        return $again;
    }

    /**
     * The covering tests a second pass streams for each of these mutants:
     * those the first pass did not fork it at, or every one where it forked
     * it at its likely killer alone, at none, or under an application booted
     * once, which no forked test could reach; of one it forked at its
     * cheapest covering test, every other (ADR-0187).
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $again
     * @param  array<string, array{string, int, string, array<string, string>}> $unrun
     * @return array<string, list<string>>
     */
    private function left(array $again, array $unrun, StreamVerdicts $verdicts): array
    {
        $booted = array_flip($verdicts->booted());
        $left = [];

        foreach (array_keys($again) as $mutant) {
            $mutant = strval($mutant);
            $covering = $this->covering[$mutant];
            $fork = $this->sites[$mutant][2];
            $rest = match (true) {
                array_key_exists($mutant, $unrun), array_key_exists($mutant, $booted) => [],
                array_key_exists($mutant, $this->cheapest) => $this->unforked($mutant, $fork, $verdicts),
                $fork !== '' => [],
                default => array_values(array_diff($covering, $this->held[$mutant])),
            };
            $left[$mutant] = $rest === [] ? $covering : $rest;
        }

        return $left;
    }
}

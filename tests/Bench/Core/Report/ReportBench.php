<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Bench\Core\Report;

use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

use function sprintf;

use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/** Rendering a verdict's JSON report and its sticky comment, which the verdict job writes for every run. */
final class ReportBench
{
    /** @return iterable<string, array{int, Verdict}> */
    public static function verdicts(): iterable
    {
        foreach ([100, 400, 1600] as $mutants) {
            yield sprintf('mutants-%d', $mutants) => [$mutants, self::verdictOf($mutants)];
        }
    }

    #[DataProvider('verdicts')]
    #[Linear('mutants')]
    #[Warmup(1)]
    public function benchJson(int $mutants, Verdict $verdict): void
    {
        JsonReport::encode($verdict);
    }

    #[DataProvider('verdicts')]
    #[Linear('mutants')]
    #[Warmup(1)]
    public function benchComment(int $mutants, Verdict $verdict): void
    {
        Markdown::comment($verdict, CommentShows::unfolded());
    }

    /** A verdict of so many mutants over fifty files, killed, survived and ignored in turn. */
    private static function verdictOf(int $mutants): Verdict
    {
        $judged = [];
        $judgements = [MutantJudgement::Killed, MutantJudgement::Survived, MutantJudgement::Killed, MutantJudgement::Ignored];

        for ($at = 0; $at < $mutants; $at++) {
            $judged[] = JudgedMutant::of(
                Verdicts::mutant(sprintf('src/File%d.php:%d', $at % 50, 1 + $at % 200), 'Plus', MutatorFamily::None, ''),
                $judgements[$at % 4],
            );
        }

        return Verdicts::of(Floor::of(80), ...$judged);
    }
}

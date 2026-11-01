<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function implode;

use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;

use function sprintf;

/**
 * The top of the HTML report: the verdict, a plain sentence on what mutation
 * testing is, why, and what to do, the JSON report's `why` and `next`.
 *
 * @internal
 *
 * @phpstan-import-type Report from JsonReport
 */
final readonly class Headline
{
    private const string HEAD = <<<'HTML'
        <header class="headline headline--%1$s">
        <p class="brand">mutation-gate</p>
        <h1><span class="status status--%1$s">%2$s</span>%3$s</h1>
        %5$s
        <ul class="why">%4$s</ul>
        <section class="next" aria-labelledby="next-title">
        <h2 id="next-title">What to do</h2>
        <ul>%6$s</ul>
        </section>
        </header>
        HTML;

    private const string QUALIFIER = ' <span class="qualifier">%s</span>';

    /** What a sampled run's verdict says beside it. */
    private const string SAMPLED = 'sampled';

    /** What a run stopped before it judged every mutant says beside its verdict. */
    private const string CUT_SHORT = 'cut short';

    /** @param Report $report */
    public static function html(array $report, Glossary $glossary): string
    {
        $judgement = Judgement::from($report['judgement']);
        $why = [];
        $next = [];

        foreach ($report['why'] as $reason) {
            $why[] = Html::text($reason);
        }

        foreach ($report['next'] as $step) {
            $next[] = Html::prose($step);
        }

        return sprintf(
            self::HEAD,
            $judgement->value,
            Html::text(self::label($judgement)),
            implode('', [
                array_key_exists('sample', $report) ? sprintf(self::QUALIFIER, self::SAMPLED) : '',
                $report['cutShort'] ? sprintf(self::QUALIFIER, self::CUT_SHORT) : '',
            ]),
            Html::items($why),
            $glossary->intro(),
            Html::items($next),
        );
    }

    private static function label(Judgement $judgement): string
    {
        return match ($judgement) {
            Judgement::Passed => 'Passed',
            Judgement::Failed => 'Failed',
            Judgement::NothingToMutate, Judgement::Exempt,
            Judgement::Undecided, Judgement::CannotJudge => 'Cannot judge',
        };
    }
}

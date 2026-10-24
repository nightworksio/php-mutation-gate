<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function implode;

use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;

use function sprintf;

/**
 * The top of the HTML report: the verdict and why. What decides a verdict
 * comes first: what kept the run from judging, each set below its floor, as
 * every report says it, and each failure no floor decides. A verdict nothing
 * failed says the project's score.
 *
 * @phpstan-import-type Report from JsonReport
 */
final readonly class Headline
{
    private const string HEAD = <<<'HTML'
        <header class="headline headline--%1$s">
        <p class="brand">mutation-gate</p>
        <h1><span class="status status--%1$s">%2$s</span>%3$s</h1>
        <ul class="why">%4$s</ul>
        </header>
        HTML;

    private const string QUALIFIER = ' <span class="qualifier">%s</span>';

    /** What a sampled run's verdict says beside it. */
    private const string SAMPLED = 'sampled';

    /** What a run stopped before it judged every mutant says beside its verdict. */
    private const string CUT_SHORT = 'cut short';


    /** @param Report $report */
    public static function html(array $report): string
    {
        $judgement = Judgement::from($report['judgement']);

        return sprintf(
            self::HEAD,
            $judgement->value,
            Html::text(self::label($judgement)),
            implode('', [
                array_key_exists('sample', $report) ? sprintf(self::QUALIFIER, self::SAMPLED) : '',
                $report['cutShort'] ? sprintf(self::QUALIFIER, self::CUT_SHORT) : '',
            ]),
            Html::items(self::why($report)),
        );
    }

    private static function label(Judgement $judgement): string
    {
        return match ($judgement) {
            Judgement::Passed => 'Passed',
            Judgement::Failed => 'Failed',
            Judgement::CannotJudge,
            Judgement::NothingToMutate,
            Judgement::Exempt,
            Judgement::Undecided => 'Cannot judge',
        };
    }

    /**
     * Why, a sentence each, escaped.
     *
     * @param  Report       $report
     * @return list<string>
     */
    private static function why(array $report): array
    {
        $why = [];

        foreach ([...$report['trees'], ...$report['newCode'], ...$report['security']] as $set) {
            $why[] = $set['judgement'] === Judgement::Failed->value ? Html::text($set['said']) : '';
        }

        foreach ([...$report['cannotJudge'], ...$report['failures']] as $said) {
            $why[] = Html::text($said);
        }

        return [
            ...$why,
            $report['cutShort'] ? SetText::CUT_SHORT : '',
            Html::text(SetText::project(self::score($report))),
        ];
    }

    /** @param Report $report */
    private static function score(array $report): Score|NothingToMutate
    {
        return array_key_exists('score', $report)
            ? Score::ofHundredths(Html::hundredths($report['score']))
            : NothingToMutate::found();
    }
}

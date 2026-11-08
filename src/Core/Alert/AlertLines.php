<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Report\Mutator;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;

use function sprintf;

/**
 * What an alert says, in groups under a heading, for a chat to lay out: the
 * failing trees with floor, score and previous score, the failures no floor
 * decides and the first survivors; why the run cannot judge; each tree
 * again after a recovery; or each lowered floor with its reason
 * (ADR-0016). Whatever the project wrote is cut to the reporter's
 * `lineLength`, and it names as many survivors as its `survivors` say.
 *
 * @internal
 */
final readonly class AlertLines
{
    /** A tree's or a set's line: its name, its score, how it stands against its floor, and what it was. */
    private const string LINE = '%s: %s%s%s.';

    private const string BELOW = ', below its floor of %s';

    private const string AGAINST = ' against its floor of %s';

    private const string WAS = '; it was %s';

    private const string LOWERED = '%s: its floor went from %s to %s. %s';

    private const string NO_REASON = 'The baseline gives no reason.';

    private function __construct(private Chat $chat, private AlertShape $shape)
    {
    }

    /**
     * Each group's heading and lines, leaving out a group with none.
     *
     * @return list<array{string, list<string>}>
     */
    public static function of(Alert $alert, Chat $chat, AlertShape $shape): array
    {
        return new self($chat, $shape)->groups($alert);
    }

    /** @return list<array{string, list<string>}> */
    private function groups(Alert $alert): array
    {
        $verdict = $alert->verdict();
        $groups = match ($alert->event()) {
            AlertEvent::Failed => [
                ['Below the floor', $this->trees($alert, Judgement::Failed)],
                ['Security below its floor', $this->security($alert)],
                ['Owners below their floor', $this->ownerFloors($alert)],
                ['Failures', $this->said($verdict->failures())],
                ['Survivors', $this->survivors($alert)],
            ],
            AlertEvent::CannotJudge => [['Why', $this->said($verdict->obstacles())]],
            AlertEvent::Recovered => [['Trees', $this->trees($alert, Judgement::Passed)]],
            AlertEvent::FloorLowered => [['Floors lowered', $this->lowered($alert)]],
        };
        $shown = [];

        foreach ($groups as [$heading, $lines]) {
            $shown = $lines === [] ? $shown : [...$shown, [$heading, $lines]];
        }

        return $shown;
    }

    /**
     * Each tree the verdict judged so, with its score, floor and previous score.
     *
     * @return list<string>
     */
    private function trees(Alert $alert, Judgement $judged): array
    {
        $lines = [];

        foreach ($alert->verdict()->trees() as $tree) {
            $lines = $tree->judgement() === $judged ? [...$lines, $this->tree($alert, $tree)] : $lines;
        }

        return $lines;
    }

    /**
     * Each package's security set that failed, with its score and floor (ADR-0021).
     *
     * @return list<string>
     */
    private function security(Alert $alert): array
    {
        $lines = [];

        foreach ($alert->verdict()->sets()->security() as $set) {
            $floor = $set->floor();
            $lines = $set->judgement() === Judgement::Failed
                ? [...$lines, sprintf(
                    self::LINE,
                    $this->chat->text(Fit::line(SetText::securityName($set), $this->shape->lineLength())),
                    Percent::of($set->score()),
                    $this->against($floor, self::BELOW),
                    '',
                )]
                : $lines;
        }

        return $lines;
    }

    /**
     * Each owner below its own floor, with its score and floor (ADR-0022).
     *
     * @return list<string>
     */
    private function ownerFloors(Alert $alert): array
    {
        $lines = [];

        foreach ($alert->verdict()->sets()->ownerFloors() as $owner) {
            $lines = $owner->judgement() === Judgement::Failed
                ? [...$lines, sprintf(
                    self::LINE,
                    $this->chat->text(Fit::line(SetText::ownerFloorName($owner), $this->shape->lineLength())),
                    Percent::of($owner->score()),
                    $this->against($owner->floor(), self::BELOW),
                    '',
                )]
                : $lines;
        }

        return $lines;
    }

    private function tree(Alert $alert, TreeVerdict $tree): string
    {
        $path = $tree->tree()->path();
        $floor = $tree->floor();
        $was = $alert->previous()->scoreOf($path);

        return sprintf(
            self::LINE,
            $this->chat->code(Fit::line($path->value(), $this->shape->lineLength())),
            Percent::of($tree->score()),
            $this->against($floor, $tree->judgement() === Judgement::Failed ? self::BELOW : self::AGAINST),
            $was instanceof Score ? sprintf(self::WAS, Percent::of($was)) : '',
        );
    }

    /** How a score stands against a floor, said this way; nothing where no floor holds it. */
    private function against(Floor|Exempt|Undeclared $floor, string $standing): string
    {
        return $floor instanceof Floor ? sprintf($standing, Percent::of($floor)) : '';
    }

    /**
     * The first survivors in a set that failed, those on changed lines first.
     *
     * @return list<string>
     */
    private function survivors(Alert $alert): array
    {
        $overview = Overview::of($alert->verdict());
        $failing = [];

        foreach ($overview->survivors() as $judged) {
            $failing = $overview->isFailing($judged) ? [...$failing, $judged] : $failing;
        }

        $lines = [];

        foreach (array_slice($failing, 0, $this->shape->survivors()) as $judged) {
            $lines[] = $this->survivor($judged);
        }

        return count($failing) > $this->shape->survivors()
            ? [...$lines, Fit::more(count($failing) - $this->shape->survivors())]
            : $lines;
    }

    private function survivor(JudgedMutant $judged): string
    {
        $mutant = $judged->mutant();
        $location = $mutant->location();

        return sprintf(
            '%s %s, %s',
            $this->chat->code(Fit::line(
                sprintf('%s:%d', $location->file()->value(), $location->start()->number()),
                $this->shape->lineLength(),
            )),
            $this->chat->text(Fit::line(Mutator::short($mutant->mutator()), $this->shape->lineLength())),
            Label::of($judged->judgement()),
        );
    }

    /**
     * Each tree whose floor went down, with the reason its baseline gives.
     *
     * @return list<string>
     */
    private function lowered(Alert $alert): array
    {
        $lines = [];

        foreach ($alert->lowered() as $floor) {
            $lowering = $floor->lowering();
            $lines[] = sprintf(
                self::LOWERED,
                $this->chat->code(Fit::line($floor->tree()->value(), $this->shape->lineLength())),
                Percent::of($floor->from()),
                Percent::of($floor->to()),
                $lowering instanceof Lowered
                    ? $this->chat->text(Fit::line($lowering->reason(), $this->shape->lineLength()))
                    : self::NO_REASON,
            );
        }

        return $lines;
    }

    /**
     * Each failure's or obstacle's sentence, cut to length.
     *
     * @param  iterable<Failure|CannotJudge> $items
     * @return list<string>
     */
    private function said(iterable $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $said = $item instanceof Failure ? $item->text() : $item->why();
            $lines[] = $this->chat->text(Fit::line($said, $this->shape->lineLength()));
        }

        return $lines;
    }
}

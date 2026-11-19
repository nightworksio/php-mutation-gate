<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\Control\AskingKinds;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlKind;
use NightWorksIO\MutationGate\Core\Control\ControlTime;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The steps a shard's time went to, as its result holds them under
 * `measured.steps` (ADR-0016): each its `step`, the seconds
 * after the shard began that it started, `since`, the seconds it took,
 * where it handled more than one, how many as `count`, and where it ran
 * unmutated controls, each as `controls` (ADR-0167): what asked for it,
 * `kinds`, the tests it asked for, `tests`, the test cases its log names,
 * `cases`, its `limit`, the `seconds` its process lasted and its `end`; and
 * where a pass of split streams knows it, `bound`, the wall seconds it would
 * take were every mutant it streamed forked at each covering test
 * (ADR-0196). A shard that timed no step holds none.
 *
 * @internal the shape of a shard result's `measured.steps`
 *
 * @phpstan-type ControlRow array{
 *     kinds: list<string>,
 *     tests: int,
 *     cases?: int,
 *     limit: float,
 *     seconds?: float,
 *     end: string,
 * }
 * @phpstan-type StepRow array{
 *     step: string,
 *     since: float,
 *     seconds: float,
 *     count?: int,
 *     controls?: list<ControlRow>,
 *     bound?: float,
 * }
 */
final readonly class StepsRecord
{
    public const string SECTION = 'steps';

    public const string STEP = 'step';

    public const string SINCE = 'since';

    public const string SECONDS = 'seconds';

    private const string COUNT = 'count';

    private const string CONTROLS = 'controls';

    private const string KINDS = 'kinds';

    private const string TESTS = 'tests';

    private const string CASES = 'cases';

    private const string LIMIT = 'limit';

    private const string END = 'end';

    private const string BOUND = 'bound';

    /** @return list<StepRow> */
    public static function of(StepTimes $steps): array
    {
        return array_map(self::rowOf(...), [...$steps]);
    }

    /** @return StepRow one step as a result holds it */
    public static function rowOf(StepTime $step): array
    {
        return [
            self::STEP => $step->step()->value,
            self::SINCE => $step->since()->seconds(),
            self::SECONDS => $step->took()->seconds(),
            ...$step->count() === 1 ? [] : [self::COUNT => $step->count()],
            ...count($step->controls()) === 0
                ? []
                : [self::CONTROLS => array_map(self::controlOf(...), [...$step->controls()])],
            ...$step->bound() instanceof Seconds ? [self::BOUND => $step->bound()->seconds()] : [],
        ];
    }

    /**
     * The steps a result holds, none where it holds none.
     *
     * @throws NotInShape
     */
    public static function read(Node $section): StepTimes
    {
        if (! $section->isPresent()) {
            return StepTimes::none();
        }

        return StepTimes::of(...array_map(self::stepIn(...), $section->items()));
    }

    /**
     * One step as a result holds it.
     *
     * @throws NotInShape
     */
    public static function stepIn(Node $item): StepTime
    {
        $count = $item->field(self::COUNT);

        $controls = $item->field(self::CONTROLS);
        $bound = $item->field(self::BOUND);

        $step = StepTime::of(
            Step::tryFrom($item->field(self::STEP)->text())
                ?? throw NotInShape::at($item->field(self::STEP)->at(), 'a step'),
            Seconds::of($item->field(self::SINCE)->number()),
            Seconds::of($item->field(self::SECONDS)->number()),
            $count->isPresent() ? $count->integer() : 1,
        )->controlling(...$controls->isPresent() ? array_map(self::controlIn(...), $controls->items()) : []);

        return $bound->isPresent() ? $step->bounded(Seconds::of($bound->number())) : $step;
    }

    /** @return ControlRow */
    private static function controlOf(ControlTime $control): array
    {
        $cases = $control->cases();
        $took = $control->took();

        return [
            self::KINDS => array_map(static fn(ControlKind $kind): string => $kind->value, [...$control->kinds()]),
            self::TESTS => $control->tests(),
            ...$cases instanceof NotGiven ? [] : [self::CASES => $cases],
            self::LIMIT => $control->limit()->seconds(),
            ...$took instanceof Seconds ? [self::SECONDS => $took->seconds()] : [],
            self::END => $control->end()->value,
        ];
    }

    /** @throws NotInShape */
    private static function controlIn(Node $item): ControlTime
    {
        $cases = $item->field(self::CASES);
        $took = $item->field(self::SECONDS);

        return ControlTime::read(
            AskingKinds::of(...array_map(
                static fn(Node $kind): ControlKind => ControlKind::tryFrom($kind->text())
                    ?? throw NotInShape::at($kind->at(), 'a kind of control'),
                $item->field(self::KINDS)->items(),
            )),
            $item->field(self::TESTS)->integer(),
            $cases->isPresent() ? $cases->integer() : NotGiven::value(),
            Seconds::of($item->field(self::LIMIT)->number()),
            $took->isPresent() ? Seconds::of($took->number()) : Unmeasured::duration(),
            ControlEnd::tryFrom($item->field(self::END)->text())
                ?? throw NotInShape::at($item->field(self::END)->at(), 'how a control ended'),
        );
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\RunnerPacing;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\PathRuleMode;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The `runner` key: an adapter chosen as any other is, and in its object
 * form also `withhold`, the environment variables a project adds to those the
 * runner never hands its tests, and `memory`, the memory each process that
 * runs a mutant may use (ADR-0004), and `workers`, how each mutant's run
 * starts (ADR-0023). A layer may write `withhold`, `memory` or `workers`
 * alone, for a later layer or zero-config to choose the runner.
 *
 * @internal
 *
 * @implements Shape<Setup>
 */
final readonly class RunnerChoice implements Shape
{
    /**
     * @param Shape<Choice>  $adapter
     * @param Section<Setup> $object
     */
    private function __construct(private Shape $adapter, private Section $object, private Builtins $builtins)
    {
    }

    public static function choosing(Builtins $builtins): self
    {
        $judges = Effect::JudgesOrReportsOnly;
        $use = Field::optional('use', Text::of('a name or a class'), $judges);
        $with = Field::optional('with', OpenObject::any(), $judges);
        $withhold = Field::optional(
            'withhold',
            Items::of(Text::of('a variable name or a glob')),
            Effect::AffectsResults,
        );
        $memory = Field::optional('memory', MemoryAmount::written(), Effect::AffectsResults);
        $workers = Field::optional('workers', Enumerated::of(Workers::cases()), Effect::AffectsResults);
        $pathRules = Field::optional('pathRules', Enumerated::of(PathRuleMode::cases()), Effect::AffectsResults);
        $recycle = Field::optional('recycleAfter', Integer::atLeast(1), $judges);
        $slack = Field::optional('warmSlack', Duration::written(), $judges);
        $poll = Field::optional('poll', Number::aboveAndAtMost(0, Seconds::PER_MINUTE), $judges);
        $processes = Field::optional('processes', Integer::atLeast(1), $judges);
        $workerWait = Field::optional('workerWait', Duration::written(), $judges);

        return new self(
            Adapter::choosing($builtins),
            Section::of(
                static function (Node $at) use (
                    $builtins,
                    $use,
                    $with,
                    $withhold,
                    $memory,
                    $workers,
                    $pathRules,
                    $recycle,
                    $slack,
                    $poll,
                    $processes,
                    $workerWait,
                ): Setup|Invalid {
                    $named = $use->read($at);
                    $withheld = $withhold->read($at);
                    $capped = $memory->read($at);
                    $started = $workers->read($at);
                    $applied = $pathRules->read($at);
                    $recycled = $recycle->read($at);
                    $slackens = $slack->read($at);
                    $polled = $poll->read($at);
                    $counted = $processes->read($at);
                    $waits = $workerWait->read($at);

                    return Reading::built(
                        static fn(): Setup|Invalid => self::object(
                            $builtins,
                            $at,
                            $named->value(),
                            $withheld->value(),
                            $capped->value(),
                            $started->value(),
                            $applied->value(),
                            RunnerPacing::of(
                                $recycled->value(),
                                $slackens->value(),
                                $polled->value(),
                                $counted->value(),
                                $waits->value(),
                            ),
                        ),
                        $named,
                        $with->read($at),
                        $withheld,
                        $capped,
                        $started,
                        $applied,
                        $recycled,
                        $slackens,
                        $polled,
                        $counted,
                        $waits,
                    );
                },
                $use,
                $with,
                $withhold,
                $memory,
                $workers,
                $pathRules,
                $recycle,
                $slack,
                $poll,
                $processes,
                $workerWait,
            ),
            $builtins,
        );
    }

    public function read(Node $at): Reading
    {
        if ($at->kind() === Kind::Map || $at->kind() === Kind::Empty) {
            return $this->object->read($at);
        }

        $chosen = Adapter::chosen($this->adapter->read($at));

        return $chosen instanceof Choice ? Reading::of(Setup::of(runner: $chosen)) : Reading::invalid($chosen);
    }

    public function expected(): string
    {
        return $this->adapter->expected();
    }

    public function schema(): Json
    {
        $alone = Json::object(
            Member::of('withhold', Items::of(Text::of('a variable name or a glob'))->schema()),
            Member::of('memory', MemoryAmount::written()->schema()),
            Member::of('workers', Enumerated::of(Workers::cases())->schema()),
            Member::of('pathRules', Enumerated::of(PathRuleMode::cases())->schema()),
            Member::of('recycleAfter', Integer::atLeast(1)->schema()),
            Member::of('warmSlack', Duration::written()->schema()),
            Member::of('poll', Number::aboveAndAtMost(0, Seconds::PER_MINUTE)->schema()),
            Member::of('processes', Integer::atLeast(1)->schema()),
            Member::of('workerWait', Duration::written()->schema()),
        );

        return Json::object(Member::of(
            'anyOf',
            Json::items(
                Json::object(Member::of('type', 'string'), Member::of('minLength', 1)),
                ...$this->builtins->schemas($alone, [], [], []),
                ...[
                    Json::object(
                        Member::of('type', 'object'),
                        Member::of('properties', $alone),
                        Member::of('minProperties', 1),
                        Member::of('additionalProperties', value: false),
                    ),
                ],
            ),
        ));
    }

    public function effects(): array
    {
        return [
            ...$this->adapter->effects(),
            '.withhold' => Effect::AffectsResults,
            '.memory' => Effect::AffectsResults,
            '.workers' => Effect::AffectsResults,
            '.pathRules' => Effect::AffectsResults,
            '.recycleAfter' => Effect::JudgesOrReportsOnly,
            '.warmSlack' => Effect::JudgesOrReportsOnly,
            '.poll' => Effect::JudgesOrReportsOnly,
            '.processes' => Effect::JudgesOrReportsOnly,
            '.workerWait' => Effect::JudgesOrReportsOnly,
        ];
    }

    /** @param Listed<string>|Absent $withhold */
    private static function object(
        Builtins $builtins,
        Node $at,
        string|Absent $use,
        Listed|Absent $withhold,
        MemoryCap|Absent $memory,
        Workers|Absent $workers,
        PathRuleMode|Absent $pathRules,
        RunnerPacing $pacing,
    ): Setup|Invalid {
        $withheld = $withhold instanceof Absent ? $withhold : Withheld::of(...$withhold);
        $options = $at->field('with');
        $alone = $withhold instanceof Absent
            && $memory instanceof Absent
            && $workers instanceof Absent
            && $pathRules instanceof Absent
            && $pacing->isUnset();
        $chosen = $use instanceof Absent ? new Absent() : Adapter::chosen($builtins->choose($use, $options));

        return match (true) {
            $chosen instanceof Invalid => $chosen,
            $chosen instanceof Choice => Setup::of(
                runner: $chosen,
                withhold: $withheld,
                memory: $memory,
                workers: $workers,
                pathRules: $pathRules,
                pacing: $pacing,
            ),
            $alone, $options->kind() !== Kind::Nothing
                => Invalid::because($at->field('use')->mismatch('a name or a class')),
            default => Setup::of(
                withhold: $withheld,
                memory: $memory,
                workers: $workers,
                pathRules: $pathRules,
                pacing: $pacing,
            ),
        };
    }
}

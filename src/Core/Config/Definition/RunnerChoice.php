<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;

/**
 * The `runner` key: an adapter chosen as any other is, and in its object
 * form also `withhold`, the environment variables a project adds to those the
 * runner never hands its tests, and `memory`, the memory each process that
 * runs a mutant may use (ADR-0004), and `workers`, how each mutant's run
 * starts (ADR-0023). A layer may write `withhold`, `memory` or `workers`
 * alone, for a later layer or zero-config to choose the runner.
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

        return new self(
            Adapter::choosing($builtins),
            Section::of(
                static function (Node $at) use ($builtins, $use, $with, $withhold, $memory, $workers): Setup|Invalid {
                    $named = $use->read($at);
                    $withheld = $withhold->read($at);
                    $capped = $memory->read($at);
                    $started = $workers->read($at);

                    return Reading::built(
                        static fn(): Setup|Invalid => self::object(
                            $builtins,
                            $at,
                            $named->value(),
                            $withheld->value(),
                            $capped->value(),
                            $started->value(),
                        ),
                        $named,
                        $with->read($at),
                        $withheld,
                        $capped,
                        $started,
                    );
                },
                $use,
                $with,
                $withhold,
                $memory,
                $workers,
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
    ): Setup|Invalid {
        $withheld = $withhold instanceof Absent ? Withheld::nothing() : Withheld::of(...$withhold);
        $options = $at->field('with');
        $alone = $withhold instanceof Absent && $memory instanceof Absent && $workers instanceof Absent;
        $chosen = $use instanceof Absent ? new Absent() : Adapter::chosen($builtins->choose($use, $options));

        return match (true) {
            $chosen instanceof Invalid => $chosen,
            $chosen instanceof Choice => Setup::of(
                runner: $chosen,
                withhold: $withheld,
                memory: $memory,
                workers: $workers,
            ),
            $alone, $options->kind() !== Kind::Nothing
                => Invalid::because($at->field('use')->mismatch('a name or a class')),
            default => Setup::of(withhold: $withheld, memory: $memory, workers: $workers),
        };
    }
}

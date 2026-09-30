<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Improvement;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\UncoveredMutants;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/** How the keys a config writes are read into its `Floors` part (ADR-0002). */
final readonly class FloorsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $floor = Field::optional('floor', Percent::floor(), $judges);
        $path = Field::optional('path', Location::path($origin), $judges);
        $improvement = Field::optional('improvement', Enumerated::of(Improvement::cases()), $judges);

        return [
            Field::entries(
                'trees',
                Into::of(
                    Items::of(self::tree($origin)),
                    static fn(Listed $trees): Layer => Layer::of(Floors::of(trees: $trees)),
                ),
            ),
            Field::section(
                'newCode',
                Section::single(
                    $floor,
                    static fn(Floor|Absent $newCode): Layer => Layer::of(Floors::of(newCode: $newCode)),
                ),
            ),
            Field::optional(
                'uncovered',
                Into::of(
                    Enumerated::of(UncoveredMutants::cases()),
                    static fn(UncoveredMutants $uncovered): Layer => Layer::of(Floors::of(uncovered: $uncovered)),
                ),
                $judges,
            ),
            Field::section(
                'baseline',
                Section::of(
                    static function (Node $baseline) use ($path, $improvement): Layer|Invalid {
                        $at = $path->read($baseline);
                        $improving = $improvement->read($baseline);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Floors::of(
                                baseline: $at->value(),
                                improvement: $improving->value(),
                            )),
                            $at,
                            $improving,
                        );
                    },
                    $path,
                    $improvement,
                ),
            ),
        ];
    }

    /**
     * A `trees` entry as a config writes it: a floor of 0 has to carry its reason.
     *
     * @return Section<DeclaredTree>
     */
    private static function tree(PathOrigin $origin): Section
    {
        $results = Effect::AffectsResults;
        $judges = Effect::JudgesOrReportsOnly;
        $path = Field::required('path', Location::path($origin), $results);
        $floor = Field::optional('floor', Percent::floor(), $judges);
        $reason = Field::optional('reason', Text::of('a reason'), $judges);
        $exclude = Field::optional('exclude', Items::of(Pattern::glob($origin)), $results);

        return Section::of(
            static function (Node $tree) use ($path, $floor, $reason, $exclude): DeclaredTree|Invalid {
                $at = $path->read($tree);
                $declared = $floor->read($tree);
                $because = $reason->read($tree);
                $excluded = $exclude->read($tree);

                return Reading::built(
                    static fn(): DeclaredTree|Invalid => self::declared(
                        $tree,
                        $at->must(),
                        $declared->value(),
                        $because->value(),
                        $excluded->value(),
                    ),
                    $at,
                    $declared,
                    $because,
                    $excluded,
                );
            },
            $path,
            $floor,
            $reason,
            $exclude,
        )->defaulting(Json::object(Member::of('exclude', Json::items())));
    }

    /** @param Listed<Glob>|Absent $exclude */
    private static function declared(
        Node $tree,
        Path $path,
        Floor|Absent $floor,
        string|Absent $reason,
        Listed|Absent $exclude,
    ): DeclaredTree|Invalid {
        $excluding = $exclude instanceof Absent ? Listed::of() : $exclude;

        return match (true) {
            ! $reason instanceof Absent && ($floor instanceof Absent || $floor->hundredths() > 0) => Invalid::because(
                $tree->field('reason')->mismatch('no reason, as only a floor of 0 takes one'),
            ),
            $floor instanceof Absent => DeclaredTree::of($path, Undeclared::floor(), $excluding),
            $floor->hundredths() > 0 => DeclaredTree::of($path, $floor, $excluding),
            ! $reason instanceof Absent => DeclaredTree::of($path, Exempt::because($reason), $excluding),
            default => Invalid::because(
                Problem::at($tree->field('reason')->at(), 'expected a reason when floor is 0, got nothing'),
            ),
        };
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\PathRule;
use NightWorksIO\MutationGate\Core\Config\PathRules;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How `paths` is read into a layer's `PathRules` part (ADR-0074): each entry a glob, with the mutators, the
 * timeouts and the static check it sets for the files it matches. What an entry sets affects only the units it
 * matches, so each of its settings says so.
 *
 * @internal
 */
final readonly class PathsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        return [Field::entries(
            PathRules::KEY,
            Into::of(
                Items::of(self::entry($origin)),
                static fn(Listed $rules): Layer => Layer::of(Setup::of(paths: PathRules::of(...$rules))),
            ),
        )];
    }

    /** @return Section<PathRule> */
    private static function entry(PathOrigin $origin): Section
    {
        $matching = Effect::AffectsMatchingUnits;
        $glob = Field::required('glob', Pattern::glob($origin), $matching);
        $mutators = Field::section('mutators', MutatorsKeys::section($matching));
        $timeouts = Field::section('timeouts', self::timeouts());
        $staticCheck = Field::section(StaticCheck::SECTION, self::staticCheck($origin));

        return Section::of(
            static function (Node $entry) use ($glob, $mutators, $timeouts, $staticCheck): PathRule|Invalid {
                $matched = $glob->read($entry);
                $parts = [$mutators->read($entry), $timeouts->read($entry), $staticCheck->read($entry)];

                return Reading::built(
                    static fn(): PathRule => self::rule($matched->must(), ...$parts),
                    $matched,
                    ...$parts,
                );
            },
            $glob,
            $mutators,
            $timeouts,
            $staticCheck,
        );
    }

    /** @param Reading<Layer> ...$parts */
    private static function rule(Glob $glob, Reading ...$parts): PathRule
    {
        $rules = Layer::of();

        foreach ($parts as $part) {
            $layer = $part->value();
            $rules = $layer instanceof Layer ? $rules->over($layer) : $rules;
        }

        return PathRule::of($glob, $rules);
    }

    /**
     * An entry's `timeouts`: `seconds` and `most`.
     *
     * @return Section<Layer>
     */
    private static function timeouts(): Section
    {
        $matching = Effect::AffectsMatchingUnits;
        $seconds = Field::optional('seconds', Integer::atLeast(1), $matching);
        $most = Field::optional('most', Integer::atLeast(1), $matching);

        return Section::of(
            static function (Node $timeouts) use ($seconds, $most): Layer|Invalid {
                $limit = $seconds->read($timeouts);
                $upper = $most->read($timeouts);

                return Reading::built(
                    static fn(): Layer => Layer::of(Triage::of(
                        limit: self::seconds($limit->value()),
                        most: self::seconds($upper->value()),
                    )),
                    $limit,
                    $upper,
                );
            },
            $seconds,
            $most,
        );
    }

    /**
     * An entry's `staticCheck`: `tool` and `before`.
     *
     * @return Section<Layer>
     */
    private static function staticCheck(PathOrigin $origin): Section
    {
        $tool = Field::optional(
            'tool',
            Adapter::choosing(Builtins::staticCheckers($origin)),
            Effect::AffectsMatchingUnits,
        );
        $before = Field::optional('before', Flag::boolean(), Effect::JudgesOrReportsOnly);

        return Section::of(
            static function (Node $staticCheck) use ($tool, $before): Layer|Invalid {
                $chosen = $tool->read($staticCheck);
                $placed = $before->read($staticCheck);

                return Reading::built(
                    static fn(): Layer => Layer::of(StaticCheck::of(tool: $chosen->value(), before: $placed->value())),
                    $chosen,
                    $placed,
                );
            },
            $tool,
            $before,
        );
    }

    private static function seconds(int|Absent $seconds): Seconds|Absent
    {
        return $seconds instanceof Absent ? $seconds : Seconds::of($seconds);
    }
}

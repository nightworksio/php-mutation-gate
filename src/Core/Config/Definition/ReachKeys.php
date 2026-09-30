<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Reach;

/** How the keys a config writes are read into its `Reach` part (ADR-0002). */
final readonly class ReachKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $everything = Field::optional('everything', Items::of(Pattern::glob($origin)), $judges);
        $hotPath = Field::optional('hotPath', Number::between(0, 1), $judges);

        return [
            Field::optional(
                'packages',
                Into::of(
                    Items::of(Pattern::glob($origin)),
                    static fn(Listed $packages): Layer => Layer::of(Reach::of(packages: $packages)),
                ),
                Effect::AffectsResults,
            ),
            Field::section(
                'reach',
                Section::single(
                    $everything,
                    static fn(Listed|Absent $globs): Layer => Layer::of(Reach::of(everything: $globs)),
                ),
            ),
            Field::section(
                'holds',
                Section::single(
                    $hotPath,
                    static fn(int|float|Absent $share): Layer => Layer::of(Reach::of(hotPath: $share)),
                ),
            ),
        ];
    }
}

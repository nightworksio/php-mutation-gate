<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\ChangedTests;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\FailFast;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the keys a config writes are read into its `Reach` part (ADR-0002).
 *
 * @internal
 */
final readonly class ReachKeys
{
    /** What an entry of `reach.lockPackages` is, as a problem names it. */
    private const string PACKAGE = 'a package\'s name, vendor/name, where `*` stands for any run of characters';

    /** A package's name, or a pattern of names: a vendor and a name, neither holding a slash or a space. */
    private const string PACKAGE_PATTERN = '^[^/\s]+/[^/\s]+$';

    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $everything = Field::optional('everything', Items::of(Pattern::glob($origin)), Effect::DecidesHowTheGateRuns);
        $lockPackages = Field::optional(
            'lockPackages',
            Items::of(Text::matching(self::PACKAGE, self::PACKAGE_PATTERN)),
            Effect::DecidesHowTheGateRuns,
        );
        $changedTests = Field::optional(
            'changedTests',
            Enumerated::of(ChangedTests::cases()),
            Effect::DecidesHowTheGateRuns,
        );
        $fixtures = Field::optional('fixtures', Items::of(Pattern::glob($origin)), Effect::DecidesHowTheGateRuns);
        $hotPath = Field::optional('hotPath', Number::between(0, 1), $judges);
        $full = Field::optional('full', Flag::boolean(), Effect::DecidesHowTheGateRuns);
        $failFast = Field::optional('failFast', Enumerated::of(FailFast::cases()), $judges);

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
                Section::of(
                    static function (Node $reach) use (
                        $everything,
                        $lockPackages,
                        $changedTests,
                        $fixtures,
                    ): Layer|Invalid {
                        $files = $everything->read($reach);
                        $packages = $lockPackages->read($reach);
                        $tests = $changedTests->read($reach);
                        $projects = $fixtures->read($reach);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Reach::of(
                                everything: $files->value(),
                                lockPackages: $packages->value(),
                                changedTests: $tests->value(),
                                fixtures: $projects->value(),
                            )),
                            $files,
                            $packages,
                            $tests,
                            $projects,
                        );
                    },
                    $everything,
                    $lockPackages,
                    $changedTests,
                    $fixtures,
                ),
            ),
            Field::section(
                'holds',
                Section::single(
                    $hotPath,
                    static fn(int|float|Absent $share): Layer => Layer::of(Reach::of(hotPath: $share)),
                ),
            ),
            Field::section(
                'run',
                Section::of(
                    static function (Node $run) use ($full, $failFast): Layer|Invalid {
                        $considered = $full->read($run);
                        $stopping = $failFast->read($run);

                        return Reading::built(
                            static fn(): Layer => Layer::of(
                                Reach::of(full: $considered->value(), failFast: $stopping->value()),
                            ),
                            $considered,
                            $stopping,
                        );
                    },
                    $full,
                    $failFast,
                ),
            ),
        ];
    }
}

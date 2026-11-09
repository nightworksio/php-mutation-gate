<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Config\ChangedTests;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\DefaultBranchSince;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\FailFast;
use NightWorksIO\MutationGate\Core\Config\HeldCoverageMode;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\LockMoveReach;
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
        $lockMoves = Field::optional(
            'lockMoves',
            Enumerated::of(LockMoveReach::cases()),
            Effect::DecidesHowTheGateRuns,
        );
        $changedTests = Field::optional(
            'changedTests',
            Enumerated::of(ChangedTests::cases()),
            Effect::DecidesHowTheGateRuns,
        );
        $fixtures = Field::optional('fixtures', Items::of(Pattern::glob($origin)), Effect::DecidesHowTheGateRuns);
        $since = Field::optional(
            'defaultBranchSince',
            Enumerated::of(DefaultBranchSince::cases()),
            Effect::DecidesHowTheGateRuns,
        );
        $heldCoverage = Field::optional('coverage', Enumerated::of(HeldCoverageMode::cases()), Effect::AffectsResults);
        $hotPath = Field::optional('hotPath', Number::between(0, 1), $judges);
        $fewest = Field::optional('fewestTests', Integer::atLeast(1), $judges);
        $full = Field::optional('full', Flag::boolean(), Effect::DecidesHowTheGateRuns);
        $failFast = Field::optional('failFast', Enumerated::of(FailFast::cases()), $judges);
        $waits = Field::optional(
            'failFastWaits',
            Items::distinct(Enumerated::of(Unchecked::cases()), static fn(Unchecked $why): string => $why->value),
            $judges,
        );

        return [
            Field::optional(
                Reach::VERSION_CONTROL,
                Into::of(
                    Adapter::choosing(Builtins::versionControls($origin)),
                    static fn(Choice $chosen): Layer => Layer::of(Reach::of(versionControl: $chosen)),
                ),
                Effect::DecidesHowTheGateRuns,
            ),
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
                        $lockMoves,
                        $changedTests,
                        $fixtures,
                        $since,
                    ): Layer|Invalid {
                        $read = $since->read($reach);
                        $files = $everything->read($reach);
                        $packages = $lockPackages->read($reach);
                        $moves = $lockMoves->read($reach);
                        $tests = $changedTests->read($reach);
                        $projects = $fixtures->read($reach);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Reach::of(
                                everything: $files->value(),
                                lockPackages: $packages->value(),
                                changedTests: $tests->value(),
                                fixtures: $projects->value(),
                                lockMoves: $moves->value(),
                                defaultBranchSince: $read->value(),
                            )),
                            $read,
                            $files,
                            $packages,
                            $moves,
                            $tests,
                            $projects,
                        );
                    },
                    $everything,
                    $lockPackages,
                    $lockMoves,
                    $changedTests,
                    $fixtures,
                    $since,
                ),
            ),
            Field::section(
                'holds',
                Section::of(
                    static function (Node $holds) use ($hotPath, $fewest, $heldCoverage): Layer|Invalid {
                        $share = $hotPath->read($holds);
                        $tests = $fewest->read($holds);
                        $measured = $heldCoverage->read($holds);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Reach::of(
                                hotPath: $share->value(),
                                hotPathFewest: $tests->value(),
                                heldCoverage: $measured->value(),
                            )),
                            $share,
                            $tests,
                            $measured,
                        );
                    },
                    $hotPath,
                    $fewest,
                    $heldCoverage,
                ),
            ),
            Field::section(
                'run',
                Section::of(
                    static function (Node $run) use ($full, $failFast, $waits): Layer|Invalid {
                        $considered = $full->read($run);
                        $stopping = $failFast->read($run);
                        $waiting = $waits->read($run);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Reach::of(
                                full: $considered->value(),
                                failFast: $stopping->value(),
                                failFastWaits: $waiting->value(),
                            )),
                            $considered,
                            $stopping,
                            $waiting,
                        );
                    },
                    $full,
                    $failFast,
                    $waits,
                ),
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Infection\Setup;
use NightWorksIO\MutationGate\Adapter\Infection\StaticAnalysis;
use NightWorksIO\MutationGate\Adapter\Native\NativeOptions;
use NightWorksIO\MutationGate\Adapter\Pest\PestOptions;
use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Cli\Registry\EnabledMutators;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Port\StaticChecker;

/**
 * The runner a config chooses, told what the gate knows that it does not
 * read itself: the bounds of each mutant's limit, the registered mutators
 * it makes mutants with, the directories the tests are in, and, for
 * Infection, that the gate checks its survivors.
 *
 * @internal
 */
final readonly class ToldRunner
{
    /**
     * The runner the config chooses: each told the bounds of each mutant's
     * limit, `timeouts.seconds` and `timeouts.most`, and the mutators whose
     * silence limit has a lower floor, `timeouts.tighter` (ADR-0008);
     * Infection told that the gate checks its survivors, where an analyser
     * does, so it runs no static analysis of its own (ADR-0020); and each
     * told the classes of the registered mutators it makes mutants with
     * (ADR-0021): Pest and Infection those the config turns on, beside
     * their own, and the PHPUnit runner the `default` set's and those
     * (ADR-0023); and each told the directories the tests are in, as the
     * PHPUnit config declares them, so a test outside the conventional
     * `tests` is one the runner lists.
     */
    public static function of(
        Settings $settings,
        StaticChecker|NoAnalyser|Invalid|CannotJudge $checker,
        EnabledMutators $mutators,
        TestSuite $suite,
    ): Choice {
        $runner = $settings->runner()->choice();
        $use = $runner->use()->value();
        $bounds = BoundsOptions::of($settings->triage());
        $beside = Json::items(...$mutators->besideTheRunners()->classes());
        $tests = self::testsOf($suite);
        $infection = Json::object(...[
            Member::of(Setup::TESTS, $tests),
            ...$bounds,
            Member::of(Setup::MUTATORS, $beside),
            ...$checker instanceof StaticChecker
                ? [Member::of(Setup::STATIC_ANALYSIS, StaticAnalysis::Gate->value)]
                : [],
        ]);
        $native = Json::object(...[
            Member::of(NativeOptions::TESTS, $tests),
            ...$bounds,
            Member::of(NativeOptions::MUTATORS, Json::items(...$mutators->forTheEngine()->classes())),
        ]);

        return match (true) {
            $use === BuiltinRunner::Infection->value => Choice::of($use, $runner->options()->over($infection)),
            $use === BuiltinRunner::PhpUnit->value,
            $use === BuiltinRunner::PhpSpec->value,
            $use === BuiltinRunner::Codeception->value,
            $use === BuiltinRunner::Testo->value => Choice::of($use, $runner->options()->over($native)),
            $use === BuiltinRunner::Pest->value => Choice::of(
                $use,
                $runner->options()->over(Json::object(...[
                    Member::of(PestOptions::TESTS, $tests),
                    ...$bounds,
                    Member::of(PestOptions::MUTATORS, $beside),
                ])),
            ),
            default => $runner,
        };
    }

    /** The directories a suite's tests are in, as a runner's `tests` option holds them. */
    private static function testsOf(TestSuite $suite): Json
    {
        $tests = [];

        foreach ($suite->paths() as $path) {
            $tests[] = $path->value();
        }

        return Json::items(...$tests);
    }
}

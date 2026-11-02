<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension\Checks;

use function count;
use function dirname;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\LibraryTest;
use NightWorksIO\MutationGate\Port\Runner;

use function sprintf;
use function str_starts_with;

/**
 * What a runner answers of itself and its library before it mutates anything:
 * its identity, its groups and how it reads them, what a mutant's run pays
 * before its first test, the files that define it, the markers in code with
 * none, and a directory that holds no project.
 *
 * @internal the runner contract's own
 */
final readonly class RunnerAnswers
{
    private function __construct(private Runner $runner)
    {
    }

    /** @return list<string> */
    public static function failures(Runner $runner): array
    {
        $answers = new self($runner);
        $source = Path::of(dirname(LibraryChange::Adds->file()->value()));
        $tests = Paths::of(Path::of(dirname(LibraryTest::Money->value)));

        return [
            ...$answers->identity(),
            ...$answers->listing(),
            ...$answers->startUp(),
            ...$answers->definitions(),
            ...$answers->markers(),
            ...Records::unless(
                $runner->rootedAt($source, $tests) instanceof CannotJudge,
                'It answers as a runner rooted in src/, which holds no project it can run.',
            ),
        ];
    }

    /** @return list<string> */
    private function identity(): array
    {
        $identity = $this->runner->identity(Withheld::standard());

        if (! $identity instanceof Identity) {
            return [sprintf('It answers no identity: %s', $identity->why())];
        }

        return [
            ...Records::unless(
                Records::identity($this->runner->identity(Withheld::standard())) === Records::identity($identity),
                'It answers another identity the second time it is asked.',
            ),
            ...Records::unless($identity->runner() !== '', 'It answers an identity with no name.'),
            ...Records::unless(count($identity->versions()) > 0, 'It answers an identity with no version.'),
        ];
    }

    /** @return list<string> */
    private function listing(): array
    {
        $listing = $this->runner->listing(Withheld::standard(), Suites::all());

        if (! $listing instanceof TestListing) {
            return [sprintf('It lists no tests: %s', $listing->why())];
        }

        $held = Group::holding(LibraryChange::Held->file()->value());
        $behaviour = $this->runner->behaviour();
        $failures = Records::unless(
            $listing->groups()->has($held) && count($listing->inGroup($held)) > 0,
            sprintf('It lists no test in the group %s.', $held->name()),
        );

        foreach ($behaviour->readByEveryKey() as $group) {
            $failures = [...$failures, ...Records::unless(
                $listing->groups()->has($group),
                sprintf('It says every key reads the group %s, which it does not list.', $group->name()),
            )];
        }

        return [...$failures, ...Records::unless(
            count($behaviour->readByEveryKey()) === 0 || ! $behaviour->opensEachShard(),
            'It says every key reads groups of its suite, and that each shard opens on its own run.',
        )];
    }

    /** @return list<string> */
    private function startUp(): array
    {
        $startUp = $this->runner->startUp(LibraryChange::Adds->file(), Withheld::standard());

        return match (true) {
            $startUp instanceof CannotJudge => [sprintf('It times no run of no test: %s', $startUp->why())],
            default => Records::unless($startUp->seconds() > 0.0, 'It times a run of no test at no time at all.'),
        };
    }

    /** @return list<string> */
    private function definitions(): array
    {
        $failures = [];

        foreach ($this->runner->definitions() as $definition) {
            $failures = [...$failures, ...Records::unless(
                ! str_starts_with($definition->value(), '/') && $definition->value() !== Path::root()->value(),
                sprintf('It names %s as a file that defines it, not from the project\'s root.', $definition->value()),
            )];
        }

        return $failures;
    }

    /** @return list<string> */
    private function markers(): array
    {
        $money = LibraryChange::Adds->file();
        $markers = $this->runner->markers(Paths::of($money));

        return match (true) {
            $markers instanceof Markers => Records::unless(
                count($markers) === 0,
                sprintf('It finds its own ignore markers in %s, which holds none.', $money->value()),
            ),
            default => [sprintf('It finds no ignore markers: %s', $markers->why())],
        };
    }
}

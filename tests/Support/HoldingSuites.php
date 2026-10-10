<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;

/**
 * A project whose PHPUnit config declares three suites: Unit, whose tests
 * judge every unit; Process, whose tests judge only the units they hold; and
 * Arch, which judges nothing (ADR-0002, decision 8).
 */
final readonly class HoldingSuites
{
    /** The test of the Unit suite. */
    public const string ADDS = 'P\Tests\Unit\MoneyTest::adds';

    /** The test of the Process suite, which holds src/Held.php. */
    public const string STARTS = 'P\Tests\Process\HeldTest::starts';

    /** The test of the Arch suite. */
    public const string RULES = 'P\Tests\Arch\RulesTest::keeps';

    public const string PHPUNIT = <<<'XML'
        <phpunit>
            <testsuites>
                <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
                <testsuite name="Process"><directory>tests/Process</directory></testsuite>
                <testsuite name="Arch"><directory>tests/Arch</directory></testsuite>
            </testsuites>
        </phpunit>
        XML;

    public const array FILES = [
        'src/Money.php' => "<?php\n\nfinal class Money\n{\n}\n",
        'src/Held.php' => "<?php\n\nfinal class Held\n{\n}\n",
        'tests/Unit/MoneyTest.php' => "<?php\n\nit('adds', fn () => expect(1)->toBe(1));\n",
        'tests/Process/HeldTest.php' => "<?php\n\nit('starts', fn () => expect(1)->toBe(1))->group('holds:src/Held.php');\n",
        'tests/Arch/RulesTest.php' => "<?php\n\nit('keeps the rules', fn () => expect(1)->toBe(1));\n",
        'composer.json' => "{}\n",
        'phpunit.xml' => self::PHPUNIT,
    ];

    /**
     * The project on disk, with these files besides its own.
     *
     * @param array<string, string> $more
     */
    public static function project(array $more = []): string
    {
        $project = Scratch::directory();

        foreach ([...self::FILES, ...$more] as $path => $contents) {
            Scratch::write($project, $path, $contents);
        }

        return $project;
    }

    /**
     * The checkout of the project, with these files besides its own.
     *
     * @param array<string, string> $more
     */
    public static function checkout(array $more = []): ChangeSourceFake
    {
        $files = [...self::FILES, ...$more];

        return new ChangeSourceFake(
            Revision::ref('base'),
            Changes::none(),
            [Revision::workingTree()->name() => $files, 'base' => $files, Flows::MAIN => $files],
        );
    }

    /** The adapters of a project, Unit's tests judging every unit and Process's the units they hold, with these ports. */
    public static function adapters(string $project, object ...$ports): Adapters
    {
        $adapters = Flows::adapters($project, [], ...$ports)
            ->judgedAmong(JudgingSuites::holding(Suites::listed('Unit'), Suites::listed('Process')));

        return $adapters instanceof Adapters ? $adapters : throw new LogicException($adapters->why());
    }

    /** A runner whose map is this, listing these tests over Unit and these over Process. */
    public static function runner(TestListing $unit, TestListing $process, CoverageMap $map): RunnerFake
    {
        return new RunnerFake(
            Identity::of('fake', Versions::none(), Digest::of('php')),
            Groups::none(),
            $map,
            Mutants::none(),
            Paths::none(),
            Paths::none(),
            TestNames::none()
                ->with(TestId::of(self::ADDS), TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'it adds'))
                ->with(TestId::of(self::STARTS), TestName::in(Path::of('tests/Process/HeldTest.php'), 'it starts'))
                ->with(TestId::of(self::RULES), TestName::in(Path::of('tests/Arch/RulesTest.php'), 'it keeps the rules')),
            Paths::none(),
        )->listingIn(Suites::listed('Unit'), $unit)->listingIn(Suites::listed('Process'), $process);
    }
}

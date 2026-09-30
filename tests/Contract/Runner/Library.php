<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Contract\Runner;

use function array_key_exists;
use function array_map;
use function basename;
use function dirname;
use function getenv;
use function is_dir;
use function iterator_to_array;

use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\ProcessShell as InfectionShell;
use NightWorksIO\MutationGate\Adapter\Infection\Project as InfectionProject;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\MinusToPlus;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Arithmetic\PostDecrementToPostIncrement;
use Pest\Mutate\Mutators\Equality\GreaterToGreaterOrEqual;

use function sprintf;

/**
 * The fixture library the runner contract suite mutates, as one runner names
 * its mutators. Each change the library's code allows is named here once, with
 * the file, line, lines and status every runner must report for it.
 *
 * @phpstan-type Change array{file: string, line: int, removed: string, added: string, status: MutantStatus}
 * @phpstan-type Record array{string, string, string, int, int, string, string}
 */
final class Library
{
    /** The library, a project of its own, installed by the runner contracts job. */
    public const string DIRECTORY = 'tests/Contract/Runner/fixture';

    /** The Infection library, the same code, tested by PHPUnit, installed by its own runner contracts job. */
    public const string INFECTION_DIRECTORY = 'tests/Contract/Runner/infection-fixture';

    public const string CANARY = 'mutation-canary';

    /** The file in each library that holds its runner's own ignore marker, outside what the suite mutates. */
    public const string MARKED = 'marked/Marked.php';

    /** Where that marker is. */
    public const string MARKER = 'marked/Marked.php:9';

    /** @var array<string, Change> every change, by a name no runner uses */
    public const array CHANGES = [
        'adds' => [
            'file' => 'src/Money.php',
            'line' => 11,
            'removed' => 'return $a + $b;',
            'added' => 'return $a - $b;',
            'status' => MutantStatus::Killed,
        ],
        'large' => [
            'file' => 'src/Money.php',
            'line' => 16,
            'removed' => 'return $amount > 100;',
            'added' => 'return $amount >= 100;',
            'status' => MutantStatus::Survived,
        ],
        'unused' => [
            'file' => 'src/Money.php',
            'line' => 21,
            'removed' => 'return $amount - 1;',
            'added' => 'return $amount + 1;',
            'status' => MutantStatus::Uncovered,
        ],
        'drains' => [
            'file' => 'src/Money.php',
            'line' => 27,
            'removed' => '$amount--;',
            'added' => '$amount++;',
            'status' => MutantStatus::TimedOut,
        ],
        'held' => [
            'file' => 'src/Held.php',
            'line' => 11,
            'removed' => 'return $amount + $amount;',
            'added' => 'return $amount - $amount;',
            'status' => MutantStatus::Survived,
        ],
    ];

    /** @var array<string, array{string, MutatorFamily}> the fake's mutator for each change, and its family */
    public const array FAKE = [
        'adds' => ['Plus', MutatorFamily::Arithmetic],
        'large' => ['GreaterThan', MutatorFamily::Boundary],
        'unused' => ['Minus', MutatorFamily::Arithmetic],
        'drains' => ['Decrement', MutatorFamily::Arithmetic],
        'held' => ['Plus', MutatorFamily::Arithmetic],
    ];

    /** @var array<string, array{string, MutatorFamily}> Pest's mutator for each change, and its family */
    private const array PEST = [
        'adds' => [PlusToMinus::class, MutatorFamily::Arithmetic],
        'large' => [GreaterToGreaterOrEqual::class, MutatorFamily::Boundary],
        'unused' => [MinusToPlus::class, MutatorFamily::Arithmetic],
        'drains' => [PostDecrementToPostIncrement::class, MutatorFamily::Arithmetic],
        'held' => [PlusToMinus::class, MutatorFamily::Arithmetic],
    ];

    /** @var array<string, array{string, MutatorFamily}> Infection's mutator for each change, and its family */
    private const array INFECTION = [
        'adds' => ['Plus', MutatorFamily::Arithmetic],
        'large' => ['GreaterThan', MutatorFamily::Boundary],
        'unused' => ['Minus', MutatorFamily::Arithmetic],
        'drains' => ['Decrement', MutatorFamily::Arithmetic],
        'held' => ['Plus', MutatorFamily::Arithmetic],
    ];

    /** @var array<string, string> what the fake names each test it is asked, by the test's id */
    private const array FAKE_NAMES = [
        'MoneyTest::adds' => 'tests/MoneyTest.php::it adds',
        'MoneyTest::adds#one' => 'tests/MoneyTest.php::it adds with data set "one"',
        'Nowhere\\GoneTest::gone' => 'Nowhere\\GoneTest::gone',
    ];

    /**
     * @var array<string, string> what Pest names each test it is asked, by the test's id: a Pest test by
     *                            the description Pest gives it, a PHPUnit test by its method
     */
    private const array PEST_NAMES = [
        'P\\Tests\\MoneySpec::__pest_evaluable_it_adds_two_amounts' => 'tests/MoneySpec.php::it adds two amounts',
        'P\\Tests\\ShapesSpec::__pest_evaluable_it_is_held_in_every_row#(2)'
            => 'tests/ShapesSpec.php::it is held in every row with data set "(2)"',
        'P\\Tests\\ShapesSpec::__pest_evaluable__a_held_describe__→_it_is_held_by_its_describe'
            => 'tests/ShapesSpec.php::`a held describe` → it is held by its describe',
        'LegacySpec::decrements' => 'tests/LegacySpec.php::decrements',
        'Nowhere\\GoneTest::gone' => 'Nowhere\\GoneTest::gone',
    ];

    /** @var array<string, string> what Infection names each test it is asked, by the test's id */
    private const array INFECTION_NAMES = [
        'Tests\\MoneySpec::addsTwoAmounts' => 'tests/MoneySpec.php::addsTwoAmounts',
        'Tests\\MoneySpec::addsTwoAmounts#1' => 'tests/MoneySpec.php::addsTwoAmounts with data set #1',
        'Tests\\MoneySpec::addsTwoAmounts#one' => 'tests/MoneySpec.php::addsTwoAmounts with data set "one"',
        'Nowhere\\GoneTest::gone' => 'Nowhere\\GoneTest::gone',
    ];

    /** @var array<string, MutationResult|CannotJudge> each real run's answer, by library and request */
    private static array $runs = [];

    /**
     * @param array<string, array{string, MutatorFamily}> $vocabulary
     * @param bool                                         $endsReported whether the runner reports the line a mutant ends on,
     *                                                                   and how long each mutant it ran took
     * @param Paths                                        $defining     the files of the library that define its runner
     * @param array<string, string>                        $naming       what the runner names each test it is asked
     * @param Runner                                       $outside      the runner built in the directory around the
     *                                                                   library, which holds no project of its own
     * @param Path                                         $package      the library's directory, from that one
     */
    private function __construct(
        private readonly string $name,
        private readonly Runner $runner,
        private readonly array $vocabulary,
        private readonly bool $endsReported,
        private readonly Paths $defining,
        private readonly array $naming,
        private readonly Runner $outside,
        private readonly Path $package,
    ) {
    }

    public static function fake(): self
    {
        $defining = Paths::of(Path::of('tests/Pest.php'), Path::of('phpunit.xml'));

        return new self(
            'fake',
            RunnerFake::ofTheFixture(),
            self::FAKE,
            endsReported: true,
            defining: $defining,
            naming: self::FAKE_NAMES,
            outside: RunnerFake::ofTheFixture(),
            package: Path::of('fixture'),
        );
    }

    /**
     * The Infection adapter over its installed library, allowing each mutant at
     * most the cap, and refusing native markers.
     */
    public static function infection(Seconds $cap): self
    {
        $infection = static function (string $root) use ($cap): Infection {
            $project = InfectionProject::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.mutation-gate'));

            return new Infection($project, new InfectionShell($root, getenv()), $cap, nativeMarkersAllowed: false);
        };

        return new self(
            sprintf('infection %.1F', $cap->seconds()),
            $infection(Tree::at(self::INFECTION_DIRECTORY)),
            self::INFECTION,
            endsReported: false,
            defining: Paths::of(Path::of('infection.json5'), Path::of('phpunit.xml')),
            naming: self::INFECTION_NAMES,
            outside: $infection(dirname(Tree::at(self::INFECTION_DIRECTORY))),
            package: Path::of(basename(self::INFECTION_DIRECTORY)),
        );
    }

    public static function isInfectionInstalled(): bool
    {
        return is_dir(Tree::at(sprintf('%s/vendor', self::INFECTION_DIRECTORY)));
    }

    /** The Pest adapter over the installed library, with `pest:patch` off or on. */
    public static function pest(Patching $patching): self
    {
        return self::pestAt(Tree::at(self::DIRECTORY), $patching);
    }

    /** The Pest adapter over the installed library at a root, such as a link to it. */
    public static function pestAt(string $root, Patching $patching): self
    {
        $pest = static function (string $root) use ($patching): Pest {
            $tests = Paths::of(Path::of('tests'));
            $project = Project::at($root, $tests, Path::of('.mutation-gate'), Path::of('vendor'));

            return new Pest($project, new ProcessShell($root), $patching);
        };

        return new self(
            sprintf('pest %s', $patching->isOn() ? 'patched' : 'unpatched'),
            $pest($root),
            self::PEST,
            endsReported: true,
            defining: Paths::of(Path::of('tests/Pest.php'), Path::of('phpunit.xml')),
            naming: self::PEST_NAMES,
            outside: $pest(dirname($root)),
            package: Path::of(basename($root)),
        );
    }

    public static function isInstalled(): bool
    {
        return is_dir(Tree::at(sprintf('%s/vendor', self::DIRECTORY)));
    }

    /** A dependency's installed source directory in the library. */
    public static function vendor(): string
    {
        return Tree::at(sprintf('%s/vendor', self::DIRECTORY));
    }

    public function runner(): Runner
    {
        return $this->runner;
    }

    /** @return array<string, string> what the runner names each test it is asked, by the test's id */
    public function naming(): array
    {
        return $this->naming;
    }

    /** The runner built in the directory around the library, which holds no project of its own. */
    public function outside(): Runner
    {
        return $this->outside;
    }

    /** The library's directory, from the one around it. */
    public function package(): Path
    {
        return $this->package;
    }

    /** The files of the library that define its runner, from the library's root. */
    public function defining(): Paths
    {
        return $this->defining;
    }

    /** Whether the runner reports how long each mutant it ran took; Infection's logs do not. */
    public function measures(): bool
    {
        return $this->endsReported;
    }

    /** The runner's mutators for these changes. */
    public function mutators(string $change, string ...$more): Mutators
    {
        $others = array_map(fn(string $each): string => $this->vocabulary[$each][0], $more);

        return Mutators::named($this->vocabulary[$change][0], ...$others);
    }

    /** A request's answer, run once for this library whatever the number of tests that ask. */
    public function mutate(string $key, MutationRequest $request): MutationResult|CannotJudge
    {
        $run = sprintf('%s %s', $this->name, $key);

        if (! array_key_exists($run, self::$runs)) {
            self::$runs[$run] = $this->runner->mutate($request);
        }

        return self::$runs[$run];
    }

    /**
     * The records a runner must report for these changes: id, status, file,
     * lines, mutator and family.
     *
     * @return list<Record>
     */
    public function expected(string ...$changes): array
    {
        $records = [];

        foreach ($changes as $name) {
            $change = self::CHANGES[$name];
            [$mutator, $family] = $this->vocabulary[$name];
            $diff = sprintf("@@ @@\n-%s\n+%s", $change['removed'], $change['added']);
            $id = MutantId::hash(Path::of($change['file']), $mutator, $diff, 0)->value();
            $line = $change['line'];
            $end = $this->endsReported ? $line : 0;
            $records[] = [$id, $change['status']->value, $change['file'], $line, $end, $mutator, $family->value];
        }

        return $records;
    }

    /** @return list<Record> */
    public static function records(Mutants $mutants): array
    {
        return array_map(static function (Mutant $mutant): array {
            $end = $mutant->location()->end();

            return [
                $mutant->id()->value(),
                $mutant->status()->value,
                $mutant->location()->file()->value(),
                $mutant->location()->start()->number(),
                $end instanceof Line ? $end->number() : 0,
                $mutant->mutation()->mutator(),
                $mutant->mutation()->family()->value,
            ];
        }, iterator_to_array($mutants, preserve_keys: false));
    }

    /** The canary group a patched shard opens on. */
    public static function canary(): Group
    {
        return Group::named(self::CANARY);
    }
}

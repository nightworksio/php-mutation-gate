<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Contract\Runner;

use function array_key_exists;
use function array_map;
use function is_dir;
use function iterator_to_array;

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Tree;

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

    public const string CANARY = 'mutation-canary';

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
            'status' => MutantStatus::Killed,
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
        'adds' => ['Pest\Mutate\Mutators\Arithmetic\PlusToMinus', MutatorFamily::Arithmetic],
        'large' => ['Pest\Mutate\Mutators\Equality\GreaterToGreaterOrEqual', MutatorFamily::Boundary],
        'unused' => ['Pest\Mutate\Mutators\Arithmetic\MinusToPlus', MutatorFamily::Arithmetic],
        'drains' => ['Pest\Mutate\Mutators\Arithmetic\PostDecrementToPostIncrement', MutatorFamily::Arithmetic],
        'held' => ['Pest\Mutate\Mutators\Arithmetic\PlusToMinus', MutatorFamily::Arithmetic],
    ];

    /** @var array<string, MutationResult|CannotJudge> each real run's answer, by library and request */
    private static array $runs = [];

    /** @param array<string, array{string, MutatorFamily}> $vocabulary */
    private function __construct(
        private readonly string $name,
        private readonly Runner $runner,
        private readonly array $vocabulary,
    ) {
    }

    public static function fake(): self
    {
        return new self('fake', RunnerFake::ofTheFixture(), self::FAKE);
    }

    /** The Pest adapter over the installed library, with `pest:patch` off or on. */
    public static function pest(Patching $patching): self
    {
        $root = Tree::at(self::DIRECTORY);
        $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'));

        $name = sprintf('pest %s', $patching->isOn() ? 'patched' : 'unpatched');

        return new self($name, new Pest($project, new ProcessShell($root), $patching), self::PEST);
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
            $records[] = [$id, $change['status']->value, $change['file'], $line, $line, $mutator, $family->value];
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

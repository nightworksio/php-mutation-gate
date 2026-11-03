<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_any;
use function array_filter;
use function array_shift;
use function array_values;
use function basename;
use function count;
use function ltrim;

use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Test\Role;

use function sprintf;
use function str_contains;

/**
 * The tests that use a fixture project `reach.fixtures` names: every file of
 * test cases outside it with a string that names its path or a path inside
 * it, spelt from the file's own directory, a directory above it or the root,
 * or that names a class or function one of the project's PHP files declares;
 * the tests that use support that does either, as {@see SupportUsers} tells
 * them; and the tests that use each other fixture one of whose files does.
 * Support that runs code when it is loaded, or a file that is not a test,
 * naming it reaches every unit of the package, and so does a fixture project
 * no test is found to name, since a path built as a test runs is not found.
 *
 * @internal
 */
final readonly class FixtureUsers
{
    /** Why a fixture project no test is found to name reaches every unit of the package. */
    private const string UNNAMED
        = 'No test is found to name the fixture `%s`, so what reads it cannot be told: every unit of %s is reached.';

    /** Why a file that names a fixture project and is not a test reaches every unit of the package. */
    private const string ELSEWHERE = '`%s` names the fixture `%s` and is no test, so every unit of %s is reached.';

    /** @param list<array{Path, PhpFile, Role}> $files every PHP file on disk, read, with what it is to the tests */
    private function __construct(private Layout $layout, private SupportUsers $support, private array $files)
    {
    }

    /** @param list<array{Path, PhpFile, Role}> $files every PHP file on disk, read, with what it is to the tests */
    public static function over(Layout $layout, SupportUsers $support, array $files): self
    {
        return new self($layout, $support, $files);
    }

    /**
     * The files of test cases that use each fixture project these changes
     * are in, as they are or as they were, or why every unit of its package
     * is reached, by its path: each read once, however many of its files
     * changed.
     *
     * @return array<string, Paths|Reason>
     */
    public function ofChanges(Changes $changes, Packages $packages): array
    {
        $users = [];

        foreach ($changes as $change) {
            foreach ($this->layout->fixturesOf($change) as $fixture) {
                $users[$fixture->value()] ??= $this->of($fixture, TestReach::named($packages->holding($fixture)));
            }
        }

        return $users;
    }

    /**
     * The files of test cases that use a fixture project, or why every unit
     * of its package is reached: where none is found, what reads it cannot be
     * told.
     */
    public function of(Path $root, string $package): Paths|Reason
    {
        $fixtures = [$root];
        $seen = Paths::of($root);
        $tests = Paths::none();

        while ($fixtures !== [] && $tests instanceof Paths) {
            [$users, $inside] = $this->naming(array_shift($fixtures), $package);
            $tests = $users instanceof Reason ? $users : $tests->and($users);
            $fixtures = [...$fixtures, ...$inside->without($seen)];
            $seen = $seen->and($inside);
        }

        return $tests instanceof Paths && count($tests) === 0
            ? Reason::that(sprintf(self::UNNAMED, $root->value(), $package))
            : $tests;
    }

    /**
     * The tests that use a fixture project through the files outside every
     * fixture that name it, or why every unit is reached; and the other
     * fixtures one of whose files names it.
     *
     * @return array{Paths|Reason, Paths}
     */
    private function naming(Path $fixture, string $package): array
    {
        $tests = Paths::none();
        $fixtures = Paths::none();

        foreach ($this->namers($fixture) as [$path, $file, $role]) {
            $inside = $this->layout->fixtureHolding($path);
            $fixtures = $inside instanceof Path ? $fixtures->with($inside) : $fixtures;
            $users = $inside instanceof Path ? Paths::none() : $this->using($path, $file, $role, $fixture, $package);

            if ($users instanceof Reason) {
                return [$users, $fixtures];
            }

            $tests = $tests->and($users);
        }

        return [$tests, $fixtures];
    }

    /**
     * The files outside a fixture project with a string that names it, or
     * that name a class or function one of its PHP files declares.
     *
     * @return list<array{Path, PhpFile, Role}>
     */
    private function namers(Path $fixture): array
    {
        $declared = $this->declaredIn($fixture);

        return array_values(array_filter(
            $this->files,
            static fn(array $file): bool => ! $file[0]->within($fixture)
                && (self::names($file[1], $file[0], $fixture) || $file[1]->mentioned()->meet($declared)),
        ));
    }

    /** Every class and function the PHP files of a fixture project declare. */
    private function declaredIn(Path $fixture): Names
    {
        $declared = Names::of();

        foreach ($this->files as [$path, $file]) {
            $declared = $path->within($fixture) ? $declared->merge($file->declares()) : $declared;
        }

        return $declared;
    }

    /** The tests a file outside every fixture project uses one through, by what it is to them. */
    private function using(Path $path, PhpFile $file, Role $role, Path $fixture, string $package): Paths|Reason
    {
        return match ($role) {
            Role::TestCase => Paths::of($path),
            Role::Support, Role::Loaded => $this->support->of($path, $file->declares(), $package),
            Role::Elsewhere => Reason::that(sprintf(self::ELSEWHERE, $path->value(), $fixture->value(), $package)),
        };
    }

    /**
     * Whether a string of a file names a fixture project's path, or a path
     * inside it, spelt from the file's own directory, from any directory
     * above it, or from the repository's root. A string that names it holds
     * its last segment, so no other string is spelt out.
     */
    private static function names(PhpFile $file, Path $path, Path $fixture): bool
    {
        $name = basename($fixture->value());

        return array_any(
            $file->strings(),
            static fn(string $string): bool => str_contains($string, $name)
                && self::spells(Path::of(ltrim($string, '/')), $path, $fixture),
        );
    }

    /** Whether a path, spelt from a file's own directory, a directory above it or the root, is in a fixture. */
    private static function spells(Path $spelt, Path $file, Path $fixture): bool
    {
        $directory = $file->directory();

        while (! $directory->equals(Path::root())) {
            if ($directory->child($spelt)->collapsed()->within($fixture)) {
                return true;
            }

            $directory = $directory->directory();
        }

        return $spelt->collapsed()->within($fixture);
    }
}

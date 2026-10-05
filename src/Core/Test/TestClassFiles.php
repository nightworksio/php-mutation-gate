<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;
use function array_keys;
use function array_values;

use Closure;

use function mb_strrpos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/**
 * The test files that declare some test classes. PHPUnit loads a test class
 * from the file named after it, so only a file named after a class wanted is
 * worth reading, and it counts when its tokens declare the class. The first
 * file read that declares a class is the class's file.
 */
final readonly class TestClassFiles
{
    /**
     * @param array<string, list<string>> $wanted each class wanted, by its short name
     * @param array<string, Path>         $found  the file that declares each class found, by the class
     */
    private function __construct(private array $wanted, private array $found)
    {
    }

    /** @param list<string> $classes fully qualified */
    public static function wanting(array $classes): self
    {
        $wanted = [];

        foreach ($classes as $class) {
            $separator = mb_strrpos($class, '\\');
            $wanted[$separator === false ? $class : mb_substr($class, $separator + 1)][] = $class;
        }

        return new self($wanted, []);
    }

    /** The classes these tests are in. */
    public static function of(TestIds $tests): self
    {
        $classes = [];

        foreach ($tests as $test) {
            $classes[TestMethod::classOf($test)] = true;
        }

        return self::wanting(array_keys($classes));
    }

    /** Whether a file is named after a class wanted, so that its tokens are worth reading. */
    public function mayDeclare(Path $file): bool
    {
        return array_key_exists($file->stem(), $this->wanted);
    }

    /** These, with each class wanted that a file's code declares and no earlier file did found in the file. */
    public function readIn(Path $file, Contents $code): self
    {
        $declared = PhpFile::read($code)->declares();
        $found = $this->found;

        foreach ($this->mayDeclare($file) ? $this->wanted[$file->stem()] : [] as $class) {
            $found += $declared->meet(Names::of($class)) ? [$class => $file] : [];
        }

        return new self($this->wanted, $found);
    }

    /**
     * Those of these tests that these test files hold: the tests of each
     * class a file declares, by what it holds, and of each class named after
     * a file that is gone.
     *
     * @param Closure(Path): (Contents|Missing) $read what a test file holds, or that it is gone
     */
    public static function held(TestIds $tests, Paths $files, Closure $read): TestIds
    {
        $classes = self::of($tests);

        foreach ($files as $file) {
            $code = $read($file);
            $classes = $code instanceof Contents ? $classes->readIn($file, $code) : $classes->gone($file);
        }

        return $classes->placed($tests);
    }

    /** @return array<string, Path> the file that declares each class found, by the class */
    public function byClass(): array
    {
        return $this->found;
    }

    /** Every file that declares a class found, in the order the files were read. */
    public function files(): Paths
    {
        return Paths::of(...array_values($this->found));
    }

    /** Whether each of these tests is in a class found, so that each is in one of the files. */
    public function placeEach(TestIds $tests): bool
    {
        foreach ($tests as $test) {
            if (! array_key_exists(TestMethod::classOf($test), $this->found)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Each of these tests as PHPUnit's JUnit log names it: the file that
     * declares its class, and its method's name, with the data set row where
     * its id runs one. A test in no class found is left out.
     */
    public function names(TestIds $tests): TestNames
    {
        $names = TestNames::none();

        foreach ($tests as $test) {
            $method = TestMethod::of($test);
            $names = $method instanceof TestMethod && array_key_exists($method->className(), $this->found)
                ? $names->with($test, $method->in($this->found[$method->className()], $method->method()))
                : $names;
        }

        return $names;
    }

    /**
     * These, with each class wanted that is named after a file that is gone
     * found in it: the file's tests went with it.
     */
    private function gone(Path $file): self
    {
        $found = $this->found;

        foreach ($this->mayDeclare($file) ? $this->wanted[$file->stem()] : [] as $class) {
            $found += [$class => $file];
        }

        return new self($this->wanted, $found);
    }

    /** Those of these tests whose class is found. */
    private function placed(TestIds $tests): TestIds
    {
        $placed = [];

        foreach ($tests as $test) {
            if (array_key_exists(TestMethod::classOf($test), $this->found)) {
                $placed[] = $test;
            }
        }

        return TestIds::of(...$placed);
    }
}

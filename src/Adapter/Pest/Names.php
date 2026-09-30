<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\TestNames;

use function sprintf;

/**
 * The names the plugin wrote for a run that listed the tests: each test by
 * the file Pest built it from and the description Pest gives it, or a PHPUnit
 * test by its class's file and its method's name, with the data set row
 * where its id runs one.
 */
final readonly class Names
{
    /** The file the plugin names the tests in, in the gate's directory. */
    private const string FILE = 'pest/names.json';

    private const string UNNAMED = "Pest did not name the suite's tests. Pest said:\n%s";

    /**
     * @param array<string, array{string, string}> $tests each test's file on disk and description, by
     *                                                    `<class>::<method>`
     */
    private function __construct(private array $tests)
    {
    }

    /**
     * These tests, as the plugin names them in a run that lists the suite's
     * tests and runs none, or why it named nothing. Listing loads the
     * project's code, which never sees the variables withheld.
     */
    public static function listed(
        Project $project,
        Shell $shell,
        Withheld $withheld,
        TestIds $ids,
    ): TestNames|CannotJudge {
        $file = $project->fresh(self::FILE);
        $names = $file instanceof CannotJudge ? $file : self::written(
            $shell->run(Invocation::installedIn($project->vendor())->listingTests($withheld, $file)),
            $file,
        );

        return $names instanceof CannotJudge ? $names : $names->of($project, $ids);
    }

    /** The names in the file a run wrote, or why the run named nothing. */
    private static function written(Ran $ran, string $file): self|CannotJudge
    {
        $text = $ran->succeeded() && is_file($file) ? file_get_contents($file) : false;

        return is_string($text) ? self::read($text) : CannotJudge::because(sprintf(self::UNNAMED, $ran->output()));
    }

    /** Each of these tests the run named, with its file as the project spells it. */
    private function of(Project $project, TestIds $ids): TestNames
    {
        $names = TestNames::none();

        foreach ($ids as $id) {
            $method = TestMethod::of($id);
            $key = sprintf('%s::%s', $method->className(), $method->method());
            $names = array_key_exists($key, $this->tests)
                ? $names->with($id, $method->in($project->relative($this->tests[$key][0]), $this->tests[$key][1]))
                : $names;
        }

        return $names;
    }

    private static function read(string $text): self
    {
        $tests = [];

        foreach (Lenient::items(Node::decode($text)) as $test) {
            $tests[Lenient::text($test->field('test'))] = [
                Lenient::text($test->field('file')),
                Lenient::text($test->field('description')),
            ];
        }

        return new self($tests);
    }
}

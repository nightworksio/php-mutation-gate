<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Extension\Options;

/**
 * What the `pest` runner's options say: `patch`, whether the project applies
 * `pest:patch`, false by default; `canary`, the group a patched shard opens
 * on, `mutation-canary` by default; and `tests`, the directories the tests
 * live in, `tests` by default.
 */
final readonly class PestOptions
{
    private const string PATCH = 'patch';

    private const string CANARY = 'canary';

    private const string TESTS = 'tests';

    private const string CANARY_GROUP = 'mutation-canary';


    private function __construct(private Patching $patching, private Paths $tests)
    {
    }

    public static function read(Options $options): self|Invalid
    {
        $with = Node::decode($options->json());
        $patch = self::patchIn($with);
        $canary = self::canaryIn($with);
        $tests = self::testsIn($with);

        return match (true) {
            $patch instanceof Problem => Invalid::because($patch),
            $canary instanceof Problem => Invalid::because($canary),
            $tests instanceof Problem => Invalid::because($tests),
            default => new self($patch ? Patching::on($canary) : Patching::off(), $tests),
        };
    }

    public function patching(): Patching
    {
        return $this->patching;
    }

    public function tests(): Paths
    {
        return $this->tests;
    }

    private static function patchIn(Node $with): bool|Problem
    {
        try {
            return $with->field(self::PATCH)->isPresent() && $with->field(self::PATCH)->boolean();
        } catch (NotInShape) {
            return Problem::at(self::PATCH, 'Whether the project applies pest:patch is true or false.');
        }
    }

    private static function canaryIn(Node $with): Group|Problem
    {
        try {
            return Group::named(
                $with->field(self::CANARY)->isPresent() ? $with->field(self::CANARY)->text() : self::CANARY_GROUP,
            );
        } catch (NotInShape) {
            return Problem::at(self::CANARY, 'The canary is the name of a group, as text.');
        }
    }

    private static function testsIn(Node $with): Paths|Problem
    {
        if (! $with->field(self::TESTS)->isPresent()) {
            return Paths::of(TestsDirectory::conventional());
        }

        try {
            $paths = Paths::none();

            foreach ($with->field(self::TESTS)->items() as $item) {
                $paths = $paths->with(Path::of($item->text()));
            }

            return $paths;
        } catch (NotInShape) {
            return Problem::at(self::TESTS, 'The tests are a list of directories, each as text.');
        }
    }
}

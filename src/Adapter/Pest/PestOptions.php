<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Pest as ConfigPest;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;

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

    private function __construct(private Patching $patching, private Paths $tests)
    {
    }

    public static function read(Options $options): self|Invalid
    {
        $patch = $options->flag(Key::of(self::PATCH));
        $canary = $options->text(Key::of(self::CANARY));
        $tests = $options->paths(Key::of(self::TESTS));

        return match (true) {
            $patch instanceof Problem => Invalid::because($patch),
            $canary instanceof Problem => Invalid::because($canary),
            $tests instanceof Problem => Invalid::because($tests),
            default => new self(
                $patch === true
                    ? Patching::on(
                        Group::named($canary instanceof NotGiven ? ConfigPest::none()->canary()->name() : $canary),
                    )
                    : Patching::off(),
                $tests instanceof NotGiven ? Paths::of(TestsDirectory::conventional()) : $tests,
            ),
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
}

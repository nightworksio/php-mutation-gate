<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Pest as ConfigPest;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\TighterOptions;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;

/**
 * What the `pest` runner's options say: `patch`, whether the project applies
 * `pest:patch`, false by default; `canary`, the group a patched shard opens
 * on, `mutation-canary` by default; `tests`, the directories the tests live
 * in, which the flows write from the PHPUnit config, `tests` by default;
 * `timeout` and `most`, the floor and the most of each mutant's limit, which
 * the flows write from `timeouts.seconds` and `timeouts.most`; and
 * `mutators`, the classes of the registered mutators Pest makes mutants with
 * beside its own, which the flows write (ADR-0021), none by default.
 */
final readonly class PestOptions
{
    /** The option that holds the registered mutators' classes, which the flows write. */
    public const string MUTATORS = 'mutators';

    /** The option that holds `timeouts.seconds`, which the flows write. */
    public const string TIMEOUT = 'timeout';

    /** The option that holds `timeouts.most`, which the flows write. */
    public const string MOST = 'most';

    /** The option that holds the directories the tests are in, which the flows write from the PHPUnit config. */
    public const string TESTS = 'tests';

    private const string PATCH = 'patch';

    private const string CANARY = 'canary';

    private function __construct(
        private Patching $patching,
        private Paths $tests,
        private Bridges $bridges,
        private LimitBounds $bounds,
    ) {
    }

    public static function read(Options $options): self|Invalid
    {
        $patch = $options->flag(Key::of(self::PATCH));
        $canary = $options->text(Key::of(self::CANARY));
        $tests = $options->paths(Key::of(self::TESTS));
        $mutators = $options->texts(Key::of(self::MUTATORS));
        $timeout = $options->number(Key::of(self::TIMEOUT));
        $most = $options->number(Key::of(self::MOST));
        $tighter = TighterOptions::read($options);

        return match (true) {
            $patch instanceof Problem => Invalid::because($patch),
            $canary instanceof Problem => Invalid::because($canary),
            $tests instanceof Problem => Invalid::because($tests),
            $mutators instanceof Problem => Invalid::because($mutators),
            $timeout instanceof Problem => Invalid::because($timeout),
            $most instanceof Problem => Invalid::because($most),
            $tighter instanceof Problem => Invalid::because($tighter),
            default => new self(
                $patch === true
                    ? Patching::on(
                        Group::named($canary instanceof NotGiven ? ConfigPest::none()->canary()->name() : $canary),
                    )
                    : Patching::off(),
                $tests instanceof NotGiven ? Paths::of(TestsDirectory::conventional()) : $tests,
                self::bridgesTo($mutators instanceof Listed ? [...$mutators] : []),
                LimitBounds::between(
                    $timeout instanceof NotGiven ? Triage::standard()->limit() : Seconds::of($timeout),
                    $most instanceof NotGiven ? Triage::standard()->most() : Seconds::of($most),
                )->tighterFor($tighter),
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

    /** `timeouts.seconds` and `timeouts.most`: what a patched run and every trial run keep each limit between. */
    public function bounds(): LimitBounds
    {
        return $this->bounds;
    }

    /** The bridges to the registered mutators the options name, or why Pest cannot make mutants with one. */
    public function bridges(): Bridges
    {
        return $this->bridges;
    }

    /** @param list<string> $classes */
    private static function bridgesTo(array $classes): Bridges
    {
        $enabled = Enabled::named(BuiltinRunner::Pest, ...$classes);

        return $enabled instanceof CannotJudge ? Bridges::refusing($enabled) : Bridges::to($enabled);
    }
}

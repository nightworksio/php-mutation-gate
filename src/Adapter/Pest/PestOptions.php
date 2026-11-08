<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Pest as ConfigPest;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\SplitStream;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;

/**
 * What the `pest` runner's options say: `patch`, whether the project applies
 * `pest:patch`, false by default; `canary`, the group a patched shard opens
 * on, `mutation-canary` by default; `bootTail`, whether the verification
 * tail's workers boot the project (ADR-0075), false by default;
 * `workerOpcache`, whether its warm workers compile through opcache
 * (ADR-0088), true by default; `schemata`, whether each file's mutants are
 * compiled into a schema (ADR-0091), true by default; `splitStream`,
 * whether and how a patched run judges its mutants in split streams
 * (ADR-0117), off by default; `tests`, the directories the tests live
 * in, which the flows write from the PHPUnit config, `tests` by default;
 * `timeout` and `most`, the floor and the most of each mutant's limit, which
 * the flows write from `timeouts.seconds` and `timeouts.most`; and
 * `mutators`, the classes of the registered mutators Pest makes mutants with
 * beside its own, which the flows write (ADR-0021), none by default.
 *
 * @internal
 */
final readonly class PestOptions
{
    /** The option that holds the registered mutators' classes, which the flows write. */
    public const string MUTATORS = 'mutators';

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
        $bootTail = $options->flag(Key::of(ConfigPest::BOOT_TAIL));
        $opcache = $options->flag(Key::of(ConfigPest::WORKER_OPCACHE));
        $schemata = $options->flag(Key::of(ConfigPest::SCHEMATA));
        $bootMutants = $options->flag(Key::of(ConfigPest::BOOT_MUTANTS));
        $tests = $options->paths(Key::of(self::TESTS));
        $mutators = $options->texts(Key::of(self::MUTATORS));
        $bounds = BoundsOptions::read($options);
        $streams = self::streamsOf($options);

        return match (true) {
            $patch instanceof Problem => Invalid::because($patch),
            $canary instanceof Problem => Invalid::because($canary),
            $bootTail instanceof Problem => Invalid::because($bootTail),
            $opcache instanceof Problem => Invalid::because($opcache),
            $schemata instanceof Problem => Invalid::because($schemata),
            $bootMutants instanceof Problem => Invalid::because($bootMutants),
            $tests instanceof Problem => Invalid::because($tests),
            $mutators instanceof Problem => Invalid::because($mutators),
            $bounds instanceof Problem => Invalid::because($bounds),
            $streams instanceof Problem => Invalid::because($streams),
            default => new self(
                self::patchingOf($patch, $canary)
                    ->bootingTail($bootTail === true)
                    ->cachingInWorkers($opcache !== false)
                    ->compilingSchemata($schemata !== false)
                    ->bootingMutants($bootMutants !== false)
                    ->splitting($streams),
                $tests instanceof NotGiven ? Paths::of(TestsDirectory::conventional()) : $tests,
                self::bridgesTo($mutators instanceof Listed ? [...$mutators] : []),
                $bounds,
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

    /** Whether a run is patched, and the group of its canary where it is. */
    private static function patchingOf(bool|NotGiven $patch, string|NotGiven $canary): Patching
    {
        return $patch === true
            ? Patching::on(Group::named($canary instanceof NotGiven ? ConfigPest::none()->canary()->name() : $canary))
            : Patching::off();
    }

    /** `splitStream`: whether, and how, a patched run judges its mutants in split streams (ADR-0117); off where unset. */
    private static function streamsOf(Options $options): StreamSettings|Problem
    {
        $split = $options->object(Key::of(SplitStream::SECTION));

        if (! $split instanceof Options) {
            return $split instanceof Problem ? $split : StreamSettings::off();
        }

        $enabled = $split->flag(Key::of(SplitStream::ENABLED));
        $children = $split->integer(Key::of(SplitStream::CHILDREN));
        $changeCheck = $split->flag(Key::of(SplitStream::CHANGE_CHECK));
        $killersFirst = $split->flag(Key::of(SplitStream::KILLERS_FIRST));
        $standard = SplitStream::of();

        return match (true) {
            $enabled instanceof Problem => $enabled,
            $children instanceof Problem => $children,
            $changeCheck instanceof Problem => $changeCheck,
            $killersFirst instanceof Problem => $killersFirst,
            default => StreamSettings::of(
                $enabled === true,
                $children instanceof NotGiven ? $standard->children() : $children,
                $changeCheck !== false,
                $killersFirst !== false,
            ),
        };
    }

    /** @param list<string> $classes */
    private static function bridgesTo(array $classes): Bridges
    {
        $enabled = Enabled::named(BuiltinRunner::Pest, ...$classes);

        return $enabled instanceof CannotJudge ? Bridges::refusing($enabled) : Bridges::to($enabled);
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;

use Closure;
use LogicException;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ConfigLoaderFake;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;

use function sprintf;

/**
 * Each extension point an adapter is registered at, with how to register one
 * under the name "it", a fake of its port, and how to look it up again.
 */
final class Registering
{
    /** @return list<ExtensionPoint> every point but presets and mutator sets, which are data rather than adapters */
    public static function adapterPoints(): array
    {
        return array_filter(
            ExtensionPoint::cases(),
            static fn(ExtensionPoint $point): bool => $point !== ExtensionPoint::Preset
                && $point !== ExtensionPoint::MutatorSet,
        );
    }

    /**
     * An adapter registered under the name "it", built as the port its point takes, or the problems it found.
     *
     * @param Closure(Options): object $build
     */
    public static function register(ExtensionPoint $point, Extensions $registry, Closure $build): Extensions
    {
        $name = Name::of('it');

        return match ($point) {
            ExtensionPoint::Runner => $registry->withRunner(
                $name,
                static fn(Options $options): Runner|Invalid => self::built($build($options), Runner::class),
            ),
            ExtensionPoint::TreeSource => $registry->withTreeSource(
                $name,
                static fn(Options $options): TreeSource|Invalid => self::built($build($options), TreeSource::class),
            ),
            ExtensionPoint::CostModel => $registry->withCostModel(
                $name,
                static fn(Options $options): CostModel|Invalid => self::built($build($options), CostModel::class),
            ),
            ExtensionPoint::ProofStore => $registry->withProofStore(
                $name,
                static fn(Options $options): ProofStore|Invalid => self::built($build($options), ProofStore::class),
            ),
            ExtensionPoint::CiPlan => $registry->withCiPlan(
                $name,
                static fn(Options $options): CiPlan|Invalid => self::built($build($options), CiPlan::class),
            ),
            ExtensionPoint::Reporter => $registry->withReporter(
                $name,
                static fn(Options $options): Reporter|Invalid => self::built($build($options), Reporter::class),
            ),
            ExtensionPoint::ChangeSource => $registry->withChangeSource(
                $name,
                static fn(Options $options): ChangeSource|Invalid => self::built($build($options), ChangeSource::class),
            ),
            ExtensionPoint::Repository => $registry->withRepository(
                $name,
                static fn(Options $options): Repository|Invalid => self::built($build($options), Repository::class),
            ),
            ExtensionPoint::ConfigLoader => $registry->withConfigLoader(
                $name,
                static fn(Options $options): ConfigLoader|Invalid => self::built($build($options), ConfigLoader::class),
            ),
            ExtensionPoint::StaticChecker => $registry->withStaticChecker(
                $name,
                static fn(Options $options): StaticChecker|Invalid => self::built($build($options), StaticChecker::class),
            ),
            ExtensionPoint::Preset => throw new LogicException('A preset is registered as a document.'),
            ExtensionPoint::MutatorSet => throw new LogicException('A mutator set is registered as a list of classes.'),
        };
    }

    /**
     * A build registered as it is given, whatever it makes, to see a lookup refuse one that makes the wrong port.
     * It is a bare closure because no port's signature describes a build that is wrong on purpose.
     */
    public static function registerMisbuilt(
        ExtensionPoint $point,
        Name $name,
        Extensions $registry,
        Closure $build,
    ): Extensions {
        return match ($point) {
            ExtensionPoint::Runner => $registry->withRunner($name, $build),
            ExtensionPoint::TreeSource => $registry->withTreeSource($name, $build),
            ExtensionPoint::CostModel => $registry->withCostModel($name, $build),
            ExtensionPoint::ProofStore => $registry->withProofStore($name, $build),
            ExtensionPoint::CiPlan => $registry->withCiPlan($name, $build),
            ExtensionPoint::Reporter => $registry->withReporter($name, $build),
            ExtensionPoint::ChangeSource => $registry->withChangeSource($name, $build),
            ExtensionPoint::Repository => $registry->withRepository($name, $build),
            ExtensionPoint::ConfigLoader => $registry->withConfigLoader($name, $build),
            ExtensionPoint::StaticChecker => $registry->withStaticChecker($name, $build),
            ExtensionPoint::Preset => throw new LogicException('A preset is registered as a document.'),
            ExtensionPoint::MutatorSet => throw new LogicException('A mutator set is registered as a list of classes.'),
        };
    }

    /** A fake of the port an adapter at this point answers. */
    public static function adapter(ExtensionPoint $point): object
    {
        return match ($point) {
            ExtensionPoint::Runner => RunnerFake::ofTheFixture(),
            ExtensionPoint::TreeSource => TreeSourceFake::ofTheFixture(),
            ExtensionPoint::CostModel => new CostModelFake(Seconds::of(1.0)),
            ExtensionPoint::ProofStore => new ProofStoreFake(),
            ExtensionPoint::CiPlan => new CiPlanFake(ShardId::of(1), CannotTell::because('A fake run.')),
            ExtensionPoint::Reporter => new ReporterFake(),
            ExtensionPoint::ChangeSource => ChangeSourceFake::ofTheFixture(),
            ExtensionPoint::Repository => RepositoryFake::onMain(Revision::ref('5eeca8f')),
            ExtensionPoint::ConfigLoader => ConfigLoaderFake::ofTheFixture(),
            ExtensionPoint::StaticChecker => StaticCheckerFake::findingNothing(),
            ExtensionPoint::Preset => throw new LogicException('A preset is no adapter.'),
            ExtensionPoint::MutatorSet => throw new LogicException('A mutator set is no adapter.'),
        };
    }

    public static function lookUp(ExtensionPoint $point, Extensions $registry, Options $options): object
    {
        $lookup = Lookup::in($registry);

        return match ($point) {
            ExtensionPoint::Runner => $lookup->runner(Name::of('it'), $options),
            ExtensionPoint::TreeSource => $lookup->treeSource(Name::of('it'), $options),
            ExtensionPoint::CostModel => $lookup->costModel(Name::of('it'), $options),
            ExtensionPoint::ProofStore => $lookup->proofStore(Name::of('it'), $options),
            ExtensionPoint::CiPlan => $lookup->ciPlan(Name::of('it'), $options),
            ExtensionPoint::Reporter => $lookup->reporter(Name::of('it'), $options),
            ExtensionPoint::ChangeSource => $lookup->changeSource(Name::of('it'), $options),
            ExtensionPoint::Repository => $lookup->repository(Name::of('it'), $options),
            ExtensionPoint::ConfigLoader => $lookup->configLoader(Name::of('it'), $options),
            ExtensionPoint::StaticChecker => $lookup->staticChecker(Name::of('it'), $options),
            ExtensionPoint::Preset => $lookup->preset(Name::of('it')),
            ExtensionPoint::MutatorSet => $lookup->mutatorSet(Name::of('it')),
        };
    }

    /**
     * What a build made, as the port its point takes, or the problems it found.
     *
     * @template T of object
     *
     * @param  class-string<T> $port
     * @return T|Invalid
     */
    private static function built(object $built, string $port): object
    {
        return $built instanceof $port || $built instanceof Invalid
            ? $built
            : throw new LogicException(sprintf('A build registered through register() made no %s.', $port));
    }
}

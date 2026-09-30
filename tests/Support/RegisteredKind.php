<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ConfigLoaderFake;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;

/**
 * Each kind of adapter an extension registers, with how to register one under
 * the name "it", a fake of its port, and how to look it up again.
 */
enum RegisteredKind: string
{
    case Runner = 'runner';
    case TreeSource = 'tree source';
    case CostModel = 'cost model';
    case ProofStore = 'proof store';
    case CiPlan = 'CI plan';
    case Reporter = 'reporter';
    case ChangeSource = 'change source';
    case Repository = 'repository';
    case ConfigLoader = 'config loader';

    public function register(Extensions $registry, Closure $build): Extensions
    {
        return match ($this) {
            self::Runner => $registry->withRunner(Name::of('it'), $build),
            self::TreeSource => $registry->withTreeSource(Name::of('it'), $build),
            self::CostModel => $registry->withCostModel(Name::of('it'), $build),
            self::ProofStore => $registry->withProofStore(Name::of('it'), $build),
            self::CiPlan => $registry->withCiPlan(Name::of('it'), $build),
            self::Reporter => $registry->withReporter(Name::of('it'), $build),
            self::ChangeSource => $registry->withChangeSource(Name::of('it'), $build),
            self::Repository => $registry->withRepository(Name::of('it'), $build),
            self::ConfigLoader => $registry->withConfigLoader(Name::of('it'), $build),
        };
    }

    /** A fake of the port this kind registers. */
    public function adapter(): object
    {
        return match ($this) {
            self::Runner => RunnerFake::ofTheFixture(),
            self::TreeSource => TreeSourceFake::ofTheFixture(),
            self::CostModel => new CostModelFake(Seconds::of(1.0)),
            self::ProofStore => new ProofStoreFake(),
            self::CiPlan => new CiPlanFake(ShardId::of(1), CannotTell::because('A fake run.')),
            self::Reporter => new ReporterFake(),
            self::ChangeSource => ChangeSourceFake::ofTheFixture(),
            self::Repository => RepositoryFake::onMain(Revision::ref('5eeca8f')),
            self::ConfigLoader => ConfigLoaderFake::ofTheFixture(),
        };
    }

    public function lookUp(Extensions $registry, Options $options): object
    {
        $lookup = Lookup::in($registry);

        return match ($this) {
            self::Runner => $lookup->runner(Name::of('it'), $options),
            self::TreeSource => $lookup->treeSource(Name::of('it'), $options),
            self::CostModel => $lookup->costModel(Name::of('it'), $options),
            self::ProofStore => $lookup->proofStore(Name::of('it'), $options),
            self::CiPlan => $lookup->ciPlan(Name::of('it'), $options),
            self::Reporter => $lookup->reporter(Name::of('it'), $options),
            self::ChangeSource => $lookup->changeSource(Name::of('it'), $options),
            self::Repository => $lookup->repository(Name::of('it'), $options),
            self::ConfigLoader => $lookup->configLoader(Name::of('it'), $options),
        };
    }
}

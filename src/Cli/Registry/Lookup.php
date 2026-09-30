<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Registry;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;

use function sprintf;

/**
 * What the extensions registered, looked up by name and built from its
 * options. Extensions only register; the composition root looks up, and
 * refuses what a registration built that is not what its extension point
 * takes.
 */
final readonly class Lookup
{
    private const string MISBUILT = 'The %s registered as "%s" built something that is not a %s.';

    private function __construct(private Extensions $extensions)
    {
    }

    public static function in(Extensions $extensions): self
    {
        return new self($extensions);
    }

    public function runner(Name $name, Options $options): Runner|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::Runner, $name, $options, Runner::class);
    }

    public function treeSource(Name $name, Options $options): TreeSource|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::TreeSource, $name, $options, TreeSource::class);
    }

    public function costModel(Name $name, Options $options): CostModel|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::CostModel, $name, $options, CostModel::class);
    }

    public function proofStore(Name $name, Options $options): ProofStore|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::ProofStore, $name, $options, ProofStore::class);
    }

    public function ciPlan(Name $name, Options $options): CiPlan|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::CiPlan, $name, $options, CiPlan::class);
    }

    public function reporter(Name $name, Options $options): Reporter|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::Reporter, $name, $options, Reporter::class);
    }

    public function changeSource(Name $name, Options $options): ChangeSource|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::ChangeSource, $name, $options, ChangeSource::class);
    }

    public function repository(Name $name, Options $options): Repository|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::Repository, $name, $options, Repository::class);
    }

    public function configLoader(Name $name, Options $options): ConfigLoader|Invalid|CannotJudge
    {
        return $this->built(ExtensionPoint::ConfigLoader, $name, $options, ConfigLoader::class);
    }

    public function preset(Name $name): Layer|CannotJudge
    {
        $preset = $this->extensions->registered(ExtensionPoint::Preset, $name);

        $found = $preset instanceof Layer || $preset instanceof CannotJudge;

        return $found ? $preset : $this->misbuilt(ExtensionPoint::Preset, $name);
    }

    /** The mutators a set registered under this name holds. */
    public function mutatorSet(Name $name): MutatorSet|CannotJudge
    {
        $set = $this->extensions->registered(ExtensionPoint::MutatorSet, $name);

        $found = $set instanceof MutatorSet || $set instanceof CannotJudge;

        return $found ? $set : $this->misbuilt(ExtensionPoint::MutatorSet, $name);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>       $port
     * @return T|Invalid|CannotJudge
     */
    private function built(ExtensionPoint $point, Name $name, Options $options, string $port): object
    {
        $build = $this->extensions->registered($point, $name);
        $built = $build instanceof Closure ? $build($options) : $build;

        return $built instanceof $port || $built instanceof Invalid || $built instanceof CannotJudge
            ? $built
            : $this->misbuilt($point, $name);
    }

    private function misbuilt(ExtensionPoint $point, Name $name): CannotJudge
    {
        return CannotJudge::because(sprintf(self::MISBUILT, $point->value, $name->value(), $point->value));
    }
}

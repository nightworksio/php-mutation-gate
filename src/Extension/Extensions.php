<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use Closure;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Detections;
use NightWorksIO\MutationGate\Core\Registry\Entries;
use NightWorksIO\MutationGate\Core\Registry\Entry;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
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

use function sprintf;

/**
 * Every adapter, preset and set of mutators the extensions offer, by name. An
 * adapter is registered as the function that builds it from its options, so a
 * config can choose it by name and configure it. Extensions only register; the
 * command line looks up what they registered.
 */
final readonly class Extensions
{
    /**
     * @param Entries<Closure(Options): (Runner|Invalid)> $runners
     * @param Entries<Closure(Options): (TreeSource|Invalid)> $treeSources
     * @param Entries<Closure(Options): (CostModel|Invalid)> $costModels
     * @param Entries<Closure(Options): (ProofStore|Invalid)> $proofStores
     * @param Entries<Closure(Options): (CiPlan|Invalid)> $ciPlans
     * @param Entries<Closure(Options): (Reporter|Invalid)> $reporters
     * @param Entries<Closure(Options): (ChangeSource|Invalid)> $changeSources
     * @param Entries<Closure(Options): (Repository|Invalid)> $repositories
     * @param Entries<Closure(Options): (ConfigLoader|Invalid)> $configLoaders
     * @param Entries<Closure(Options): (StaticChecker|Invalid)> $staticCheckers
     * @param Entries<Layer> $presets
     * @param Entries<MutatorSet> $mutatorSets
     * @param Withheld $ciWithheld what the CI plans registered here declare no runner may see, whether or not a
     *                             config lets them build
     * @param Detections $ciDetections how each CI plan registered here tells a job runs in its CI
     */
    private function __construct(
        private Origin $origin,
        private Entries $runners,
        private Entries $treeSources,
        private Entries $costModels,
        private Entries $proofStores,
        private Entries $ciPlans,
        private Entries $reporters,
        private Entries $changeSources,
        private Entries $repositories,
        private Entries $configLoaders,
        private Entries $staticCheckers,
        private Entries $presets,
        private Entries $mutatorSets,
        private Withheld $ciWithheld,
        private Detections $ciDetections,
    ) {
    }

    /** An empty registry, whose additions come from this package. */
    public static function of(Origin $origin): self
    {
        return new self(
            $origin,
            Entries::none(ExtensionPoint::Runner),
            Entries::none(ExtensionPoint::TreeSource),
            Entries::none(ExtensionPoint::CostModel),
            Entries::none(ExtensionPoint::ProofStore),
            Entries::none(ExtensionPoint::CiPlan),
            Entries::none(ExtensionPoint::Reporter),
            Entries::none(ExtensionPoint::ChangeSource),
            Entries::none(ExtensionPoint::Repository),
            Entries::none(ExtensionPoint::ConfigLoader),
            Entries::none(ExtensionPoint::StaticChecker),
            Entries::none(ExtensionPoint::Preset),
            Entries::none(ExtensionPoint::MutatorSet),
            Withheld::nothing(),
            Detections::none(),
        );
    }

    /** @param Closure(Options): (Runner|Invalid) $build */
    public function withRunner(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners->with(Entry::of($name, $this->origin, $build)),
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /** @param Closure(Options): (TreeSource|Invalid) $build */
    public function withTreeSource(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources->with(Entry::of($name, $this->origin, $build)),
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /** @param Closure(Options): (CostModel|Invalid) $build */
    public function withCostModel(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels->with(Entry::of($name, $this->origin, $build)),
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /** @param Closure(Options): (ProofStore|Invalid) $build */
    public function withProofStore(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores->with(Entry::of($name, $this->origin, $build)),
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /**
     * A CI plan, with the credentials of its CI that no process running the project's code may see, declared
     * apart from its options, so a config that stops it building never stops them being withheld (ADR-0004);
     * and the variable its CI marks every job with, so a config that names no plan takes the one the job runs in.
     *
     * @param Closure(Options): (CiPlan|Invalid) $build
     */
    public function withCiPlan(Name $name, Closure $build, Withheld $withheld, CiMarker $marker): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans->with(Entry::of($name, $this->origin, $build)),
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld->and($withheld),
            $this->ciDetections->with($name, $this->origin, $marker),
        );
    }

    /** @param Closure(Options): (Reporter|Invalid) $build */
    public function withReporter(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters->with(Entry::of($name, $this->origin, $build)),
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /** @param Closure(Options): (ChangeSource|Invalid) $build */
    public function withChangeSource(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources->with(Entry::of($name, $this->origin, $build)),
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /** @param Closure(Options): (Repository|Invalid) $build */
    public function withRepository(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories->with(Entry::of($name, $this->origin, $build)),
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /** @param Closure(Options): (ConfigLoader|Invalid) $build */
    public function withConfigLoader(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders->with(Entry::of($name, $this->origin, $build)),
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /** @param Closure(Options): (StaticChecker|Invalid) $build */
    public function withStaticChecker(Name $name, Closure $build): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers->with(Entry::of($name, $this->origin, $build)),
            $this->presets,
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /** A config fragment with a name, applied before the config file so the project's own settings win. */
    public function withPreset(Name $name, Layer $fragment): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets->with(Entry::of($name, $this->origin, $fragment)),
            $this->mutatorSets,
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /**
     * A set of mutators, which a config turns on by its name in
     * `mutators.sets`; installing it changes nothing by itself (ADR-0021).
     */
    public function withMutators(Name $set, MutatorSet $mutators): self
    {
        return new self(
            $this->origin,
            $this->runners,
            $this->treeSources,
            $this->costModels,
            $this->proofStores,
            $this->ciPlans,
            $this->reporters,
            $this->changeSources,
            $this->repositories,
            $this->configLoaders,
            $this->staticCheckers,
            $this->presets,
            $this->mutatorSets->with(Entry::of($set, $this->origin, $mutators)),
            $this->ciWithheld,
            $this->ciDetections,
        );
    }

    /**
     * This registry and another's. Two packages that register the same name
     * at the same extension point cannot both be meant, so that is refused,
     * naming both.
     */
    public function merge(self $other): self|CannotJudge
    {
        $conflicts = [
            ...$this->runners->conflictsWith($other->runners),
            ...$this->treeSources->conflictsWith($other->treeSources),
            ...$this->costModels->conflictsWith($other->costModels),
            ...$this->proofStores->conflictsWith($other->proofStores),
            ...$this->ciPlans->conflictsWith($other->ciPlans),
            ...$this->reporters->conflictsWith($other->reporters),
            ...$this->changeSources->conflictsWith($other->changeSources),
            ...$this->repositories->conflictsWith($other->repositories),
            ...$this->configLoaders->conflictsWith($other->configLoaders),
            ...$this->staticCheckers->conflictsWith($other->staticCheckers),
            ...$this->presets->conflictsWith($other->presets),
            ...$this->mutatorSets->conflictsWith($other->mutatorSets),
        ];

        if ($conflicts !== []) {
            return CannotJudge::because(sprintf(
                '%s Remove one of the packages, or run with --no-extensions.',
                implode(' ', $conflicts),
            ));
        }

        return new self(
            $this->origin,
            $this->runners->merge($other->runners),
            $this->treeSources->merge($other->treeSources),
            $this->costModels->merge($other->costModels),
            $this->proofStores->merge($other->proofStores),
            $this->ciPlans->merge($other->ciPlans),
            $this->reporters->merge($other->reporters),
            $this->changeSources->merge($other->changeSources),
            $this->repositories->merge($other->repositories),
            $this->configLoaders->merge($other->configLoaders),
            $this->staticCheckers->merge($other->staticCheckers),
            $this->presets->merge($other->presets),
            $this->mutatorSets->merge($other->mutatorSets),
            $this->ciWithheld->and($other->ciWithheld),
            $this->ciDetections->merge($other->ciDetections),
        );
    }

    /**
     * What every CI plan registered here declares withheld, whichever plan a run takes.
     *
     * @internal extensions register; only the command line looks up
     */
    public function ciWithheld(): Withheld
    {
        return $this->ciWithheld;
    }

    /**
     * The CI plan of the CI the job runs in, by the markers the plans
     * registered here declare: this package's own first, another package's
     * only where none of this package's is shown; none where none is.
     *
     * @internal extensions register; only the command line looks up
     *
     * @param Closure(CiMarker): bool $shows whether the job's environment shows a marker
     */
    public function detectedCiPlan(Closure $shows): Name|NotGiven
    {
        return $this->ciDetections->detected($shows, $this->origin);
    }

    /**
     * What is registered at this extension point under this name: the function that
     * builds an adapter from its options, a preset's fragment, or a set of mutators.
     *
     * @internal extensions register; only the command line looks up
     */
    public function registered(ExtensionPoint $point, Name $name): Closure|Layer|MutatorSet|CannotJudge
    {
        return $this->entries($point)->find($name);
    }

    /**
     * The name of everything registered at this extension point, in the order it was registered.
     *
     * @internal extensions register; only the command line looks up
     *
     * @return Listed<Name>
     */
    public function names(ExtensionPoint $point): Listed
    {
        return $this->entries($point)->names();
    }

    /** @return Entries<Closure|Layer|MutatorSet> */
    private function entries(ExtensionPoint $point): Entries
    {
        return match ($point) {
            ExtensionPoint::Runner => $this->runners,
            ExtensionPoint::TreeSource => $this->treeSources,
            ExtensionPoint::CostModel => $this->costModels,
            ExtensionPoint::ProofStore => $this->proofStores,
            ExtensionPoint::CiPlan => $this->ciPlans,
            ExtensionPoint::Reporter => $this->reporters,
            ExtensionPoint::ChangeSource => $this->changeSources,
            ExtensionPoint::Repository => $this->repositories,
            ExtensionPoint::ConfigLoader => $this->configLoaders,
            ExtensionPoint::StaticChecker => $this->staticCheckers,
            ExtensionPoint::Preset => $this->presets,
            ExtensionPoint::MutatorSet => $this->mutatorSets,
        };
    }
}

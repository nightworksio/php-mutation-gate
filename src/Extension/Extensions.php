<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use Closure;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Registry\Entries;
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
 * Every adapter and preset the extensions offer, by name. An adapter is
 * registered as the function that builds it from its options, so a config
 * can choose it by name and configure it. Extensions only register; the
 * command line looks up what they registered.
 */
final readonly class Extensions
{
    /** @var Entries<Closure(Options): (Runner|Invalid)> */
    private Entries $runners;

    /** @var Entries<Closure(Options): (TreeSource|Invalid)> */
    private Entries $treeSources;

    /** @var Entries<Closure(Options): (CostModel|Invalid)> */
    private Entries $costModels;

    /** @var Entries<Closure(Options): (ProofStore|Invalid)> */
    private Entries $proofStores;

    /** @var Entries<Closure(Options): (CiPlan|Invalid)> */
    private Entries $ciPlans;

    /** @var Entries<Closure(Options): (Reporter|Invalid)> */
    private Entries $reporters;

    /** @var Entries<Closure(Options): (ChangeSource|Invalid)> */
    private Entries $changeSources;

    /** @var Entries<Closure(Options): (Repository|Invalid)> */
    private Entries $repositories;

    /** @var Entries<Closure(Options): (ConfigLoader|Invalid)> */
    private Entries $configLoaders;

    /** @var Entries<Document> */
    private Entries $presets;

    /** An empty registry, whose additions come from this package. */
    public function __construct(private Origin $origin)
    {
        $this->runners = new Entries(Kind::Runner->value);
        $this->treeSources = new Entries(Kind::TreeSource->value);
        $this->costModels = new Entries(Kind::CostModel->value);
        $this->proofStores = new Entries(Kind::ProofStore->value);
        $this->ciPlans = new Entries(Kind::CiPlan->value);
        $this->reporters = new Entries(Kind::Reporter->value);
        $this->changeSources = new Entries(Kind::ChangeSource->value);
        $this->repositories = new Entries(Kind::Repository->value);
        $this->configLoaders = new Entries(Kind::ConfigLoader->value);
        $this->presets = new Entries(Kind::Preset->value);
    }

    /** @param Closure(Options): (Runner|Invalid) $build */
    public function withRunner(Name $name, Closure $build): self
    {
        return clone($this, [
            'runners' => $this->runners->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** @param Closure(Options): (TreeSource|Invalid) $build */
    public function withTreeSource(Name $name, Closure $build): self
    {
        return clone($this, [
            'treeSources' => $this->treeSources->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** @param Closure(Options): (CostModel|Invalid) $build */
    public function withCostModel(Name $name, Closure $build): self
    {
        return clone($this, [
            'costModels' => $this->costModels->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** @param Closure(Options): (ProofStore|Invalid) $build */
    public function withProofStore(Name $name, Closure $build): self
    {
        return clone($this, [
            'proofStores' => $this->proofStores->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** @param Closure(Options): (CiPlan|Invalid) $build */
    public function withCiPlan(Name $name, Closure $build): self
    {
        return clone($this, [
            'ciPlans' => $this->ciPlans->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** @param Closure(Options): (Reporter|Invalid) $build */
    public function withReporter(Name $name, Closure $build): self
    {
        return clone($this, [
            'reporters' => $this->reporters->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** @param Closure(Options): (ChangeSource|Invalid) $build */
    public function withChangeSource(Name $name, Closure $build): self
    {
        return clone($this, [
            'changeSources' => $this->changeSources->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** @param Closure(Options): (Repository|Invalid) $build */
    public function withRepository(Name $name, Closure $build): self
    {
        return clone($this, [
            'repositories' => $this->repositories->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** @param Closure(Options): (ConfigLoader|Invalid) $build */
    public function withConfigLoader(Name $name, Closure $build): self
    {
        return clone($this, [
            'configLoaders' => $this->configLoaders->with($name->value(), $this->origin->name(), $build),
        ]);
    }

    /** A config fragment with a name, applied before the config file so the project's own settings win. */
    public function withPreset(Name $name, Document $fragment): self
    {
        return clone($this, [
            'presets' => $this->presets->with($name->value(), $this->origin->name(), $fragment),
        ]);
    }

    /**
     * This registry and another's. Two packages that register the same name
     * for the same kind of thing cannot both be meant, so that is refused,
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
            ...$this->presets->conflictsWith($other->presets),
        ];

        if ($conflicts !== []) {
            return CannotJudge::because(sprintf(
                '%s Remove one of the packages, or run with --no-extensions.',
                implode(' ', $conflicts),
            ));
        }

        return clone($this, [
            'runners' => $this->runners->merge($other->runners),
            'treeSources' => $this->treeSources->merge($other->treeSources),
            'costModels' => $this->costModels->merge($other->costModels),
            'proofStores' => $this->proofStores->merge($other->proofStores),
            'ciPlans' => $this->ciPlans->merge($other->ciPlans),
            'reporters' => $this->reporters->merge($other->reporters),
            'changeSources' => $this->changeSources->merge($other->changeSources),
            'repositories' => $this->repositories->merge($other->repositories),
            'configLoaders' => $this->configLoaders->merge($other->configLoaders),
            'presets' => $this->presets->merge($other->presets),
        ]);
    }

    /**
     * What is registered as this kind under this name: the function that
     * builds an adapter from its options, or a preset's fragment.
     *
     * @internal extensions register; only the command line looks up
     */
    public function registered(Kind $kind, Name $name): Closure|Document|CannotJudge
    {
        $entries = match ($kind) {
            Kind::Runner => $this->runners,
            Kind::TreeSource => $this->treeSources,
            Kind::CostModel => $this->costModels,
            Kind::ProofStore => $this->proofStores,
            Kind::CiPlan => $this->ciPlans,
            Kind::Reporter => $this->reporters,
            Kind::ChangeSource => $this->changeSources,
            Kind::Repository => $this->repositories,
            Kind::ConfigLoader => $this->configLoaders,
            Kind::Preset => $this->presets,
        };

        return $entries->find($name->value());
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\PestWorkers;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MultipleVariable;
use NightWorksIO\MutationGate\Core\Runner\PerTestVariables;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\StartUpVariable;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * Whether the project applies `pest:patch`, the canary group a shard's
 * opening run is when it does, which tests a mutant's own run selects,
 * whether the verification tail's workers boot the project (ADR-0075), and
 * how its warm workers compile, serve and boot mutants' runs (ADR-0088,
 * ADR-0091).
 *
 * @internal
 */
final readonly class Patching
{
    private function __construct(
        private Group|Unpatched $canary,
        private StreamSettings $streams,
        private PestSteps $steps,
        private PestWorkers $workers,
    ) {
    }

    public static function off(): self
    {
        return new self(
            Unpatched::Vendor,
            StreamSettings::off(),
            PestSteps::standard(),
            PestWorkers::of(bootTail: false, workerOpcache: true, schemata: true, bootMutants: true),
        );
    }

    public static function on(Group $canary): self
    {
        return new self($canary, StreamSettings::off(), PestSteps::standard(), self::off()->workers);
    }

    public function isOn(): bool
    {
        return $this->canary instanceof Group;
    }

    /**
     * The same, its warm workers booting, compiling and serving as these say where it applies the patch, each
     * where they set it: `pest.bootTail`, `pest.workerOpcache`, `pest.schemata` and `pest.bootMutants`.
     */
    public function working(PestWorkers $workers): self
    {
        return new self($this->canary, $this->streams, $this->steps, $this->workers->over($workers));
    }

    /** The same, its mutants judged in split streams as `splitStream` says, where it compiles schemata (ADR-0117). */
    public function splitting(StreamSettings $streams): self
    {
        return new self($this->canary, $streams, $this->steps, $this->workers);
    }

    /** The same, taking these steps (ADR-0138, ADR-0142). */
    public function stepping(PestSteps $steps): self
    {
        return new self($this->canary, $this->streams, $steps, $this->workers);
    }

    /** The steps a run takes, and what they say of narrowed kills, long filters and preloaded files (ADR-0142). */
    public function steps(): PestSteps
    {
        return $this->steps;
    }

    /**
     * What a run is started with to compile each file's mutants into a schema
     * (see Schemata), and to judge them in split streams where `splitStream`
     * asks it to (ADR-0117); nothing where the project does not apply the
     * patch, or `pest.schemata` is off.
     *
     * @return array<string, string>
     */
    public function schemata(): array
    {
        return $this->isOn() && $this->workers->schemata()
            ? [GateVariable::Schemata->value => '1', ...$this->streams->variables()]
            : [];
    }

    /**
     * What a run is started with, where it applies the patch: so a mutant's covering tests too many for a filter
     * are selected by their ids under `pest.longFilter: set` (ADR-0138), and so every mutant is judged by its own
     * run under `mutants.duplicates: judge-each` (ADR-0137); nothing otherwise.
     *
     * @return array<string, string>
     */
    public function stepVariables(): array
    {
        return $this->isOn() ? [
            ...$this->steps->longFilter() === LongFilter::Set
                ? [GateVariable::LongFilter->value => LongFilter::Set->value]
                : [],
            ...$this->steps->duplicates() === Duplicates::JudgeEach
                ? [GateVariable::Duplicates->value => Duplicates::JudgeEach->value]
                : [],
        ] : [];
    }

    /** Whether a run judges its mutants in split streams: where it compiles schemata and `splitStream` asks it to. */
    public function splitsStreams(): bool
    {
        return $this->isOn() && $this->workers->schemata() && $this->streams->isOn();
    }

    /** Whether the warm workers compile through opcache, keeping out each file the run mutates and copy it serves. */
    public function cachesInWorkers(): bool
    {
        return $this->isOn() && $this->workers->workerOpcache();
    }

    /** Whether the verification tail's workers boot the project before they fork its runs of every test file. */
    public function bootsTail(): bool
    {
        return $this->isOn() && $this->workers->bootTail();
    }

    /** Whether the workers of mutants' own runs boot the project, no test file loaded, before they fork. */
    public function bootsMutants(): bool
    {
        return $this->isOn() && $this->workers->bootMutants();
    }

    /**
     * Whether a shard's chunks keep their mutants' warm workers for the next
     * chunk: where `pest.bootChunks` asks and those workers boot nothing of
     * the project and compile without opcache, so a kept worker is in the
     * state a fresh one starts in.
     */
    public function keepsChunkWorkers(): bool
    {
        return $this->isOn()
            && $this->steps->bootsChunks()
            && ! $this->workers->bootMutants()
            && ! $this->workers->workerOpcache();
    }

    /**
     * What a run is started with to keep each mutant's limit, and its silence
     * limit, within these bounds, where the project applies the patch (see
     * MutantTime); nothing where it does not, as Pest allows each mutant its
     * own limit then.
     *
     * @return array<string, string>
     */
    public function bounding(LimitBounds $bounds): array
    {
        return $this->isOn()
            ? [
                GateVariable::MutantFloor->value => sprintf('%F', $bounds->floor()->seconds()),
                GateVariable::MutantCap->value => sprintf('%F', $bounds->most()->seconds()),
                ...TighterVariables::of($bounds->tighter()),
                ...StartUpVariable::of($bounds),
                ...MultipleVariable::of($bounds),
                ...PerTestVariables::of($bounds->perTest()),
            ]
            : [];
    }

    /**
     * What a mutant's own run selects of the tests that cover it: exactly
     * those, a row of a data set alone, a class or a data set the suite holds
     * no other test of named whole, where the project applies the patch,
     * and every row of their data sets, as pest-plugin-mutate's filter
     * selects them, where it does not.
     */
    public function selecting(TestIds $covering, Suite $suite): Selection
    {
        return $this->isOn() ? Selection::exactly($covering, $suite) : Selection::of($covering);
    }

    /** Which tests of a map a mutant's own run runs, and how, as `selecting` selects them; these files hold them. */
    public function selection(TestFiles $files): RunSelection
    {
        return $this->isOn() ? new ExactRows($files) : new EveryRow();
    }

    /** The canary group, where the project applies the patch. */
    public function canary(): Group|Unpatched
    {
        return $this->canary;
    }

    /**
     * The tests a mutation run opens on: the canary group, where the run
     * opens with a map another job handed over and the project applies the
     * patch, or else the tests that judge it.
     */
    public function opensOn(WholeSuite|Group|Filter $judgedBy, bool $handedAMap): WholeSuite|Group|Filter
    {
        return $handedAMap && $this->canary instanceof Group ? $this->canary : $judgedBy;
    }
}

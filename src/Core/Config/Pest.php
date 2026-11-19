<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Test\Group;

use function sprintf;

/**
 * The optional Pest patches, the tests that show they work (ADR-0004),
 * whether the verification tail's workers boot the project (ADR-0075),
 * how Pest's warm workers compile, serve and boot each mutant's run
 * (ADR-0088, ADR-0091), and what becomes of a mutant whose file its own run
 * loaded first (ADR-0148): `pest`; and whether a patched run judges its
 * mutants in split streams (ADR-0117): `splitStream`.
 */
final readonly class Pest implements Part
{
    /** The key and option that say what a kill in a narrowed run needs (ADR-0142). */
    public const string NARROWED_KILLS = 'narrowedKills';

    /** The key and option that say what a run selects where its filter cannot be passed (ADR-0138). */
    public const string LONG_FILTER = 'longFilter';

    /** The key and option that say what becomes of a mutant whose file its own run loaded first (ADR-0148). */
    public const string PRELOADED = 'preloaded';

    /** The key and option that say whether a mutant is served to the PHP processes its tests start (ADR-0150). */
    public const string CHILD_PROCESSES = 'childProcesses';

    private const string CANARY = 'mutation-canary';

    private function __construct(
        private bool|Absent $patch,
        private Group|Absent $canary,
        private PestWorkers $workers,
        private SplitStream $splitStream,
        private NarrowedKillTrust|Absent $narrowedKills,
        private LongFilter|Absent $longFilter,
        private Preloaded|Absent $preloaded,
        private ChildProcesses|Absent $childProcesses,
        private PcovKept $pcovKept,
    ) {
    }

    /** @param Listed<Glob>|Absent $pcovKept */
    public static function of(
        bool|Absent $patch = new Absent(),
        Group|Absent $canary = new Absent(),
        bool|Absent $bootTail = new Absent(),
        bool|Absent $workerOpcache = new Absent(),
        bool|Absent $schemata = new Absent(),
        bool|Absent $bootMutants = new Absent(),
        NarrowedKillTrust|Absent $narrowedKills = new Absent(),
        LongFilter|Absent $longFilter = new Absent(),
        bool|Absent $bootChunks = new Absent(),
        Preloaded|Absent $preloaded = new Absent(),
        ChildProcesses|Absent $childProcesses = new Absent(),
        Listed|Absent $pcovKept = new Absent(),
    ): self {
        return new self(
            $patch,
            $canary,
            PestWorkers::of($bootTail, $workerOpcache, $schemata, $bootMutants, $bootChunks),
            SplitStream::of(),
            $narrowedKills,
            $longFilter,
            $preloaded,
            $childProcesses,
            PcovKept::of($pcovKept),
        );
    }

    /** What a layer sets of `splitStream`, and of no other Pest setting. */
    public static function splitting(SplitStream $splitStream): self
    {
        $none = new Absent();

        return new self($none, $none, PestWorkers::of(), $splitStream, $none, $none, $none, $none, PcovKept::of());
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();
        $workers = $none->workers();

        return self::of(
            $none->patch(),
            $none->canary(),
            $workers->bootTail(),
            $workers->workerOpcache(),
            $workers->schemata(),
            $workers->bootMutants(),
            $none->narrowedKills(),
            $none->longFilter(),
            $workers->bootChunks(),
            $none->preloaded(),
            $none->childProcesses(),
        )->over(self::splitting(SplitStream::standard()));
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->patch, $later->patch),
                Absent::laid($this->canary, $later->canary),
                $this->workers->over($later->workers),
                $this->splitStream->over($later->splitStream),
                Absent::laid($this->narrowedKills, $later->narrowedKills),
                Absent::laid($this->longFilter, $later->longFilter),
                Absent::laid($this->preloaded, $later->preloaded),
                Absent::laid($this->childProcesses, $later->childProcesses),
                $this->pcovKept->over($later->pcovKept),
            )
            : $this;
    }

    /** `pest.narrowedKills`: what a kill in a narrowed run needs before it counts; `vouch` unless a layer says. */
    public function narrowedKills(): NarrowedKillTrust
    {
        return $this->narrowedKills instanceof NarrowedKillTrust ? $this->narrowedKills : NarrowedKillTrust::Vouch;
    }

    /** `pest.longFilter`: what a run selects where its filter cannot be passed; `set` unless a layer says. */
    public function longFilter(): LongFilter
    {
        return $this->longFilter instanceof LongFilter ? $this->longFilter : LongFilter::DEFAULT;
    }

    /**
     * `pest.preloaded`: what becomes of a mutant whose own run had loaded its file before the mutant was in place;
     * `fresh` unless a layer says (ADR-0148).
     */
    public function preloaded(): Preloaded
    {
        return $this->preloaded instanceof Preloaded ? $this->preloaded : Preloaded::Fresh;
    }

    /**
     * `pest.childProcesses`: whether a mutant is served to the PHP processes its tests start; `serve` unless a layer
     * says (ADR-0150).
     */
    public function childProcesses(): ChildProcesses
    {
        return $this->childProcesses instanceof ChildProcesses ? $this->childProcesses : ChildProcesses::Serve;
    }

    /** Whether the optional Pest patches are applied. */
    public function patch(): bool
    {
        return ! $this->patch instanceof Absent && $this->patch;
    }

    /** How Pest's warm workers boot, compile and serve each run. */
    public function workers(): PestWorkers
    {
        return $this->workers;
    }

    /** `splitStream`: whether, and how, a patched run judges its mutants in split streams (ADR-0117). */
    public function splitStream(): SplitStream
    {
        return $this->splitStream;
    }

    /**
     * Whether a run under this runner judges its mutants in split streams:
     * the Pest runner, patched and compiling schemata, where
     * `splitStream.enabled` says so (ADR-0117).
     */
    public function splits(Choice $runner): bool
    {
        return $runner->use()->value() === BuiltinRunner::Pest->value
            && $this->patch()
            && $this->workers->schemata()
            && $this->splitStream->enabled();
    }

    /**
     * Whether a run under this runner splits its streams and cuts its test
     * files across its shards, as `splitStream.sharding: tests` asks
     * (ADR-0117).
     */
    public function splitsByTests(Choice $runner): bool
    {
        return $this->splits($runner) && $this->splitStream->sharding()->cutsTests();
    }

    /** The group of tests that shows the patches work. */
    public function canary(): Group
    {
        return $this->canary instanceof Group ? $this->canary : Group::named(self::CANARY);
    }

    /**
     * The runner a config chooses, with these settings beneath the options
     * it gives the Pest runner, so `pest.patch` reaches the runner it is
     * for; any other runner as it is.
     */
    public function beneath(Choice $runner): Choice
    {
        $options = Json::object(Member::of('patch', $this->patch()), Member::of('canary', $this->canary()->name()))
            ->merged($this->workers->options())
            ->with(
                Member::of(self::NARROWED_KILLS, $this->narrowedKills()->value),
                Member::of(self::LONG_FILTER, $this->longFilter()->value),
                Member::of(self::PRELOADED, $this->preloaded()->value),
                Member::of(self::CHILD_PROCESSES, $this->childProcesses()->value),
                $this->pcovKept->option(),
                $this->splitStream->option(),
            );

        return $runner->use()->value() === BuiltinRunner::Pest->value
            ? Choice::of($runner->use()->value(), $runner->options()->over($options))
            : $runner;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::unlessEmpty(
                'pest',
                Json::object(
                    Member::of('patch', $this->patch),
                    Member::of('canary', $this->canary instanceof Group ? $this->canary->name() : $this->canary),
                )->merged($this->workers->written())->with(
                    Member::of(
                        self::NARROWED_KILLS,
                        $this->narrowedKills instanceof NarrowedKillTrust
                            ? $this->narrowedKills->value
                            : $this->narrowedKills,
                    ),
                    Member::of(
                        self::LONG_FILTER,
                        $this->longFilter instanceof LongFilter ? $this->longFilter->value : $this->longFilter,
                    ),
                    Member::of(
                        self::PRELOADED,
                        $this->preloaded instanceof Preloaded ? $this->preloaded->value : $this->preloaded,
                    ),
                    Member::of(
                        self::CHILD_PROCESSES,
                        $this->childProcesses instanceof ChildProcesses
                            ? $this->childProcesses->value
                            : $this->childProcesses,
                    ),
                    $this->pcovKept->written($origin),
                ),
            ),
            $this->splitStream->written(),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::flag($this->patch, 'Pest::patched()', 'Pest::unpatched()')
            ->and(PhpCalls::inWith(...$this->canary instanceof Group
                ? [sprintf('Pest::canary(%s)', PhpCalls::literal($this->canary->name()))]
                : []))
            ->and($this->workers->php())
            ->and(PhpCalls::inWith(...[
                ...$this->narrowedKills instanceof NarrowedKillTrust ? [$this->narrowedKills->php()] : [],
                ...$this->longFilter instanceof LongFilter ? [$this->longFilter->php()] : [],
                ...$this->preloaded instanceof Preloaded ? [$this->preloaded->php()] : [],
                ...$this->childProcesses instanceof ChildProcesses ? [$this->childProcesses->php()] : [],
            ]))
            ->and($this->pcovKept->php($origin))
            ->and($this->splitStream->php());
    }
}

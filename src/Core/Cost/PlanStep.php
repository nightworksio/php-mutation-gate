<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/**
 * One kind of work the plan's time goes to, as it prints and records it
 * (ADR-0220): each runs one after another in the plan's own process, while
 * the work it begins in a child of its own, the analyser's warm-up and the
 * whole map's writing, runs beside the steps that follow.
 */
enum PlanStep: string
{
    /** Composing the run from its options: the config, its layers and the adapters they choose. */
    case Composing = 'composing';

    /** Listing the trees' units and the inputs every unit's key reads. */
    case Inventory = 'inventory';

    /** Reading the ledgers of the run's scope and the default branch. */
    case Ledgers = 'ledgers';

    /** Checking whether nothing the last passed run judged has changed, which needs no map. */
    case Unchanged = 'unchanged';

    /** Deciding whether a full split-stream run starts early against the map it keeps (ADR-0135). */
    case Early = 'early start';

    /** Reading each suite's coverage the plan keeps, by suite. */
    case Suites = 'suites';

    /** Reading the names every file declares and uses. */
    case NameGraph = 'name graph';

    /** Keying each test file's entry of the kept map. */
    case Entries = 'entries';

    /** Readying the run's coverage: the map another job handed over, read, or the tests run under coverage. */
    case Coverage = 'coverage';

    /** Building the coverage map the plan cuts by, from what the coverage step readied. */
    case Map = 'map';

    /** Holding the map within its memory cap. */
    case Cap = 'cap';

    /** Building what each unit's key reads, the limits the ledgers learned among them. */
    case Keying = 'keying';

    /** Working out what the change reaches since its base. */
    case Reach = 'reach';

    /** Digesting every unit's inputs. */
    case Digests = 'unit digests';

    /** Weighing each unit against the ledgers: carried, considered or to run. */
    case Considering = 'considering';

    /** Building each considered unit's key. */
    case Keys = 'unit keys';

    /** Pulling and reading the packs of proofs the cache keeps (ADR-0131). */
    case Packs = 'packs';

    /** Taking the proofs the ledgers and the packs hold for each key. */
    case Proving = 'proving';

    /** Reading the lines the change added or modified. */
    case NewCode = 'new code lines';

    /** Drawing the sample, where the run samples. */
    case Sampling = 'sampling';

    /** Pruning the units the ledgers show need no run. */
    case Pruning = 'pruning';

    /** Measuring the first run of the units no run has timed yet. */
    case FirstRun = 'first run';

    /** Cutting the units to run into shards. */
    case Cutting = 'cutting';

    /** Saying why the plan reaches what it does, and what stands without a run, for its briefing. */
    case Briefing = 'briefing';

    /** Running each held unit's holding tests alone under coverage, for the plan's briefing (ADR-0161). */
    case HeldCoverage = 'held coverage';

    /** Naming the map's tests as the runner names them, and what the plan considered. */
    case Naming = 'naming';

    /**
     * Beginning the analyser's warm-up in a child of the plan's, beside the
     * steps that follow, where the plan did not begin it as it read its map
     * (ADR-0180).
     */
    case WarmUpBegun = 'warm-up begun';

    /** Beginning the whole map's writing in a child of the plan's, beside the steps that follow (ADR-0169). */
    case WholeMapBegun = 'whole map begun';

    /** Readying the map every shard opens on (ADR-0163). */
    case Opening = 'opening';

    /** Writing each shard's map and the kill history it is handed. */
    case Handoff = 'handoff';

    /** Waiting for the analyser's warm-up to end, and taking what it left (ADR-0164). */
    case WarmUp = 'warm-up';

    /** Measuring a mutant's start-up, once the warm-up is done (ADR-0165). */
    case StartUp = 'start-up timing';

    /** Writing the plan, handing it to the CI and writing the comment in its planned state. */
    case Publishing = 'publishing';
}

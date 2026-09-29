<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * The effective config: every setting of the README's configuration
 * reference, typed, with its default where the config leaves it out, after
 * presets, the config file and the command line (ADR-0002).
 */
final readonly class Settings
{
    /**
     * @param Listed<string> $extensions
     * @param Listed<string> $presets
     * @param Listed<Report> $reports
     */
    private function __construct(
        private Listed $extensions,
        private Listed $presets,
        private Choice $runner,
        private Choice $treeSource,
        private Floors $floors,
        private Reach $reach,
        private Shards $shards,
        private Ci $ci,
        private Proofs $proofs,
        private Seconds|Unlimited $budget,
        private Triage $triage,
        private Ignores $ignores,
        private Listed $reports,
        private Table $badge,
        private Pest $pest,
        private Local $local,
        private string $effective,
        private string $canonical,
    ) {
    }

    /**
     * The settings a config was read into, with the effective config as JSON and the canonical form of the
     * settings that affect results.
     */
    public static function from(Fields $read, string $effective, string $canonical): self
    {
        $budget = $read->optional('budget', Seconds::class);

        return new self(
            Listed::of($read->strings('extensions')),
            Listed::of($read->strings('preset')),
            $read->object('runner', Choice::class),
            $read->object('treeSource', Choice::class),
            self::floorsFrom($read),
            new Reach(
                Listed::of($read->strings('packages')),
                Listed::of($read->fields('reach')->strings('everything')),
                $read->fields('holds')->float('hotPath'),
            ),
            new Shards(
                Seconds::of($read->fields('shards')->int('seconds')),
                $read->fields('shards')->int('max'),
                $read->fields('costs')->object('secondsPerLine', Table::class),
            ),
            new Ci(
                $read->fields('ci')->optional('plan', Choice::class),
                $read->fields('ci')->has('defaultBranch')
                    ? $read->fields('ci')->string('defaultBranch')
                    : Absent::setting(),
                $read->fields('ci')->fields('gitlab')->object('template', Path::class),
                $read->fields('ci')->fields('buildkite')->string('step'),
            ),
            new Proofs(
                $read->fields('proofs')->object('store', Choice::class),
                Listed::of($read->fields('proofs')->strings('ignore')),
                $read->fields('proofs')->object('write', ProofWriting::class),
            ),
            $budget instanceof Absent ? Unlimited::time() : $budget,
            new Triage(
                $read->fields('timeouts')->object('mode', TimeoutMode::class),
                Seconds::of($read->fields('timeouts')->int('seconds')),
                $read->fields('timeouts')->int('retries'),
                $read->fields('flaky')->bool('confirmSurvivors'),
            ),
            new Ignores(
                Listed::of($read->fields('ignores')->objects('entries', Ignored::class)),
                $read->fields('ignores')->has('maxDays') ? $read->fields('ignores')->int('maxDays') : Absent::setting(),
                $read->fields('ignores')->object('native', NativeMarkers::class),
            ),
            Listed::of($read->objects('reports', Report::class)),
            $read->fields('badge')->object('colors', Table::class),
            new Pest($read->fields('pest')->bool('patch'), Group::named($read->fields('pest')->string('canary'))),
            new Local(
                $read->fields('local')->object('watchBudget', Seconds::class),
                $read->fields('local')->object('prePushBudget', Seconds::class),
            ),
            $effective,
            $canonical,
        );
    }

    /** @return Listed<string> the extension classes the config loads, beside those Composer names */
    public function extensions(): Listed
    {
        return $this->extensions;
    }

    /** @return Listed<string> the presets applied, in order */
    public function presets(): Listed
    {
        return $this->presets;
    }

    public function runner(): Choice
    {
        return $this->runner;
    }

    public function treeSource(): Choice
    {
        return $this->treeSource;
    }

    public function floors(): Floors
    {
        return $this->floors;
    }

    public function reach(): Reach
    {
        return $this->reach;
    }

    public function shards(): Shards
    {
        return $this->shards;
    }

    public function ci(): Ci
    {
        return $this->ci;
    }

    public function proofs(): Proofs
    {
        return $this->proofs;
    }

    /** How long a run may take, riskiest code first (ADR-0008). */
    public function budget(): Seconds|Unlimited
    {
        return $this->budget;
    }

    public function triage(): Triage
    {
        return $this->triage;
    }

    public function ignores(): Ignores
    {
        return $this->ignores;
    }

    /** @return Listed<Report> the file reports the config asks for */
    public function reports(): Listed
    {
        return $this->reports;
    }

    /** `badge.colors`: the lowest score of each shields.io colour, red below them all. */
    public function badge(): Table
    {
        return $this->badge;
    }

    public function pest(): Pest
    {
        return $this->pest;
    }

    public function local(): Local
    {
        return $this->local;
    }

    /** The effective config, every setting with its value, as pretty JSON that reads back into these settings. */
    public function effective(): string
    {
        return $this->effective;
    }

    /**
     * The settings that affect results, serialised canonically: one line of JSON with every object's keys in
     * byte order. It is the configuration part of a proof's key (ADR-0007), so it holds nothing that only
     * judges or reports.
     */
    public function canonical(): string
    {
        return $this->canonical;
    }

    private static function floorsFrom(Fields $read): Floors
    {
        return new Floors(
            $read->has('trees') ? Listed::of($read->objects('trees', DeclaredTree::class)) : Absent::setting(),
            $read->fields('newCode')->object('floor', Floor::class),
            $read->object('uncovered', UncoveredMutants::class),
            $read->fields('baseline')->object('path', Path::class),
            $read->fields('baseline')->object('improvement', Improvement::class),
        );
    }
}

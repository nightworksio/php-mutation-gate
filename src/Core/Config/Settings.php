<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * The effective config (ADR-0002): every layer laid in order, presets, the
 * config file and the command line, over the value each setting takes when
 * every layer leaves it out, and typed. It holds a runner, and no ignore that
 * outlasts `ignores.maxDays`.
 */
final readonly class Settings
{
    /** @param Layer $layer every layer laid over the standard one, so each setting has its value */
    private function __construct(private Layer $layer, private ChosenRunner $runner)
    {
    }

    /** The settings every layer laid over another makes, or every problem that remains once they are laid. */
    public static function settled(Layer $layer, DateTimeImmutable $now): self|Invalid
    {
        $runner = $layer->setup()->runner();
        $late = $layer->ignores()->late($now);
        $problems = [
            ...$runner instanceof Choice
                ? []
                : [Problem::at('runner', sprintf('expected %s, got nothing', Adapter::EXPECTED))],
            ...$late instanceof Invalid ? [...$late] : [],
        ];

        return $runner instanceof Choice && $problems === []
            ? new self(Layer::standard()->over($layer), ChosenRunner::of($runner, $layer->setup()->withhold()))
            : Invalid::because(...$problems);
    }

    /** @return Listed<string> the extension classes the config loads, beside those Composer names */
    public function extensions(): Listed
    {
        return $this->layer->setup()->extensions();
    }

    /** @return Listed<string> the presets applied, in order */
    public function presets(): Listed
    {
        $presets = $this->layer->setup()->presets();

        return $presets instanceof Listed ? $presets : Listed::of();
    }

    /** The runner chosen, and what it withholds from the project's tests besides what every run withholds. */
    public function runner(): ChosenRunner
    {
        return $this->runner;
    }

    public function treeSource(): Choice
    {
        return $this->layer->setup()->treeSource();
    }

    public function floors(): Floors
    {
        return $this->layer->floors();
    }

    public function reach(): Reach
    {
        return $this->layer->reach();
    }

    public function shards(): Shards
    {
        return $this->layer->shards();
    }

    public function ci(): Ci
    {
        return $this->layer->ci();
    }

    public function proofs(): Proofs
    {
        return $this->layer->proofs();
    }

    /** How long a run may take, riskiest code first (ADR-0008). */
    public function budget(): Seconds|Unlimited
    {
        return $this->layer->triage()->budget();
    }

    public function triage(): Triage
    {
        return $this->layer->triage();
    }

    public function ignores(): Ignores
    {
        return $this->layer->ignores();
    }

    /** @return Listed<Report> the reports the config asks for */
    public function reports(): Listed
    {
        return $this->layer->reports()->reports();
    }

    /** `badge.colors`: the lowest score of each shields.io colour, red below them all. */
    public function badge(): Table
    {
        return $this->layer->badge()->colors();
    }

    public function pest(): Pest
    {
        return $this->layer->pest();
    }

    public function local(): Local
    {
        return $this->layer->local();
    }

    /** The effective config: every setting with its value, which reads back into these settings. */
    public function effective(): Layer
    {
        return $this->layer;
    }

    /**
     * The settings that affect results, serialised canonically: one line of JSON with every object's keys in
     * byte order. It is the configuration part of a proof's key (ADR-0007), so it holds nothing that only
     * judges or reports.
     */
    public function canonical(): string
    {
        return Canonical::of($this->layer->written(ProjectRoot::origin()), Definition::effects());
    }
}

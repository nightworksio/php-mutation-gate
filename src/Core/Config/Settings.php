<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;

use function sprintf;

/**
 * The effective config (ADR-0002): every layer laid in order, presets, the
 * config file and the command line, over the value each setting takes when
 * every layer leaves it out, and typed. It holds a runner, no ignore that
 * outlasts `ignores.maxDays`, and no `timeouts.most` under `timeouts.seconds`.
 *
 * @internal
 */
final readonly class Settings
{
    /** @param Layer $layer every layer laid over the standard one, so each setting has its value */
    private function __construct(private Layer $layer, private ChosenRunner $runner)
    {
    }

    /** `extensions`, `preset`, `runner`, `treeSource`, `paths` and `mutators`: what the gate is set up with. */
    public function setup(): Setup
    {
        return $this->layer->setup();
    }

    /** The settings every layer laid over another makes, or every problem that remains once they are laid. */
    public static function settled(Layer $layer, DateTimeImmutable $now): self|Invalid
    {
        $runner = $layer->setup()->runner();
        $late = $layer->ignores()->late($now);
        $settled = Layer::standard()->over(
            $runner instanceof Choice && $layer->pest()->splits($runner)
                ? Layer::of(StaticCheck::of(before: false))->over($layer)
                : $layer,
        );
        $unbounded = $settled->triage()->refusal();
        $paths = $settled->setup()->paths()->unbounded($settled);
        $problems = [
            ...$runner instanceof Choice
                ? []
                : [Problem::at('runner', sprintf('expected %s, got nothing', Adapter::EXPECTED))],
            ...$late instanceof Invalid ? [...$late] : [],
            ...$unbounded instanceof Invalid ? [...$unbounded] : [],
            ...$paths instanceof Invalid ? [...$paths] : [],
        ];

        return $runner instanceof Choice && $problems === []
            ? new self(
                $settled,
                ChosenRunner::of(
                    $settled->mutants()->beneath($settled->toggles()->beneath(
                        $settled->shards()->outbreak()->beneath($settled->pest()->beneath($runner)),
                    )),
                    $layer->setup()->runnerLayer()->withhold(),
                    $settled->setup()->runnerLayer()->memory(),
                    $settled->setup()->runnerLayer()->workers(),
                    $settled->setup()->runnerLayer()->pacing(),
                ),
            )
            : Invalid::because(...$problems);
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

    /** `badge` and `dashboard`: the badge's colours, and which repositories an organisation's dashboard shows. */
    public function publishing(): Publishing
    {
        return $this->layer->publishing();
    }

    /** Which static analyser rejects a mutant before its tests run, and with which config (ADR-0020). */
    public function staticCheck(): StaticCheck
    {
        return $this->layer->staticCheck();
    }

    /** Which steps of a run are on or off (ADR-0112, ADR-0142). */
    public function toggles(): Toggles
    {
        return $this->layer->toggles();
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

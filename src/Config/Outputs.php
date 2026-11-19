<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** What a run writes beside the reports a config lists: `outputs`. */
final readonly class Outputs implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `outputs.verdictOnly`: write neither the reports where the run is nor those the action asks for. */
    public static function verdictOnly(): self
    {
        return new self(Json::at('outputs.verdictOnly', value: true));
    }

    /** `outputs.verdictOnly: false`: write them, the default. */
    public static function everyOutput(): self
    {
        return new self(Json::at('outputs.verdictOnly', value: false));
    }

    /** `outputs.automatic`: the reporters a run writes because of where it is, by name. */
    public static function automatic(string ...$reporters): self
    {
        return new self(Json::at('outputs.automatic', Json::items(...$reporters)));
    }

    /** `outputs.actionReports`: the reports the action asks of the verdict: `json`, `html` and `digest`. */
    public static function actionReports(string ...$reporters): self
    {
        return new self(Json::at('outputs.actionReports', Json::items(...$reporters)));
    }

    /** `outputs.costs`: the reports say what the run cost, the default. */
    public static function withCosts(): self
    {
        return new self(Json::at('outputs.costs', value: true));
    }

    /** `outputs.costs: false`: they do not. */
    public static function withoutCosts(): self
    {
        return new self(Json::at('outputs.costs', value: false));
    }

    /** `outputs.costReport`: a costed run's reports say where its minutes went, the default (ADR-0230). */
    public static function withCostReport(): self
    {
        return new self(Json::at('outputs.costReport', value: true));
    }

    /** `outputs.costReport: false`: they do not. */
    public static function withoutCostReport(): self
    {
        return new self(Json::at('outputs.costReport', value: false));
    }

    /** `outputs.costliest`: how many of its costliest mutants and tests the cost report names, 10 by default. */
    public static function costliest(int $count): self
    {
        return new self(Json::at('outputs.costliest', $count));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** The CI the plan is written for, and what it needs to know about it (ADR-0006, ADR-0007): `ci`. */
final readonly class Ci implements Part
{
    /** The check-run the verdict reports under. */
    private const string CHECK = 'mutation / verdict';

    /** Monday, at three. */
    private const string WEEKLY = '0 3 * * 1';

    private function __construct(
        private Choice|Absent $plan,
        private string|Absent $defaultBranch,
        private string|Absent $check,
        private MergedTrust|Absent $trustMergedPullRequests,
        private OwnScopeTrust|Absent $mergedOwnScopeProofs,
        private Pipelines $pipelines,
        private string|Absent $schedule,
    ) {
    }

    public static function of(
        Choice|Absent $plan = new Absent(),
        string|Absent $defaultBranch = new Absent(),
        string|Absent $check = new Absent(),
        MergedTrust|Absent $trustMergedPullRequests = new Absent(),
        OwnScopeTrust|Absent $mergedOwnScopeProofs = new Absent(),
        Path|Absent $gitlabTemplate = new Absent(),
        BuildkiteStep|Absent $buildkiteStep = new Absent(),
        Path|Absent $buildkiteDefinition = new Absent(),
        Path|Absent $azureDefinition = new Absent(),
        Path|Absent $bitbucketDefinition = new Absent(),
        Path|Absent $jenkinsDefinition = new Absent(),
        string|Absent $schedule = new Absent(),
    ): self {
        return new self(
            $plan,
            $defaultBranch,
            $check,
            $trustMergedPullRequests,
            $mergedOwnScopeProofs,
            Pipelines::of(
                $gitlabTemplate,
                $buildkiteStep,
                $buildkiteDefinition,
                $azureDefinition,
                $bitbucketDefinition,
                $jenkinsDefinition,
            ),
            $schedule,
        );
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();
        $pipelines = $none->pipelines();

        return self::of(
            check: $none->check(),
            trustMergedPullRequests: $none->mergedTrust(),
            mergedOwnScopeProofs: $none->mergedOwnScopeProofs(),
            gitlabTemplate: $pipelines->gitlabTemplate(),
            buildkiteStep: $pipelines->buildkiteStep(),
            buildkiteDefinition: $pipelines->buildkiteDefinition(),
            azureDefinition: $pipelines->azureDefinition(),
            bitbucketDefinition: $pipelines->bitbucketDefinition(),
            jenkinsDefinition: $pipelines->jenkinsDefinition(),
            schedule: $none->schedule(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->plan, $later->plan),
                Absent::laid($this->defaultBranch, $later->defaultBranch),
                Absent::laid($this->check, $later->check),
                Absent::laid($this->trustMergedPullRequests, $later->trustMergedPullRequests),
                Absent::laid($this->mergedOwnScopeProofs, $later->mergedOwnScopeProofs),
                $this->pipelines->over($later->pipelines),
                Absent::laid($this->schedule, $later->schedule),
            )
            : $this;
    }

    /**
     * The CI plan, with the options this section gives a built-in one laid under its own, or none, when it is
     * detected from the environment.
     */
    public function plan(): Choice|Absent
    {
        return $this->plan instanceof Choice && $this->plan->use() instanceof Name
            ? Choice::of(
                $this->plan->use()->value(),
                $this->plan->options()->over($this->planOptions($this->plan->use())->written()),
            )
            : $this->plan;
    }

    /** The default branch, or none, when the CI or git says which it is. */
    public function defaultBranch(): string|Absent
    {
        return $this->defaultBranch;
    }

    /**
     * `ci.check`: the name of the check-run the verdict reports under, which a passing run's proof records and a
     * pull request's passing run is trusted by (ADR-0007).
     */
    public function check(): string
    {
        return $this->check instanceof Absent ? self::CHECK : $this->check;
    }

    /**
     * `ci.schedule`: the cron schedule `init --ci` writes for the full run,
     * which reaches the units no change does; weekly unless the config says.
     * A workflow's triggers are its own, so a reusable workflow cannot hold it.
     */
    public function schedule(): string
    {
        return $this->schedule instanceof Absent ? self::WEEKLY : $this->schedule;
    }

    /**
     * `ci.trustMergedPullRequests`: what the default branch takes from a merged pull request's pass, nothing unless
     * the config says. The pass is a record the pull request's own run computed, an untrusted write, so anything
     * but `false` trusts that run (ADR-0005, ADR-0139).
     */
    public function mergedTrust(): MergedTrust
    {
        return $this->trustMergedPullRequests instanceof MergedTrust
            ? $this->trustMergedPullRequests
            : MergedTrust::Off;
    }

    /** Whether the default branch takes anything from a merged pull request's pass. */
    public function trustsMergedPullRequests(): bool
    {
        return $this->mergedTrust()->trusts();
    }

    /**
     * `ci.mergedOwnScopeProofs`: what a merged pull request's pass that took results from its own scope's proofs is
     * trusted for, `reach` unless the config says (ADR-0139).
     */
    public function mergedOwnScopeProofs(): OwnScopeTrust
    {
        return $this->mergedOwnScopeProofs instanceof OwnScopeTrust
            ? $this->mergedOwnScopeProofs
            : OwnScopeTrust::Reach;
    }

    /** The pipeline files each CI runs the gate from, and what a generated job is built from (ADR-0024). */
    public function pipelines(): Pipelines
    {
        return $this->pipelines;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'ci',
            Json::object(
                Member::of('plan', $this->plan instanceof Choice ? $this->plan->written() : $this->plan),
                Member::of('defaultBranch', $this->defaultBranch),
                Member::of('check', $this->check),
                Member::of('schedule', $this->schedule),
                Member::of(
                    'trustMergedPullRequests',
                    $this->trustMergedPullRequests instanceof MergedTrust
                        ? $this->trustMergedPullRequests->json()
                        : $this->trustMergedPullRequests,
                ),
                Member::of(
                    'mergedOwnScopeProofs',
                    $this->mergedOwnScopeProofs instanceof OwnScopeTrust
                        ? $this->mergedOwnScopeProofs->value
                        : $this->mergedOwnScopeProofs,
                ),
            )->merged($this->pipelines->written($origin)),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->plan instanceof Choice
                ? [PhpCalls::chosen($this->plan, AdapterBuilder::Ci, ...BuiltinCiPlan::names())]
                : [],
            ...CiCalls::named('Ci::defaultBranch(%s)', $this->defaultBranch),
            ...CiCalls::named('Ci::check(%s)', $this->check),
            ...CiCalls::named('Ci::schedule(%s)', $this->schedule),
            ...CiCalls::trust($this->trustMergedPullRequests),
            ...CiCalls::ownScope($this->mergedOwnScopeProofs),
        ])->and($this->pipelines->php($origin));
    }

    /** The options the gate hands a CI plan it builds in, from this section (see Pipelines::options()). */
    public function planOptions(Name $plan): Options
    {
        return $this->pipelines->options($plan);
    }
}

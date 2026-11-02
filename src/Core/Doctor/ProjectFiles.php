<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What the project's own files say, as far as the checks ask them: its
 * `.gitignore`, its Infection config, how its `composer.json` installs its
 * packages, which CI definitions run the gate, its baseline, what its
 * runner's own config says, and whether its clone holds the whole of its
 * history. A file nobody read is not given.
 *
 * @internal
 */
final readonly class ProjectFiles
{
    private function __construct(
        private GitIgnore|NotGiven $gitIgnore,
        private InfectionConfig|NotGiven $infection,
        private ComposerSetup|NotGiven $composer,
        private Paths|NotGiven $runningTheGate,
        private Baseline|CannotJudge|NotGiven $baseline,
        private RunnerConfig $runnerConfig,
        private bool $shallow,
        private SonarSources|NotGiven $sonarSources,
        private WarmRefusal|NotGiven $warmRefusal,
    ) {
    }

    public static function none(): self
    {
        $none = NotGiven::value();

        return new self(
            $none,
            $none,
            $none,
            $none,
            $none,
            RunnerConfig::none(),
            shallow: false,
            sonarSources: $none,
            warmRefusal: $none,
        );
    }

    /** These, with the project's `.gitignore`, empty where it has none. */
    public function withGitIgnore(GitIgnore $gitIgnore): self
    {
        return new self(
            $gitIgnore,
            $this->infection,
            $this->composer,
            $this->runningTheGate,
            $this->baseline,
            $this->runnerConfig,
            $this->shallow,
            $this->sonarSources,
            $this->warmRefusal,
        );
    }

    /** These, with the project's Infection config, where it has one. */
    public function withInfection(InfectionConfig $infection): self
    {
        return new self(
            $this->gitIgnore,
            $infection,
            $this->composer,
            $this->runningTheGate,
            $this->baseline,
            $this->runnerConfig,
            $this->shallow,
            $this->sonarSources,
            $this->warmRefusal,
        );
    }

    /** These, with how `composer.json` installs the project's packages. */
    public function withComposer(ComposerSetup $composer): self
    {
        return new self(
            $this->gitIgnore,
            $this->infection,
            $composer,
            $this->runningTheGate,
            $this->baseline,
            $this->runnerConfig,
            $this->shallow,
            $this->sonarSources,
            $this->warmRefusal,
        );
    }

    /** These, with every CI definition that runs the gate, none where no definition does. */
    public function withRunningTheGate(Paths $definitions): self
    {
        return new self(
            $this->gitIgnore,
            $this->infection,
            $this->composer,
            $definitions,
            $this->baseline,
            $this->runnerConfig,
            $this->shallow,
            $this->sonarSources,
            $this->warmRefusal,
        );
    }

    /** These, with the baseline, empty where there is none, or why it cannot be read. */
    public function withBaseline(Baseline|CannotJudge $baseline): self
    {
        return new self(
            $this->gitIgnore,
            $this->infection,
            $this->composer,
            $this->runningTheGate,
            $baseline,
            $this->runnerConfig,
            $this->shallow,
            $this->sonarSources,
            $this->warmRefusal,
        );
    }

    /** These, with what the runner's own config says. */
    public function withRunnerConfig(RunnerConfig $config): self
    {
        return new self(
            $this->gitIgnore,
            $this->infection,
            $this->composer,
            $this->runningTheGate,
            $this->baseline,
            $config,
            $this->shallow,
            $this->sonarSources,
            $this->warmRefusal,
        );
    }

    /** These, from a shallow clone: one that holds only the newest commits, and not the history before them. */
    public function shallow(): self
    {
        return new self(
            $this->gitIgnore,
            $this->infection,
            $this->composer,
            $this->runningTheGate,
            $this->baseline,
            $this->runnerConfig,
            shallow: true,
            sonarSources: $this->sonarSources,
            warmRefusal: $this->warmRefusal,
        );
    }

    /** Whether the clone holds only the newest commits. */
    public function isShallow(): bool
    {
        return $this->shallow;
    }

    /** These, with the paths `sonar.sources` names, where `sonar-project.properties` sets it. */
    public function withSonarSources(SonarSources $sources): self
    {
        return new self(
            $this->gitIgnore,
            $this->infection,
            $this->composer,
            $this->runningTheGate,
            $this->baseline,
            $this->runnerConfig,
            $this->shallow,
            $sources,
            $this->warmRefusal,
        );
    }

    /** These, with why the last run's warm workers forked nothing (ADR-0023). */
    public function withWarmRefusal(WarmRefusal $refusal): self
    {
        return new self(
            $this->gitIgnore,
            $this->infection,
            $this->composer,
            $this->runningTheGate,
            $this->baseline,
            $this->runnerConfig,
            $this->shallow,
            $this->sonarSources,
            $refusal,
        );
    }

    public function warmRefusal(): WarmRefusal|NotGiven
    {
        return $this->warmRefusal;
    }

    public function gitIgnore(): GitIgnore|NotGiven
    {
        return $this->gitIgnore;
    }

    public function infection(): InfectionConfig|NotGiven
    {
        return $this->infection;
    }

    public function composer(): ComposerSetup|NotGiven
    {
        return $this->composer;
    }

    public function runningTheGate(): Paths|NotGiven
    {
        return $this->runningTheGate;
    }

    public function baseline(): Baseline|CannotJudge|NotGiven
    {
        return $this->baseline;
    }

    public function runnerConfig(): RunnerConfig
    {
        return $this->runnerConfig;
    }

    public function sonarSources(): SonarSources|NotGiven
    {
        return $this->sonarSources;
    }
}

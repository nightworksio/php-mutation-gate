<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;

/**
 * What the project's files say to the services around it, as far as the
 * checks ask them: the paths SonarQube's `sonar.sources` names (ADR-0028),
 * and the CODEOWNERS GitHub reads (ADR-0022), each as it stands.
 */
final readonly class ServiceFiles
{
    private function __construct(private SonarSources|NotGiven $sonarSources, private CodeOwners $codeOwners)
    {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), CodeOwners::none());
    }

    /** These, with the paths `sonar.sources` names, where `sonar-project.properties` sets it. */
    public function withSonarSources(SonarSources $sources): self
    {
        return new self($sources, $this->codeOwners);
    }

    /** These, with the project's CODEOWNERS, none where it has none. */
    public function withCodeOwners(CodeOwners $codeOwners): self
    {
        return new self($this->sonarSources, $codeOwners);
    }

    public function sonarSources(): SonarSources|NotGiven
    {
        return $this->sonarSources;
    }

    public function codeOwners(): CodeOwners
    {
        return $this->codeOwners;
    }
}

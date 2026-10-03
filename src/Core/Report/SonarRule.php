<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * The rules the SonarQube report raises its issues under: SARIF's, and one
 * for a surviving mutant a security-tagged mutator made, each with the
 * quality of software it affects (ADR-0028, decision 8).
 */
enum SonarRule: string
{
    case Survived = 'survived';
    case Uncovered = 'uncovered';
    case Unjudged = 'unjudged';
    case Flaky = 'flaky';
    case SurvivedSecurity = 'survived-security';

    private const string SECURITY = 'A mutant a security-tagged mutator made, which no test fails on.';

    /** The rule of a mutant SARIF reports under this rule, which has the same id. */
    public static function of(ResultRule $rule): self
    {
        return self::from($rule->value);
    }

    /** The rule's name, as SonarQube lists it. */
    public function title(): string
    {
        return match ($this) {
            self::Survived => 'Surviving mutant',
            self::Uncovered => 'Uncovered mutant',
            self::Unjudged => 'Unjudged mutant',
            self::Flaky => 'Flaky mutant',
            self::SurvivedSecurity => 'Surviving security mutant',
        };
    }

    /** What the rule reports, and the section of this release's troubleshooting guide that explains it. */
    public function description(Guide $guide): string
    {
        return sprintf(
            '%s See %s',
            $this === self::SurvivedSecurity ? self::SECURITY : ResultRule::from($this->value)->text(),
            $guide->link($this->slug()),
        );
    }

    /** The quality of software a mutant under this rule affects. */
    public function quality(): SonarQuality
    {
        return $this === self::SurvivedSecurity ? SonarQuality::Security : SonarQuality::Reliability;
    }

    private function slug(): Slug
    {
        return match ($this) {
            self::Survived, self::SurvivedSecurity => Slug::Survived,
            self::Uncovered => Slug::Uncovered,
            self::Unjudged => Slug::Unjudged,
            self::Flaky => Slug::Flaky,
        };
    }
}

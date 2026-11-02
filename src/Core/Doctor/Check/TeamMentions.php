<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Owner\OwnerMention;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/**
 * CODEOWNERS names a team while the comment mentions owners: a comment
 * GitHub Actions' own token writes cannot mention a team, so the team is
 * named without a mention (ADR-0022, ADR-0049).
 */
final readonly class TeamMentions
{
    private const string FOUND
        = 'CODEOWNERS names a team, which the comment names without a mention when GitHub Actions\' token writes it.';

    private const string WHY
        = 'GITHUB_TOKEN cannot read an organisation\'s teams, so github-actions[bot] mentions none.';

    private const string FIX
        = 'Write the comment with a token that can read the teams, or set owners.mention to never.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();

        return $settings instanceof Settings
            && $settings->floors()->owners()->mention() === OwnerMention::First
            && $observed->files()->services()->codeOwners()->namesTeam()
            ? Findings::of(Finding::of(
                Slug::TeamsNotMentioned,
                Severity::Advice,
                self::FOUND,
                self::WHY,
                self::FIX,
            ))
            : Findings::none();
    }
}

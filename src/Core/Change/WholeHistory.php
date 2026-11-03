<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use function array_map;
use function array_pop;
use function implode;
use function sprintf;

/**
 * The setting of each CI the gate writes a definition for that clones the
 * whole history, which a shallow clone does not hold.
 *
 * @internal
 */
enum WholeHistory: string
{
    case GitHub = '`fetch-depth: 0` on GitHub\'s `actions/checkout`';

    case GitLab = '`GIT_DEPTH: 0` on GitLab';

    case Bitbucket = '`clone: depth: full` on Bitbucket';

    case Azure = '`fetchDepth: 0` on Azure Pipelines\' checkout';

    /** Every CI's setting, as a sentence lists them. */
    public static function settings(): string
    {
        $settings = array_map(static fn(self $ci): string => $ci->value, self::cases());
        $last = array_pop($settings);

        return sprintf('%s, or %s', implode(', ', $settings), $last);
    }
}

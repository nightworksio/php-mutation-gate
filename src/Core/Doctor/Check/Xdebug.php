<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function array_diff;
use function array_map;
use function explode;
use function is_string;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;
use function trim;

/**
 * Xdebug slows every test: loaded in a mode other than `coverage` or `off`,
 * or collecting coverage where pcov, which is faster, is installed beside it.
 */
final readonly class Xdebug
{
    /** The variable that sets Xdebug's mode over its setting. */
    public const string MODE_VARIABLE = 'XDEBUG_MODE';

    private const string MODE_SETTING = 'xdebug.mode';

    /** Xdebug's mode where nothing sets one. */
    private const string DEFAULT_MODE = 'develop';

    /** The modes that cost a test nothing beyond coverage. */
    private const array CHEAP = ['coverage', 'off'];

    private const string SLOW_MODE = 'Xdebug runs in the mode %s in %s.';

    private const string SLOW_MODE_WHY
        = 'Every mode but coverage slows each test, and the runner runs tests again for every mutant.';

    private const string SLOW_MODE_FIX = 'Set XDEBUG_MODE=coverage where the gate runs, or xdebug.mode=coverage in %s.';

    private const string OVER_PCOV = 'Xdebug collects coverage in %s, although pcov is installed beside it.';

    private const string OVER_PCOV_WHY = 'pcov collects line coverage several times faster than Xdebug.';

    private const string OVER_PCOV_FIX
        = 'Add extension=pcov to %s: php-code-coverage collects with pcov wherever both are loaded.';

    public static function in(Observations $observed): Findings
    {
        $php = $observed->php();

        if (! $php instanceof RunnerPhp || ! $php->loads(Driver::XDEBUG)) {
            return Findings::none();
        }

        $mode = self::modeOf($php);
        $costly = array_diff(array_map(trim(...), explode(',', $mode)), self::CHEAP);

        return match (true) {
            $costly !== [] => self::finding(
                sprintf(self::SLOW_MODE, $mode, $php->binary()),
                self::SLOW_MODE_WHY,
                sprintf(self::SLOW_MODE_FIX, $php->iniFile()),
            ),
            ! $php->loads(Driver::PCOV) && $php->offers(Driver::PCOV) => self::finding(
                sprintf(self::OVER_PCOV, $php->binary()),
                self::OVER_PCOV_WHY,
                sprintf(self::OVER_PCOV_FIX, $php->iniFile()),
            ),
            default => Findings::none(),
        };
    }

    /** The mode Xdebug runs in: the variable's, else the setting's, else Xdebug's own default. */
    private static function modeOf(RunnerPhp $php): string
    {
        $variable = $php->variable(self::MODE_VARIABLE);
        $setting = $php->valueOf(self::MODE_SETTING);

        return match (true) {
            is_string($variable) && $variable !== '' => $variable,
            is_string($setting) && $setting !== '' => $setting,
            default => self::DEFAULT_MODE,
        };
    }

    private static function finding(string $found, string $why, string $fix): Findings
    {
        return Findings::of(Finding::of(Slug::XdebugSlowsTests, Severity::Slow, $found, $why, $fix));
    }
}

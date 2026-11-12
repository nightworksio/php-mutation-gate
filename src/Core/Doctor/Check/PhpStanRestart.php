<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function in_array;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * PHPStan restarts itself without Xdebug for every check, where Xdebug is
 * loaded in a mode other than `off` for the processes the gate starts it in:
 * `staticCheck.php`'s `xdebug.mode`, else Xdebug's variable, else its
 * setting (ADR-0020).
 *
 * @internal
 */
final readonly class PhpStanRestart
{
    private const string ANALYSER = 'phpstan';

    private const string MODE_SETTING = 'xdebug.mode';

    private const string OFF = 'off';

    /** Xdebug's mode where nothing sets one. */
    private const string DEFAULT_MODE = 'develop';

    private const string FOUND = 'PHPStan restarts itself without Xdebug for every check in %s.';

    private const string WHY
        = 'Xdebug is loaded in the mode %s for it, so each check boots PHP twice before it analyses anything.';

    private const string FIX = 'Leave xdebug.mode at off in staticCheck.php, as by default.';

    public static function in(Observations $observed): Findings
    {
        $php = $observed->php();
        $settings = $observed->settings();

        if (! $php instanceof RunnerPhp || ! $settings instanceof Settings || ! $php->loads(Driver::XDEBUG)) {
            return Findings::none();
        }

        $static = $settings->staticCheck();
        $mode = self::modeOf($php, $static->phpIni());
        $phpStan = in_array($static->tool()->use()->value(), [self::ANALYSER, StaticCheck::AUTO], true);

        return $phpStan && $mode !== self::OFF
            ? Findings::of(Finding::of(
                Slug::PhpStanRestarts,
                Severity::Slow,
                sprintf(self::FOUND, $php->binary()),
                sprintf(self::WHY, $mode),
                self::FIX,
            ))
            : Findings::none();
    }

    /** The mode PHPStan's processes run Xdebug in: `staticCheck.php`'s, else the variable's, else the setting's. */
    private static function modeOf(RunnerPhp $php, Json $ini): string
    {
        $named = self::named($ini);
        $variable = $php->variable(Xdebug::MODE_VARIABLE);
        $setting = $php->valueOf(self::MODE_SETTING);

        return match (true) {
            $named !== '' => $named,
            is_string($variable) && $variable !== '' => $variable,
            is_string($setting) && $setting !== '' => $setting,
            default => self::DEFAULT_MODE,
        };
    }

    /** The mode `staticCheck.php` names; none where it names none. */
    private static function named(Json $ini): string
    {
        foreach ($ini as $name => $value) {
            $mode = $name === self::MODE_SETTING ? json_decode($value->line()) : null;

            if (is_string($mode)) {
                return $mode;
            }
        }

        return '';
    }
}

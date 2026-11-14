<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function is_float;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The Outbreak settings a config writes beneath the options it gives a
 * runner (see `Core\Config\Outbreak::beneath()`), as the adapter reads them
 * back.
 *
 * @internal
 */
final readonly class OutbreakOptions
{
    /** The options beneath a runner's that hold its Outbreak settings; none where it holds none. */
    public static function beneath(Options $options): Options
    {
        $beneath = $options->object(Key::of(OutbreakSettings::OPTION));

        return $beneath instanceof Options ? $beneath : Options::none();
    }

    /**
     * What the binary is run by, beneath a runner's options: whether it is
     * enabled, the binary named, each run's timeout and its threads; the
     * defaults where the options hold none.
     */
    public static function binary(Options $options): OutbreakSettings
    {
        $read = self::beneath($options);
        $enabled = $read->flag(Key::of(OutbreakSettings::ENABLED));
        $binary = $read->text(Key::of(OutbreakSettings::BINARY));
        $timeout = $read->number(Key::of(OutbreakSettings::TIMEOUT));
        $threads = $read->integer(Key::of(OutbreakSettings::THREADS));

        return OutbreakSettings::of(
            $enabled === false ? $enabled : Absent::setting(),
            is_string($binary) ? $binary : Absent::setting(),
            is_float($timeout) ? Seconds::of($timeout) : Absent::setting(),
            is_int($threads) ? $threads : Absent::setting(),
        );
    }
}

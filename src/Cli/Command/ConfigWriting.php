<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function basename;
use function dirname;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Imported;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * The config `init` writes: what zero-config found, with an Infection config
 * imported over it where one is named, in the destination's format, and
 * `.mutation-gate/` added to `.gitignore`.
 */
final readonly class ConfigWriting
{
    private const string ZERO_CONFIG = 'what zero-config found';

    private const string AND_ZERO_CONFIG = '%s and what zero-config found';


    private const string PRINTED_IGNORING = "%s:\n\n%s\n.gitignore gains the line %s";

    /** What was written, said as a sentence, and what became of each imported key, or why nothing was. */
    public static function written(
        Setting $setting,
        Settings $settings,
        Destination $destination,
        Path|NotGiven $from,
        Layer $more,
        Output $output,
    ): string|Invalid|CannotJudge {
        $import = self::seeded($setting, $settings, $from, $more);
        $text = $import instanceof Import
            ? $setting->formats->file(
                $import->layer(),
                $destination->format(),
                ConfigFile::at($destination->file(), Path::of($setting->project)),
            )
            : $import;

        if (! is_string($text)) {
            return $text;
        }

        $source = $from instanceof Path ? sprintf(self::AND_ZERO_CONFIG, $from->value()) : self::ZERO_CONFIG;
        $said = $output === Output::Printed
            ? self::printed($setting->project, $destination, $text)
            : self::write($setting->project, $destination, $text, $source);

        return is_string($said) && $from instanceof Path
            ? sprintf("%s\n%s", $said, $import->report($from->value()))
            : $said;
    }

    /** What zero-config found, with the Infection config imported over it where one is named. */
    private static function seeded(
        Setting $setting,
        Settings $settings,
        Path|NotGiven $from,
        Layer $more,
    ): Import|Invalid|CannotJudge {
        $zeroConfig = ZeroConfig::layer($setting->extensions, $settings);

        if (! $zeroConfig instanceof Layer) {
            return $zeroConfig;
        }

        $found = $zeroConfig->over($more);

        return $from instanceof Path
            ? Imported::from($setting->project, $from, $found, $setting->now)
            : Import::of($found);
    }

    /** The config printed whole, and the line `.gitignore` would gain, where it would gain one. */
    private static function printed(string $project, Destination $destination, string $text): string|CannotJudge
    {
        $needed = IgnoredWorkspace::needed(Directory::at($project));

        return match (true) {
            $needed instanceof CannotJudge => $needed,
            $needed => sprintf(self::PRINTED_IGNORING, $destination->shown(), $text, IgnoredWorkspace::line()),
            default => sprintf(Output::FILE, $destination->shown(), $text),
        };
    }

    /** The config written, said as a sentence, with where what it holds came from. */
    private static function write(
        string $project,
        Destination $destination,
        string $text,
        string $source,
    ): string|CannotJudge {
        $file = $destination->file()->value();
        $written = Directory::at(dirname($file))->write(Path::of(basename($file)), Contents::of($text));
        $ignored = $written instanceof CannotJudge ? $written : IgnoredWorkspace::in(Directory::at($project));

        return match (true) {
            $ignored instanceof CannotJudge => $ignored,
            $ignored => sprintf(
                'Wrote %s with %s, and added %s to .gitignore.',
                $destination->shown(),
                $source,
                IgnoredWorkspace::line(),
            ),
            default => sprintf('Wrote %s with %s.', $destination->shown(), $source),
        };
    }
}

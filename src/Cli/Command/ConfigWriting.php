<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function basename;
use function dirname;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Imported;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Tree\FoundTrees;
use NightWorksIO\MutationGate\Core\Tree\Trees;

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

    /**
     * What was written, said as a sentence, with the trees zero-config found
     * where the config names none, and what became of each imported key; or
     * why nothing was.
     */
    public static function written(
        Setting $setting,
        Settings $settings,
        Destination $destination,
        Path|NotGiven $from,
        Layer $more,
        Output $output,
    ): string|Invalid|CannotJudge {
        $found = ZeroConfig::found($setting->extensions, $settings);
        $import = $found instanceof ZeroConfig ? self::seeded($setting, $found, $more, $from) : $found;

        $trees = $found instanceof ZeroConfig ? $found->trees() : FoundTrees::of(Trees::none());

        return $import instanceof Import
            ? self::told($setting, $destination, $from, $output, $import, $trees)
            : $import;
    }

    /**
     * The config written, or printed, with the trees listed where it names
     * none, said as a sentence.
     */
    private static function told(
        Setting $setting,
        Destination $destination,
        Path|NotGiven $from,
        Output $output,
        Import $import,
        FoundTrees $trees,
    ): string|CannotJudge {
        $file = ConfigFile::at($destination->file(), Path::of($setting->project));
        $text = $setting->formats->file($import->layer(), $destination->format(), $file);

        if (! is_string($text)) {
            return $text;
        }

        $comment = $import->layer()->floors()->trees() instanceof Absent
            ? $destination->format()->commented($trees->lines($file))
            : '';
        $text = sprintf('%s%s', $text, is_string($comment) ? $comment : '');
        $source = $from instanceof Path ? sprintf(self::AND_ZERO_CONFIG, $from->value()) : self::ZERO_CONFIG;
        $said = $output === Output::Printed
            ? self::printed($setting->project, $destination, $text)
            : self::write($setting->project, $destination, $text, $source);
        $more = [
            ...$comment instanceof NotWritten ? [$trees->said()] : [],
            ...$from instanceof Path ? [$import->report($from->value())] : [],
        ];

        return is_string($said) ? implode("\n", [$said, ...$more]) : $said;
    }

    /** What zero-config found, with the Infection config imported over it where one is named. */
    private static function seeded(
        Setting $setting,
        ZeroConfig $found,
        Layer $more,
        Path|NotGiven $from,
    ): Import|CannotJudge {
        $layer = $found->layer()->over($more);

        return $from instanceof Path
            ? Imported::from($setting->project, $from, $layer, $found->declared(), $setting->now)
            : Import::of($layer);
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

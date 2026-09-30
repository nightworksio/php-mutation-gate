<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Path;

use function pathinfo;
use function sprintf;

/**
 * What an import does where the Infection config does not say it on its own:
 * the runner it chooses, where the HTML report goes, what becomes of
 * Infection's JSON log.
 */
final readonly class Choices
{
    /** Where the HTML report goes when only the Stryker dashboard asked for one. */
    private const string DASHBOARD = 'build/mutation-gate';

    private const string JSON
        = 'the gate\'s JSON is its own format; to write it, add the reports entry {"use": "json", "path": "%s"}';

    /** The runner an Infection config's import chooses: Infection, whose config it is. */
    public static function runner(): Choice
    {
        return Choice::of(BuiltinRunner::Infection->value, Options::none());
    }

    /** The directory the HTML report is written to, from the file Infection wrote its own to: that file's stem. */
    public static function html(Path $file): Path
    {
        $directory = pathinfo($file->value(), PATHINFO_DIRNAME);

        return Path::of(sprintf('%s/%s', $directory, pathinfo($file->value(), PATHINFO_FILENAME)));
    }

    /** The directory the HTML report is written to where only the Stryker dashboard asked for a report. */
    public static function dashboard(): Path
    {
        return Path::of(self::DASHBOARD);
    }

    /** Why Infection's JSON log is not imported, with the entry that writes the gate's. */
    public static function json(Path $file): string
    {
        return sprintf(self::JSON, $file->value());
    }
}

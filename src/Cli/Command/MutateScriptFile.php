<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Composer\MutateScript;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * `composer mutate`, added by `init` where a person asks for it (ADR-0024):
 * written into the project's `composer.json` where nothing else in it
 * changes, and otherwise printed as the line to add to its scripts; with
 * `--stdout`, printed.
 *
 * @internal
 */
final readonly class MutateScriptFile
{
    private const string ADD = "Add this to the scripts in composer.json, then run composer mutate:\n  %s";

    private const string HAS = 'composer.json already has a mutate script, so init leaves it as it is.';

    private const string LAID_OUT
        = 'composer.json is not laid out as Composer writes it, so init leaves it as it is. %s';

    private const string RUN = '%s Run it with composer mutate.';

    /** The script added to the project's `composer.json`, or printed, said; or why it could not be. */
    public static function made(string $project, Output $output): string|CannotJudge
    {
        $directory = Directory::at($project);
        $file = Manifest::fileIn(Path::root());
        $manifest = $output === Output::Printed ? NotGiven::value() : $directory->read($file);

        return match (true) {
            $manifest instanceof CannotJudge => $manifest,
            ! $manifest instanceof Contents => self::add(),
            MutateScript::isIn($manifest) => self::HAS,
            default => self::written($directory, $file, MutateScript::addedTo($manifest)),
        };
    }

    private static function written(Directory $project, Path $file, Contents|NotGiven $added): string|CannotJudge
    {
        $written = $added instanceof Contents ? $project->write($file, $added) : NotGiven::value();

        return match (true) {
            $written instanceof Written => sprintf(self::RUN, Written::to($file->value())->said()),
            $written instanceof CannotJudge => $written,
            default => sprintf(self::LAID_OUT, self::add()),
        };
    }

    private static function add(): string
    {
        return sprintf(self::ADD, MutateScript::line());
    }
}

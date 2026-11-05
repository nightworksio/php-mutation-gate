<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Serve;

use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Cli\Config\ConfigLocation;
use NightWorksIO\MutationGate\Cli\InstalledGate;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;

use function realpath;

/**
 * The project a daemon of `mutation-gate serve` serves, as it is on disk now
 * (ADR-0078): its root, the gate's version, and the digests of its
 * composer.lock and of the config file the command line finds.
 *
 * @internal
 */
final readonly class ServedProject
{
    /** The project's identity now, with the config file `--config` names, or the one found where it names none. */
    public static function identity(string $project, string|NotGiven $config): ServeIdentity
    {
        $root = Root::of($project);
        $found = ConfigLocation::in($project, $config);
        $real = realpath($project);

        return ServeIdentity::of(
            is_string($real) ? $real : $project,
            InstalledGate::version()->spelt(),
            self::digestOf($root->at(Manifest::lockIn(Path::of('')))->value()),
            $found instanceof Path ? self::digestOf($root->at($found)->value()) : '',
        );
    }

    /** The digest of a file's contents; nothing where there is no file. */
    private static function digestOf(string $file): string
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        return is_string($contents) ? Digest::sha256Of($contents)->value() : '';
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function is_readable;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;

/**
 * The version a project's installed Pest names itself by: what its
 * `Pest\version()` returns, which Pest stamps its test run history with and
 * reads one only under. It can differ from the release Composer installed:
 * Pest 5.1.0 names itself 5.0.5.
 *
 * @internal
 */
final readonly class PestVersion
{
    /** Where Pest declares the version it names itself by, in the vendor directory. */
    private const string SOURCE = 'pestphp/pest/src/Pest.php';

    /** The version `Pest\version()` returns, as Pest writes it. */
    private const string RETURNED = "/function\\s+version\\(\\)\\s*:\\s*string\\s*\\{\\s*return\\s+'([^'\\\\]+)'\\s*;/";

    /** The version the project's installed Pest names itself by; none where it cannot be read. */
    public static function of(Project $project): string|NotGiven
    {
        $source = $project->absolute($project->vendor()->child(Path::of(self::SOURCE)));
        $text = is_readable($source) ? file_get_contents($source) : false;

        return is_string($text) && preg_match(self::RETURNED, $text, $found) === 1
            ? $found[1]
            : NotGiven::value();
    }
}

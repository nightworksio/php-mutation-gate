<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function mb_strpos;
use function mb_substr;
use function mb_substr_count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use PhpToken;

use function scandir;
use function sprintf;

/**
 * Pest's own ignore marker (ADR-0008), `@pest-mutate-ignore` in a comment of
 * the source, each found with the `ignores.entries` entry that replaces it.
 * Whether a run may go ahead with them is the verdict's to decide.
 */
final readonly class NativeMarkers
{
    public const string MARKER = '@pest-mutate-ignore';



    /** Every marker in the PHP files these paths name. */
    public static function in(Project $project, Paths $files): Markers
    {
        $markers = Markers::none();

        foreach ($files as $path) {
            foreach (self::under($project->absolute($path)) as $file) {
                $markers = $markers->merge(self::inFile($project, $file));
            }
        }

        return $markers;
    }

    private static function inFile(Project $project, string $file): Markers
    {
        $markers = Markers::none();

        foreach (PhpToken::tokenize(sprintf('%s', file_get_contents($file))) as $token) {
            $at = $token->is([T_COMMENT, T_DOC_COMMENT]) ? mb_strpos($token->text, self::MARKER) : false;

            if ($at !== false) {
                $line = $token->line + mb_substr_count(mb_substr($token->text, 0, $at), "\n");
                $where = sprintf('%s:%d', $project->relative($file)->value(), $line);
                $markers = $markers->with(Marker::inSource($where, self::MARKER));
            }
        }

        return $markers;
    }

    /** @return list<string> the file itself, or every PHP file under the directory, in name order */
    private static function under(string $path): array
    {
        if (is_file($path)) {
            return Path::of($path)->isPhp() ? [$path] : [];
        }

        $entries = is_dir($path) ? scandir($path) : [];
        $found = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $child = sprintf('%s/%s', $path, $entry);
            $found = [...$found, ...(self::isFollowed($entry, $child) ? self::under($child) : [])];
        }

        return $found;
    }

    /**
     * Whether a directory's entry is walked into: not the directory itself or
     * its parent, and not a link to a directory, which may lead back up into
     * a loop.
     */
    private static function isFollowed(string $entry, string $child): bool
    {
        return $entry !== '.' && $entry !== '..' && (!is_link($child) || !is_dir($child));
    }
}

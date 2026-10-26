<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;
use function json_validate;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Staged;

use function rename;

/**
 * A file a Pest run's warm worker, or a process waiting on one, writes for
 * another to read while both run (ADR-0023): written beside its name first,
 * then moved there, so a reader finds it whole or not at all (see
 * Core\Runner\Staged), and read as the JSON it holds.
 */
final readonly class WholeFile
{
    /** The text written to the file whole: whether it could be. */
    public static function written(string $file, string $text): bool
    {
        $writing = Staged::beside($file);

        return file_put_contents($writing, $text) !== false && rename($writing, $file);
    }

    /** The JSON the file holds; none where there is no file, or it holds no JSON. */
    public static function read(string $file): Node|NotGiven
    {
        $text = is_file($file) ? file_get_contents($file) : NotGiven::value();

        return is_string($text) && json_validate($text) ? Node::decode($text) : NotGiven::value();
    }
}

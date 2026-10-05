<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function gzdecode;
use function is_string;

/** The text the gate gzipped, for a test that reads what it wrote. */
final class Gunzipped
{
    /** The text some gzipped bytes hold; nothing where they hold none. */
    public static function of(string $bytes): string
    {
        $text = gzdecode($bytes);

        return is_string($text) ? $text : '';
    }
}

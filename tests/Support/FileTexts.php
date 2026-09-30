<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;

/** Files read together, as plain values a test compares. */
final class FileTexts
{
    /**
     * Each file's text, or null where it is missing, by its path.
     *
     * @param  ByPath<Contents|Missing>   $files
     * @return array<string, string|null>
     */
    public static function of(ByPath $files): array
    {
        $texts = [];

        foreach ($files as $path => $file) {
            $texts[$path->value()] = $file instanceof Contents ? $file->text() : null;
        }

        return $texts;
    }
}

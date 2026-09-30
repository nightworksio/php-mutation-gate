<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\File\Path;

/** Where a project keeps its tests when nothing it configures says otherwise. */
final readonly class TestsDirectory
{
    private const string CONVENTIONAL = 'tests';

    public static function conventional(): Path
    {
        return Path::of(self::CONVENTIONAL);
    }
}

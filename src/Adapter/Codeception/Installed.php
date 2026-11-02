<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as Composer;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Floors;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function sprintf;

/**
 * The exact versions of the packages the Codeception runner drives, as
 * Composer installed them, where each is one the runner can drive
 * (ADR-0027): Codeception at its floor or later, and php-code-coverage at
 * the release whose map the native runner reads, which the runner measures
 * each test's coverage with (see Floors).
 *
 * @internal
 */
final readonly class Installed
{
    /** The first Codeception that installs beside php-code-coverage 14, whose map the native runner reads. */
    private const string FLOOR = '5.3.6';

    public static function versionsIn(string $manifest): Versions|CannotJudge
    {
        $floors = Floors::of(BuiltinRunner::Codeception, self::FLOOR);
        $file = Path::of($manifest);
        $installed = is_file($manifest)
            ? Composer::decode(Contents::of(sprintf('%s', file_get_contents($manifest))), $file)
            : Composer::missingAt($file);
        $versions = $installed instanceof CannotJudge
            ? $installed
            : $installed->drivenBy(Program::Codeception, ...$floors->driven());

        return $versions instanceof Versions ? $floors->held($versions, $manifest) : $versions;
    }
}

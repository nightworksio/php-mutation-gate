<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Adapter\Psalm\Psalm;

use function sprintf;

/** Psalm in a test's project, as the tests of the Psalm adapter start it. */
final readonly class PsalmProjects
{
    /** Psalm in a project, reading the config the gate hands it, if any. */
    public static function in(string $project, string $options = '{}'): Psalm
    {
        $psalm = Psalm::fromOptions(Configs::options($options), $project);

        return $psalm instanceof Psalm ? $psalm : throw new LogicException('No Psalm.');
    }

    /**
     * A stand-in Psalm project with `src/Money.php`, whose command line reports
     * these issues over the originals, as JSON, and exits as told.
     */
    public static function project(string $issues = '[]', int $exit = 0): string
    {
        $project = FakeAnalyser::psalm('Psalm 6.19.1@e2ca44251c1f1aa2e35452d426895746b47de64e');
        Scratch::write($project, 'src/Money.php', "<?php\n// error: InvalidReturnType kept in the original\n");
        Scratch::write($project, 'src/Wallet.php', "<?php\n// error: InaccessibleMethod in the wallet\n");
        Scratch::write($project, 'vendor/bin/answer.json', $issues);
        Scratch::write($project, 'vendor/bin/answer.exit', sprintf('%d', $exit));

        return $project;
    }
}

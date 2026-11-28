<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Cli\Command\Aside;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `pest:patch`, run from a project's `post-install-cmd` and `post-update-cmd`
 * when `pest.patch` is on. A patch that cannot be applied fails the install;
 * an install without dev packages that left pest-plugin-mutate out has
 * nothing to patch, and passes.
 *
 * @internal
 */
final readonly class PestPatch
{
    private const string DESCRIPTION = 'Apply the optional Pest patches';

    /** What it says where Composer installed no dev packages and the plugin is not there. */
    private const string LEFT_OUT
        = '%s patched nothing: Composer installed no dev packages, and pest-plugin-mutate is not among the rest.';

    /** What Composer sets `COMPOSER_DEV_MODE` to where it installs no dev packages. */
    private const string WITHOUT_DEV = '0';

    /** The plugin's file every one of its installs holds, and the first the patch reads. */
    private const string HELD = '%s/%s/src/MutationTest.php';

    /** @param string|false $devMode Composer's `COMPOSER_DEV_MODE` for the script that runs the command, where set */
    public static function command(string $vendor, PackageFiles $files, string|false $devMode = false): Command
    {
        return new Command(Patch::COMMAND)
            ->setDescription(self::DESCRIPTION)
            ->setCode(static function (OutputInterface $output) use ($vendor, $files, $devMode): int {
                if ($devMode === self::WITHOUT_DEV && self::isLeftOut($vendor, $files)) {
                    $output->writeln(sprintf(self::LEFT_OUT, Patch::COMMAND), OutputInterface::OUTPUT_RAW);

                    return ExitCode::Passed->value;
                }

                $patched = Patch::applyIn($vendor, $files);

                if ($patched instanceof CannotJudge) {
                    Aside::of($output)->writeln($patched->why(), OutputInterface::OUTPUT_RAW);

                    return ExitCode::CannotJudge->value;
                }

                $output->writeln($patched, OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }

    private static function isLeftOut(string $vendor, PackageFiles $files): bool
    {
        return $files->read(sprintf(self::HELD, $vendor, Package::PestMutate->value)) instanceof NotGiven;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Hunk;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Composer\VendorPatch;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Version;

use function sprintf;

/**
 * `infection:patch`: the changes to Infection that bring its mutant limit
 * onto the gate's (ADR-0008, decision 2), applied only to the Infection
 * releases whose places it was checked against (see Release).
 * - Each mutant is allowed the standard mutant limit of its covering tests'
 *   own time, within the bounds the gate names (see MutantTime), not
 *   Infection's own five times their time under its `timeout`.
 * - Where the gate names its bounds, no mutant is skipped for the time its
 *   covering tests take.
 * - A mutant's run is also stopped once it has printed nothing for its
 *   silence limit, the standard limit of its slowest covering test (see
 *   Silence).
 *
 * Run by Infection outside the gate, the patched lines keep Infection's own.
 */
final readonly class Patch
{
    /** The command that applies the patch. */
    public const string COMMAND = 'infection:patch';

    /** Why the command patches no release it does not know. */
    private const string UNSUPPORTED
        = '%s patched nothing: it patches Infection %s, and %s holds Infection %s. Install a supported release.';

    /** Why the command cannot say which release is installed. */
    private const string UNLISTED = '%s patched nothing: %s lists no Infection. Is Infection installed?';

    /** The files the patch changes, by their paths under Infection's source directory. */
    private const string FACTORY = 'Process/Factory/MutantProcessContainerFactory.php';

    private const string RUNNER = 'Process/Runner/MutationTestingRunner.php';

    private const string PARALLEL = 'Process/Runner/ParallelProcessRunner.php';

    /** What Infection times a mutant's covering tests by, and what it allows a run to start in. */
    private const string NOMINAL = '$mutant->getMutation()->getNominalTestExecutionTime()';

    private const string BOOTSTRAP = 'self::TEST_FRAMEWORK_BOOTSTRAP_THRESHOLD';

    private const string LIMIT_SHIPS = <<<'PHP'
                $timeout = min(%2$s + (self::TIMEOUT_FACTOR * %1$s), $this->timeout);
        PHP;

    private const string LIMIT_BECOMES = <<<'PHP'
                {MARK} the gate's mutant limit of the covering tests' own time, within the bounds it names.
                $timeout = class_exists(\%2$s::class)
                    ? \%2$s::of(%1$s, $this->timeout)
                    : min(
                        %3$s + (self::TIMEOUT_FACTOR * %1$s),
                        $this->timeout,
                    );
        PHP;

    private const string SKIP_SHIPS = <<<'PHP'
                if ($mutant->getMutation()->getNominalTestExecutionTime() < $this->timeout) {
                    return true;
                }
        PHP;

    private const string SKIP_BECOMES = <<<'PHP'
                {MARK} within the gate's bounds, no mutant is skipped for the time its tests take.
                if (class_exists(\%1$s::class) && \%1$s::bounded()) {
                    return true;
                }

                if ($mutant->getMutation()->getNominalTestExecutionTime() < $this->timeout) {
                    return true;
                }
        PHP;

    private const string WATCH_SHIPS = <<<'PHP'
                if ($this->configuration->isDryRun) {
                    $process = DryRunProcess::fromProcess($process);
                }
        PHP;

    private const string WATCH_BECOMES = <<<'PHP'
                if ($this->configuration->isDryRun) {
                    $process = DryRunProcess::fromProcess($process);
                }

                {MARK} a run that prints nothing for its silence limit is stopped (see Silence).
                if (class_exists(\%1$s::class)) {
                    \%1$s::watch($process, $mutant, $this->timeout);
                }
        PHP;

    private const string SILENCE_SHIPS = <<<'PHP'
                    try {
                        $process->checkTimeout();
                    } catch (ProcessTimedOutException) {
        PHP;

    private const string SILENCE_BECOMES = <<<'PHP'
                    try {
                        $process->checkTimeout();

                        {MARK} stopped where it printed nothing for its silence limit (see Silence).
                        if (class_exists(\%1$s::class)) {
                            \%1$s::check($process, microtime(true));
                        }
                    } catch (ProcessTimedOutException) {
        PHP;

    /** Patch Infection in a vendor directory, where it is a supported release, and say what was done. */
    public static function applyIn(string $vendor): string|CannotJudge
    {
        $release = self::release($vendor);

        return $release instanceof CannotJudge ? $release : PackageSource::applyIn(self::patch(), $vendor);
    }

    /** Whether Infection in a vendor directory carries every hunk, and no hunk another version wrote. */
    public static function isAppliedIn(string $vendor): bool
    {
        return PackageSource::isAppliedIn(self::patch(), $vendor);
    }

    /** Whose limit each mutant of the Infection in a vendor directory gets. */
    public static function stateIn(string $vendor): PatchState
    {
        return self::isAppliedIn($vendor) ? PatchState::Applied : PatchState::Missing;
    }

    /** The Infection release installed in a vendor directory, where the patch supports it; or why not. */
    private static function release(string $vendor): Version|CannotJudge
    {
        $file = Installed::fileIn(Path::of($vendor));
        $installed = is_file($file->value())
            ? Installed::decode(Contents::of(sprintf('%s', file_get_contents($file->value()))), $file)
            : Installed::missingAt($file);
        $versions = $installed instanceof CannotJudge ? [] : [...$installed->versionsOf(Package::Infection->value)];
        $release = $versions === []
            ? CannotJudge::because(sprintf(self::UNLISTED, self::COMMAND, $file->value()))
            : $versions[0];

        return match (true) {
            $installed instanceof CannotJudge => $installed,
            $release instanceof CannotJudge, Release::tryFrom($release->release()) instanceof Release => $release,
            default => CannotJudge::because(sprintf(
                self::UNSUPPORTED,
                self::COMMAND,
                Release::listed(),
                $vendor,
                $release->spelt(),
            )),
        };
    }

    private static function patch(): VendorPatch
    {
        return VendorPatch::of(
            self::COMMAND,
            Package::Infection->value,
            Hunk::in(
                self::FACTORY,
                sprintf(self::LIMIT_SHIPS, self::NOMINAL, self::BOOTSTRAP),
                sprintf(self::LIMIT_BECOMES, self::NOMINAL, MutantTime::class, self::BOOTSTRAP),
            ),
            Hunk::in(self::RUNNER, self::SKIP_SHIPS, sprintf(self::SKIP_BECOMES, MutantTime::class)),
            Hunk::in(self::FACTORY, self::WATCH_SHIPS, sprintf(self::WATCH_BECOMES, Silence::class)),
            Hunk::in(self::PARALLEL, self::SILENCE_SHIPS, sprintf(self::SILENCE_BECOMES, Silence::class)),
        );
    }
}

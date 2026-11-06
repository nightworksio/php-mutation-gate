<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_key_exists;

use Pest\Bootstrappers\BootKernelDump;

/**
 * What a booted warm worker did before it forked, as `pest:patch` asks it in
 * Pest's script, its kernel and its test suite loader: where this process
 * stands, whether its worker left Pest's plugins to each child, and the class
 * Pest's loader found in each test file the worker loaded. A child skips
 * what its worker ran once, keeps the test suite its worker built, and finds
 * each test file its worker loaded where the worker found it. A worker of
 * the verification tail (ADR-0075) boots the plugins for its children; a
 * worker of mutants' runs (ADR-0091) loads no test file and leaves the
 * plugins to each child. A fresh process, unbooted, does all of it itself,
 * as it does unpatched.
 *
 * @internal
 */
final class Booted
{
    private static BootStage $stage = BootStage::Unbooted;

    /** Whether the worker leaves Pest's plugins to each child, as a mutants' worker does (ADR-0091). */
    private static bool $defersPlugins = false;

    /** @var array<string, string> the class Pest's loader found in each test file the worker loaded, by the file */
    private static array $loaded = [];

    /** This process is a worker, booting the project before it forks. */
    public static function booting(): void
    {
        self::$stage = BootStage::Booting;
    }

    /**
     * This booting worker serves mutants' runs, leaving Pest's plugins, which
     * each run's variables tell what to record, to each child (ADR-0091).
     */
    public static function leavingPlugins(): void
    {
        self::$defersPlugins = true;
    }

    /**
     * Whether Pest's kernel boots its plugins in this process: a fresh one
     * does, and a tail's worker does for its children; a mutants' worker
     * leaves them to each child.
     */
    public static function bootsPlugins(): bool
    {
        return match (self::$stage) {
            BootStage::Unbooted => true,
            BootStage::Booting => ! self::$defersPlugins,
            BootStage::Forked => self::$defersPlugins,
        };
    }

    /** This process is a child forked from a worker, where the worker booted the project. */
    public static function forked(): void
    {
        self::$stage = self::$stage === BootStage::Booting ? BootStage::Forked : self::$stage;
    }

    public static function stage(): BootStage
    {
        return self::$stage;
    }

    /** Whether this process is a child of a booted worker, which keeps the test suite the worker built. */
    public static function isForked(): bool
    {
        return self::$stage === BootStage::Forked;
    }

    /**
     * Whether Pest's kernel skips this bootstrapper as it boots: a booting
     * worker leaves the kernel dump, which buffers what a run prints, to each
     * child, and a child runs it alone, its worker having run the rest.
     */
    public static function skips(string $bootstrapper): bool
    {
        return match (self::$stage) {
            BootStage::Unbooted => false,
            BootStage::Booting => $bootstrapper === BootKernelDump::class,
            BootStage::Forked => $bootstrapper !== BootKernelDump::class,
        };
    }

    /** The class Pest's loader found in a test file, kept while the worker boots. */
    public static function kept(string $file, string $class): void
    {
        if (self::$stage === BootStage::Booting) {
            self::$loaded[$file] = $class;
        }
    }

    /** The class the worker found in a test file, in a child; none where the worker loaded no such file. */
    public static function loaded(string $file): string
    {
        return self::$stage === BootStage::Forked && array_key_exists($file, self::$loaded) ? self::$loaded[$file] : '';
    }
}

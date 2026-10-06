<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use PHPUnit\Event\DirectDispatcher;
use PHPUnit\Event\DispatchingEmitter;
use PHPUnit\Event\Emitter;
use PHPUnit\Event\Telemetry\System;
use PHPUnit\Event\Telemetry\SystemCpuTimeMeter;
use PHPUnit\Event\Telemetry\SystemGarbageCollectorStatusProvider;
use PHPUnit\Event\Telemetry\SystemMemoryMeter;
use PHPUnit\Event\Telemetry\SystemStopWatch;
use PHPUnit\Event\TypeMap;
use PHPUnit\Runner\Version;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\XmlConfiguration\Loader;

use function version_compare;

/**
 * PHPUnit's configuration loader and PHP settings handler, built as the
 * installed PHPUnit builds them, for a worker's boot (ADR-0023, decision
 * 12). From PHPUnit 13.4 each is told where to emit what it reports, and a
 * boot tells them an emitter of its own, which nothing hears: each child's
 * PHPUnit loads the configuration again and reports it there, once. Before
 * 13.4 each emits through PHPUnit's event facade, which the guard then finds
 * started (decision 13).
 */
final readonly class Configuring
{
    /** The first PHPUnit release whose loader and handler are told where to emit. */
    private const string TOLD = '13.4';

    private function __construct(private string $version)
    {
    }

    /** As the PHPUnit installed beside the worker builds them. */
    public static function installed(): self
    {
        return new self(Version::id());
    }

    /** As the PHPUnit of this version builds them. */
    public static function at(string $version): self
    {
        return new self($version);
    }

    public function loader(): Loader
    {
        return $this->built(Loader::class);
    }

    public function handler(): PhpHandler
    {
        return $this->built(PhpHandler::class);
    }

    /**
     * One of the two, told where to emit where its constructor takes that.
     * Its class is named at run time because PHPUnit's releases declare its
     * constructor differently, and the package's own PHPUnit, which the
     * analyser reads, is one before 13.4.
     *
     * @template T of object
     *
     * @param  class-string<T> $class
     * @return T
     */
    private function built(string $class): object
    {
        return new $class(...$this->told());
    }

    /** @return list<Emitter> where the loader and the handler emit, as their constructors take it */
    private function told(): array
    {
        return version_compare($this->version, self::TOLD, '>=') ? [$this->unheard()] : [];
    }

    /** An emitter of the boot's own, apart from PHPUnit's facade, with nothing subscribed to it. */
    private function unheard(): Emitter
    {
        return new DispatchingEmitter(
            new DirectDispatcher(new TypeMap()),
            new System(
                new SystemStopWatch(),
                new SystemMemoryMeter(),
                new SystemGarbageCollectorStatusProvider(),
                new SystemCpuTimeMeter(),
            ),
        );
    }
}

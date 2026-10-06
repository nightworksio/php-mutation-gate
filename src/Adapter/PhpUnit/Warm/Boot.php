<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_map;
use function class_exists;
use function get_included_files;
use function in_array;

use NightWorksIO\MutationGate\Core\NotGiven;
use PHPUnit\Event\Facade;

use function realpath;

/**
 * A warm worker's boot, as the guard reads it: the autoloader and what it had
 * loaded before the rest of the boot began, the bootstrap PHPUnit's config
 * names, the spy that saw what the boot autoloaded from where, and whether
 * the boot started PHPUnit's event facade.
 */
final readonly class Boot
{
    /** @param list<string> $before each file loaded before the boot went on from the autoloader, by its real path */
    private function __construct(
        private string $autoloader,
        private array $before,
        private string|NotGiven $bootstrap,
        private BootSpy $spy,
        private string $events,
        private bool $eventsBefore,
    ) {
    }

    /**
     * A boot that has required this autoloader, and watches from here on: for
     * PHPUnit's event facade, or for the class a test names in its place.
     */
    public static function begun(string $autoloader, string $events = Facade::class): self
    {
        return new self(
            (string) realpath($autoloader),
            array_map(static fn(string $file): string => (string) realpath($file), get_included_files()),
            NotGiven::value(),
            BootSpy::watching(),
            $events,
            class_exists($events, autoload: false),
        );
    }

    /**
     * Whether the boot started PHPUnit's event facade, whose buffered events
     * and start time every child would inherit: its class loaded during the
     * boot, as PHPUnit before 13.4 loads it to report a deprecation in its
     * configuration.
     */
    public function startedEvents(): bool
    {
        return ! $this->eventsBefore && class_exists($this->events, autoload: false);
    }

    /** This boot, done: its spy no longer watches, so no child pays for it. */
    public function done(): self
    {
        $this->spy->stop();

        return $this;
    }

    /** This boot, going on through this bootstrap. */
    public function through(string $bootstrap): self
    {
        return clone($this, ['bootstrap' => (string) realpath($bootstrap)]);
    }

    /**
     * Where the boot loaded a file: the autoloader, where it was loaded before
     * the rest of the boot; the line of the bootstrap that autoloaded it, where
     * the spy saw that; or else the bootstrap.
     */
    public function whereLoaded(string $file): string
    {
        $line = $this->bootstrap instanceof NotGiven ? NotGiven::value() : $this->spy->lineOf($file, $this->bootstrap);

        return match (true) {
            in_array($file, $this->before, strict: true) => $this->autoloader,
            ! $line instanceof NotGiven => $line,
            default => $this->ran(),
        };
    }

    /** The file of the boot that ran last: the bootstrap, or the autoloader where there is none. */
    public function ran(): string
    {
        return $this->bootstrap instanceof NotGiven ? $this->autoloader : $this->bootstrap;
    }
}

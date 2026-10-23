<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_diff;
use function get_included_files;

use NightWorksIO\MutationGate\Core\Native\OwnFiles;
use NightWorksIO\MutationGate\Core\Native\Selected;
use PhpSpec\Event\ExampleEvent;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Measures the lines of the project's own files each example the run
 * selects runs, and how long it took, with php-code-coverage, and writes the
 * map once the suite is done, as PHPUnit's `--coverage-php` writes it, which
 * the native runner reads. Each file PHP loaded by an example's end is one
 * the map measures where it is the project's own, so no driver measures a
 * file of the vendor directory.
 */
final class Covering implements EventSubscriberInterface
{
    /** @var list<string> the files PHP loaded that the map has been told of */
    private array $told = [];

    /** @param non-empty-string $map */
    public function __construct(
        private readonly string $map,
        private readonly CodeCoverage $coverage,
        private readonly Selected $selected,
        private readonly OwnFiles $own,
    ) {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return ['beforeExample' => 'started', 'afterExample' => 'ended', 'afterSuite' => 'written'];
    }

    public function started(ExampleEvent $event): void
    {
        $example = Example::of($event->getExample());

        if ($this->selected->selects($example)) {
            $this->coverage->start($example->key());
        }
    }

    public function ended(ExampleEvent $event): void
    {
        if ($this->selected->selects(Example::of($event->getExample()))) {
            $loaded = get_included_files();
            $this->coverage->filter()->includeFiles($this->own->among(array_diff($loaded, $this->told)));
            $this->told = $loaded;
            $append = true;
            $this->coverage->stop($append, null, null, null, $event->getTime());
        }
    }

    public function written(): void
    {
        new Serializer()->serialize($this->map, $this->coverage);
    }
}

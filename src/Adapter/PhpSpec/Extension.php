<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function file_put_contents;
use function getenv;
use function ini_get;
use function is_string;

use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use PhpSpec\Extension as PhpSpecExtension;
use PhpSpec\ServiceContainer;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

/**
 * The gate's PhpSpec extension, which its launcher loads into PhpSpec's
 * container before PhpSpec reads the project's config (ADR-0027): it skips
 * every example the run does not select; records each example the run
 * selects to the file `MUTATION_GATE_RESULTS` names, where it names one;
 * says in the file `MUTATION_GATE_GUARD` names whether opcache could serve
 * a cached original in place of the mutated file; and measures the
 * coverage, or lists the examples, where the launcher was told to.
 */
final readonly class Extension implements PhpSpecExtension
{
    /** The tag PhpSpec's example runner reads its maintainers by. */
    private const string MAINTAINERS = 'runner.maintainers';

    /** The tag PhpSpec's event dispatcher reads its subscribers by. */
    private const string LISTENERS = 'event_dispatcher.listeners';

    /** The services it defines in PhpSpec's container, each under the gate's own name. */
    private const string SELECTING = 'mutation_gate.selecting';

    private const string RECORDING = 'mutation_gate.recording';

    private const string COVERING = 'mutation_gate.covering';

    private const string LISTING = 'mutation_gate.listing';

    public function __construct(private Told $told, private OwnFiles $own)
    {
    }

    /** @param array<mixed> $params none: the launcher loads it, and no config names it */
    public function load(ServiceContainer $container, array $params): void
    {
        $selected = $this->told->selected();
        $results = getenv(Variable::Results->value);
        $coverage = $this->told->coverage();
        $listing = $this->told->listing();

        $container->define(self::SELECTING, static fn(): Selecting => new Selecting($selected), [self::MAINTAINERS]);

        if (is_string($results) && $results !== '') {
            $recording = static fn(): Recording => new Recording($results, $selected);
            $container->define(self::RECORDING, $recording, [self::LISTENERS]);
        }

        if (! $coverage instanceof NotGiven && $coverage !== '') {
            $own = $this->own;
            $covering = static fn(): Covering => self::covering($coverage, $selected, $own);
            $container->define(self::COVERING, $covering, [self::LISTENERS]);
        }

        if (! $listing instanceof NotGiven) {
            $container->define(self::LISTING, static fn(): Listing => new Listing($listing), [self::LISTENERS]);
        }

        self::noting(getenv(Variable::Guard->value), Opcache::ofCommandLine(ini_get(Opcache::CLI)));
    }

    /** Says in the guard file, where one is named, that opcache could serve a cached original. */
    public static function noting(string|false $file, Opcache $opcache): void
    {
        $note = Guard::noting($opcache);

        if (is_string($file) && $file !== '' && is_string($note)) {
            file_put_contents($file, $note, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * The coverage of each selected example, over the project's own files,
     * written to this file.
     *
     * @param non-empty-string $map
     */
    private static function covering(string $map, Selected $selected, OwnFiles $own): Covering
    {
        $filter = new Filter();

        return new Covering($map, new CodeCoverage(new Selector()->select($filter), $filter), $selected, $own);
    }
}

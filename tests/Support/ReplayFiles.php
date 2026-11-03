<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function array_keys;
use function array_map;
use function file_get_contents;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use RuntimeException;

use function sprintf;
use function str_replace;

/**
 * What a replay of a merged pull request reads, as
 * `tests/Fixtures/Replays/<name>` holds it:
 *
 * - `changes.json`, each path the pull request changed, by its kind;
 * - `sources`, each changed test file the map places tests in, as it is at
 *   the merge (`.now`), and the patch from it back to the pull request's
 *   parent (`.patch`);
 * - `tests.json`, those files' tests the map holds, by their methods, each
 *   with the files it covers, and `files.json`, those files;
 * - `judges.json`, the files the runner judges by each changed test file;
 * - `units.json`, each unit the run planned with its learned cost, a new
 *   unit's estimate shared among those it was cut with, and the opening
 *   run's seconds;
 * - `layout.json`, what the gate's config said reaches everything.
 */
final readonly class ReplayFiles
{
    /** Where the replays are. */
    private const string DIRECTORY = 'tests/Fixtures/Replays/%s/%s';

    /** What a change of a file in its place is called. */
    private const string MODIFIED = 'Modified';

    private function __construct(private string $name)
    {
    }

    public static function of(string $name): self
    {
        return new self($name);
    }

    public function changes(): Changes
    {
        $changes = Changes::none();

        foreach (Node::decode($this->read('changes.json'))->items() as $row) {
            $parts = $row->items();
            $at = Path::of($parts[1]->text());
            $changes = $changes->with(match ($parts[0]->text()) {
                'Added' => Change::added($at, Lines::none()),
                'Deleted' => Change::deleted($at),
                'Renamed' => Change::renamed(Path::of($parts[2]->text()), $at, Lines::none()),
                default => Change::modified($at, Lines::none()),
            });
        }

        return $changes;
    }

    /** Each changed test file the pull request modified in its place, as it is and as it was. */
    public function sources(): Sources
    {
        $sources = Sources::none();

        foreach ($this->modified() as $file) {
            $now = $this->now($file);
            $sources = $sources
                ->withNow(Path::of($file), Contents::of($now))
                ->withBefore(Path::of($file), Contents::of(ReversedPatch::applied($now, $this->read($this->source($file, 'patch')))));
        }

        return $sources;
    }

    /** A changed test file as it is at the merge. */
    public function now(string $file): string
    {
        return $this->read($this->source($file, 'now'));
    }

    /** The files the runner judges by each changed test file, as the run read them. */
    public function judges(): Judges
    {
        $files = $this->files();
        $by = [];

        foreach (Node::decode($this->read('judges.json'))->entries() as $test => $judged) {
            foreach ($judged->integers() as $file) {
                $by[$files[$file]][] = Path::of((string) $test);
            }
        }

        $judges = Judges::none();

        foreach ($by as $file => $tests) {
            $judges = $judges->judging(Path::of($file), Paths::of(...$tests));
        }

        return $judges;
    }

    /** The map's tests of the changed test files, each covering the first line of each file it covers. */
    public function map(): CoverageMap
    {
        $files = $this->files();
        $by = [];

        foreach ($this->covers() as $test => $covered) {
            foreach ($covered as $file) {
                $by[$files[$file]][] = $test;
            }
        }

        return CoverageMap::of(...array_map(
            static fn(string $file, array $tests): CoveredLine => CoveredLine::of(Path::of($file), 1, ...$tests),
            array_keys($by),
            $by,
        ));
    }

    /** The map's tests the runner places in each changed test file, but in this one. */
    public function places(string $leftOut = ''): TestPlaces
    {
        $places = TestPlaces::none();

        foreach (Node::decode($this->read('tests.json'))->entries() as $file => $placed) {
            $ids = TestIds::none();

            foreach (array_keys($placed->field('tests')->entries()) as $method) {
                $ids = $ids->with($this->id($placed, (string) $method));
            }

            $places = (string) $file === $leftOut ? $places : $places->placing(Path::of((string) $file), $ids);
        }

        return $places;
    }

    /** @return array<array-key, float> each unit the run planned, with its learned cost, by its path as PHP keys it */
    public function costs(): array
    {
        return array_map(static fn(Node $cost): float => $cost->number(), Node::decode($this->read('units.json'))->field('costs')->entries());
    }

    /** How long the opening run of each shard took. */
    public function opening(): float
    {
        return Node::decode($this->read('units.json'))->field('opening')->number();
    }

    /** @return list<Glob> what the gate's config said reaches everything */
    public function everything(): array
    {
        return array_map(static fn(Node $glob): Glob => Glob::of($glob->text()), Node::decode($this->read('layout.json'))->field('everything')->items());
    }

    /** @return array<string, list<int>> the files each test of the changed test files covers, by its id */
    private function covers(): array
    {
        $covers = [];

        foreach (Node::decode($this->read('tests.json'))->entries() as $placed) {
            foreach ($placed->field('tests')->entries() as $method => $covered) {
                $covers[$this->id($placed, (string) $method)->value()] = $covered->integers();
            }
        }

        return $covers;
    }

    /** @return list<string> each changed test file the map places tests in that the pull request modified in its place */
    private function modified(): array
    {
        $placed = Node::decode($this->read('tests.json'))->entries();
        $modified = [];

        foreach (Node::decode($this->read('changes.json'))->items() as $row) {
            $parts = $row->items();
            $file = $parts[1]->text();
            $modified = $parts[0]->text() === self::MODIFIED && array_key_exists($file, $placed) ? [...$modified, $file] : $modified;
        }

        return $modified;
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_map(static fn(Node $file): string => $file->text(), Node::decode($this->read('files.json'))->items());
    }

    private function id(Node $placed, string $method): TestId
    {
        return TestId::of(sprintf('%s%s%s', $placed->field('class')->text(), TestMethod::SEPARATOR, $method));
    }

    private function source(string $file, string $version): string
    {
        return sprintf('sources/%s.%s', str_replace('/', '.', $file), $version);
    }

    private function read(string $file): string
    {
        $text = file_get_contents(Tree::at(sprintf(self::DIRECTORY, $this->name, $file)));

        return $text === false ? throw new RuntimeException(sprintf('No replay holds %s/%s.', $this->name, $file)) : $text;
    }
}

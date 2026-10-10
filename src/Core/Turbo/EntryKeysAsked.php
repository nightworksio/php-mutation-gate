<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

use function array_key_exists;
use function array_map;
use function array_push;
use function array_values;
use function mb_check_encoding;

use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Proof\Key\EntryKeying;

/**
 * The keys of test files' coverage entries, asked of the helper: everything
 * `EntryKeying::keyOf()` reads, written so the helper can work out the same
 * bytes, and the answer read back as untrusted input (ADR-0023, decision 1;
 * ADR-0029).
 *
 * Every path the walk can reach is written once, in a table, with the digest
 * the key writes beside it, so the helper never resolves a path or a digest
 * itself. The test files' order is the answer's order.
 */
final readonly class EntryKeysAsked
{
    private const string NOT_UTF8 = 'The paths or digests are not all UTF-8, which the helper\'s JSON cannot carry.';

    /**
     * @param list<string>     $paths   every path the walk can reach, each once
     * @param list<string>     $digests each path's digest as a key writes it
     * @param list<list<int>>  $edges   the paths each path names, by place
     * @param list<int>        $always  the paths every entry reads, by place
     * @param list<Path>       $files   the test files, in the answer's order
     * @param list<list<int>>  $starts  each test file's own path and the files its tests executed, by place
     */
    private function __construct(
        private string $base,
        private array $paths,
        private array $digests,
        private array $edges,
        private array $always,
        private array $files,
        private array $starts,
    ) {
    }

    /**
     * The request for these test files' keys over this base and name graph,
     * each file by its digest among these, with these files read by every
     * entry. PHP keys a path that reads as a number by the number, so the
     * graph's keys may be integers.
     *
     * @param array<array-key, list<string>> $naming   the files each file names, by its path
     * @param list<array{Path, Paths}>       $executed each test file and the files its tests executed
     */
    public static function of(Digest $base, array $naming, Fingerprints $files, Paths $always, array $executed): self
    {
        $paths = self::pathsIn($naming, $always, $executed);
        $places = [];

        foreach ($paths as $at => $path) {
            $places[$path] = $at;
        }

        $edges = [];
        $digests = [];

        foreach ($paths as $path) {
            $edges[] = self::placesOf(array_key_exists($path, $naming) ? $naming[$path] : [], $places);
            $digest = $files->digestOf(Path::of($path));
            $digests[] = $digest instanceof Digest ? $digest->value() : Missing::DIGESTED;
        }

        $tests = [];
        $starts = [];

        foreach ($executed as [$test, $ran]) {
            $tests[] = $test;
            $starts[] = self::placesOf([$test->value(), ...self::valuesOf($ran)], $places);
        }

        $everyEntry = self::placesOf(self::valuesOf($always), $places);

        return new self($base->value(), $paths, $digests, $edges, $everyEntry, $tests, $starts);
    }

    /** The test files asked about, in the order the answer gives their keys. */
    public function testFiles(): Paths
    {
        return Paths::of(...$this->files);
    }

    /** The request in the helper's protocol; or why it cannot be written. */
    public function request(): Request|NotAccelerated
    {
        foreach ([$this->base, ...$this->paths, ...$this->digests] as $text) {
            if (! mb_check_encoding($text, 'UTF-8')) {
                return NotAccelerated::because(self::NOT_UTF8);
            }
        }

        return Request::ofText(JsonText::compact([
            'protocol' => Protocol::VERSION,
            'kind' => Kind::EntryKeys->value,
            'format' => EntryKeying::FORMAT,
            'base' => $this->base,
            'paths' => $this->paths,
            'digests' => $this->digests,
            'edges' => $this->edges,
            'always' => $this->always,
            'entries' => $this->starts,
        ]));
    }

    /** Each test file's key, as the helper answered it; or why there is no answer, or it is not read. */
    public function keysIn(Answer|NotAccelerated $answer): EntryKeys|NotAccelerated
    {
        $digests = AnsweredKeys::read($answer, $this->files);

        if ($digests instanceof NotAccelerated) {
            return $digests;
        }

        $entries = EntryKeys::none();

        foreach ($digests as $at => $digest) {
            $entries = $entries->with($this->files[$at], $digest);
        }

        return $entries;
    }

    /** The test files a run recomputes in PHP to check the helper's answer ({@see Sample}). */
    public function sample(): Paths
    {
        return Sample::of($this->base, $this->files);
    }

    /**
     * Every path the request names, each once, in the order first named: the
     * graph's files and what each names, what every entry reads, then each
     * test file and what its tests executed.
     *
     * @param  array<array-key, list<string>> $naming
     * @param  list<array{Path, Paths}>       $executed
     * @return list<string>
     */
    private static function pathsIn(array $naming, Paths $always, array $executed): array
    {
        $named = [];

        foreach ($naming as $file => $targets) {
            array_push($named, (string) $file, ...$targets);
        }

        array_push($named, ...self::valuesOf($always));

        foreach ($executed as [$test, $ran]) {
            array_push($named, $test->value(), ...self::valuesOf($ran));
        }

        $seen = [];

        foreach ($named as $path) {
            $seen[$path] ??= $path;
        }

        return array_values($seen);
    }

    /**
     * @param  list<string>       $paths
     * @param  array<string, int> $places
     * @return list<int>
     */
    private static function placesOf(array $paths, array $places): array
    {
        return array_map(static fn(string $path): int => $places[$path], $paths);
    }

    /** @return list<string> */
    private static function valuesOf(Paths $paths): array
    {
        return array_map(static fn(Path $path): string => $path->value(), [...$paths]);
    }
}

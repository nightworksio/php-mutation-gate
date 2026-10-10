<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_push;
use function array_slice;
use function array_values;
use function ceil;
use function count;
use function hash;
use function max;
use function mb_check_encoding;

use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Key\EntryKeying;

use function sprintf;
use function uasort;

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
    /** How many entries a run recomputes in PHP at least, to check the helper's answer. */
    private const int SAMPLED_AT_LEAST = 50;

    /** The share of entries a run recomputes in PHP, where that is more than {@see SAMPLED_AT_LEAST}. */
    private const float SAMPLED_SHARE = 0.02;

    private const string NOT_UTF8 = 'The paths or digests are not all UTF-8, which the helper\'s JSON cannot carry.';

    private const string UNREAD = 'The helper\'s answer is not what this gate reads: %s';

    private const string MISCOUNTED = 'The helper answered %d keys for %d test files.';

    private const string NOT_A_KEY = 'The helper answered %s for %s, which is no SHA-256.';

    private const string OTHER_PROTOCOL = 'The helper answered in protocol %d, and this gate reads %d.';

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
        if ($answer instanceof NotAccelerated) {
            return $answer;
        }

        $read = Node::decode($answer->text(), Protocol::ANSWER);

        try {
            $protocol = $read->field('protocol')->integer();
            $keys = $read->field('keys')->items();
        } catch (NotInShape $unread) {
            return NotAccelerated::because(sprintf(self::UNREAD, $unread->getMessage()));
        }

        return match (true) {
            $protocol !== Protocol::VERSION
                => NotAccelerated::because(sprintf(self::OTHER_PROTOCOL, $protocol, Protocol::VERSION)),
            count($keys) !== count($this->files)
                => NotAccelerated::because(sprintf(self::MISCOUNTED, count($keys), count($this->files))),
            default => $this->keyed($keys),
        };
    }

    /**
     * The test files a run recomputes in PHP to check the helper's answer:
     * those whose digest beside the base comes first, so which are checked
     * changes with every state of the repository and no one picks them.
     */
    public function sample(): Paths
    {
        $ranks = [];

        foreach ($this->files as $at => $file) {
            $ranks[$at] = hash('sha256', ContentKeys::framed($this->base, $file->value()));
        }

        uasort($ranks, static fn(string $a, string $b): int => $a <=> $b);
        $size = (int) max(self::SAMPLED_AT_LEAST, ceil(count($this->files) * self::SAMPLED_SHARE));
        $sampled = [];

        foreach (array_keys(array_slice($ranks, 0, $size, preserve_keys: true)) as $at) {
            $sampled[] = $this->files[$at];
        }

        return Paths::of(...$sampled);
    }

    /** @param list<Node> $keys */
    private function keyed(array $keys): EntryKeys|NotAccelerated
    {
        $entries = EntryKeys::none();

        foreach ($keys as $at => $key) {
            $file = $this->files[$at];

            try {
                $text = $key->text();
            } catch (NotInShape $unread) {
                return NotAccelerated::because(sprintf(self::UNREAD, $unread->getMessage()));
            }

            if (! Digest::isSha256($text)) {
                return NotAccelerated::because(sprintf(self::NOT_A_KEY, $text, $file->value()));
            }

            $entries = $entries->with($file, Digest::of($text));
        }

        return $entries;
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

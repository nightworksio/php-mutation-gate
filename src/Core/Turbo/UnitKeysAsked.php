<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

use function array_key_exists;
use function array_values;
use function count;
use function mb_check_encoding;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Keys;

use function sprintf;

/**
 * Units' proof keys, asked of the helper: everything
 * `ContentKeys::keyReading()` reads past the run's base, written so the
 * helper can work out the same bytes, and the answer read back as untrusted
 * input (ADR-0007; ADR-0029).
 *
 * Each distinct set of tests is written once, its ids by place in one table
 * of every id, and each unit's covered lines name their set by place, in the
 * order the core gives them. The units' order is the answer's order.
 */
final readonly class UnitKeysAsked
{
    private const string UNNAMED = 'A covered line of %s names a set of tests the request was not given.';

    private const string NOT_UTF8 = 'The paths or test ids are not all UTF-8, which the helper\'s JSON cannot carry.';

    /**
     * @param list<string>          $tests every test id, each once
     * @param list<list<int>>       $sets  each set's ids, by place
     * @param list<array{path: string, judgedBy: string, read: string, lines: list<array{int, int}>}> $units
     * @param list<Path>            $paths the units, in the answer's order
     * @param list<string>          $texts every text the request carries
     */
    private function __construct(
        private string $base,
        private array $tests,
        private array $sets,
        private array $units,
        private array $paths,
        private array $texts,
        private string $unnamed,
    ) {
    }

    /**
     * The request for these units' keys over this base. Each unit comes with
     * what judges it, as the key writes it, the digest of what its judges
     * read, and each covered line with the name of its set, in the key's
     * order.
     *
     * @param list<array{Path, string, Digest, list<array{int, string}>}> $units
     * @param array<array-key, list<string>>                                $sets each set's test ids, by its name
     */
    public static function of(Digest $base, array $units, array $sets): self
    {
        $tests = [];
        $places = [];
        $written = [];
        $named = [];

        foreach ($sets as $name => $ids) {
            $named[$name] = count($written);
            $set = [];

            foreach ($ids as $id) {
                $places[$id] ??= count($tests);
                $tests[$places[$id]] = $id;
                $set[] = $places[$id];
            }

            $written[] = $set;
        }

        $asked = [];
        $paths = [];
        $texts = [$base->value(), ...array_values($tests)];
        $unnamed = '';

        foreach ($units as [$unit, $judgedBy, $read, $lines]) {
            $paths[] = $unit;
            $texts[] = $unit->value();
            $texts[] = $judgedBy;
            $unnamed = $unnamed === '' && ! self::allNamed($lines, $named) ? $unit->value() : $unnamed;
            $asked[] = [
                'path' => $unit->value(),
                'judgedBy' => $judgedBy,
                'read' => $read->value(),
                'lines' => self::linesOf($lines, $named),
            ];
        }

        return new self($base->value(), array_values($tests), $written, $asked, $paths, $texts, $unnamed);
    }

    /** The request in the helper's protocol; or why it cannot be written. */
    public function request(): Request|NotAccelerated
    {
        if ($this->unnamed !== '') {
            return NotAccelerated::because(sprintf(self::UNNAMED, $this->unnamed));
        }

        foreach ($this->texts as $text) {
            if (! mb_check_encoding($text, 'UTF-8')) {
                return NotAccelerated::because(self::NOT_UTF8);
            }
        }

        return Request::ofText(JsonText::compact([
            'protocol' => Protocol::VERSION,
            'kind' => Kind::UnitKeys->value,
            'format' => ContentKeys::FORMAT,
            'base' => $this->base,
            'tests' => $this->tests,
            'sets' => $this->sets,
            'units' => $this->units,
        ]));
    }

    /** Each unit's key, as the helper answered it; or why there is no answer, or it is not read. */
    public function keysIn(Answer|NotAccelerated $answer): Keys|NotAccelerated
    {
        $digests = AnsweredKeys::read($answer, $this->paths);

        if ($digests instanceof NotAccelerated) {
            return $digests;
        }

        $keys = Keys::none();

        foreach ($digests as $at => $digest) {
            $keys = $keys->with($this->paths[$at], $digest);
        }

        return $keys;
    }

    /** The units a run recomputes in PHP to check the helper's answer ({@see Sample}). */
    public function sample(): Paths
    {
        return Sample::of($this->base, $this->paths);
    }

    /**
     * Each line with the place of its set.
     *
     * @param  list<array{int, string}> $lines
     * @param  array<array-key, int>    $named each set's place, by its name
     * @return list<array{int, int}>
     */
    private static function linesOf(array $lines, array $named): array
    {
        $placed = [];

        foreach ($lines as [$line, $set]) {
            $placed[] = [$line, array_key_exists($set, $named) ? $named[$set] : 0];
        }

        return $placed;
    }

    /**
     * Whether every line names a set the request was given.
     *
     * @param list<array{int, string}> $lines
     * @param array<array-key, int>    $named
     */
    private static function allNamed(array $lines, array $named): bool
    {
        foreach ($lines as [, $set]) {
            if (! array_key_exists($set, $named)) {
                return false;
            }
        }

        return true;
    }
}

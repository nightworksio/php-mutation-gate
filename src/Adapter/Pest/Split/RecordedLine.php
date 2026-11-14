<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * One line of a stream's recorded lines, read (ADR-0135): whether it records
 * what a test covered, the test, whether it ran in a process of its own, the
 * lines it executed in each file, and its time where the line says it.
 *
 * @internal
 */
final readonly class RecordedLine
{
    /** @param array<string, list<int>> $lines each file's lines the test executed */
    private function __construct(
        public bool $covered,
        public string $test,
        public bool $own,
        public array $lines,
        public float|NotGiven $seconds,
    ) {
    }

    public static function of(string $text): self
    {
        $line = json_decode($text, associative: true);
        $line = is_array($line) ? $line : [];
        $seconds = self::entry($line, 's');

        return new self(
            self::entry($line, StreamLine::KIND) === StreamLine::COVERED,
            self::text($line, 't'),
            self::entry($line, StreamLine::OWN) === true,
            self::lines(self::entry($line, 'l')),
            is_float($seconds) || is_int($seconds) ? (float) $seconds : NotGiven::value(),
        );
    }

    /** @return list<array{string, int}> each file and line the test executed */
    public function places(): array
    {
        $places = [];

        foreach ($this->lines as $file => $numbers) {
            foreach ($numbers as $number) {
                $places[] = [$file, $number];
            }
        }

        return $places;
    }

    /** @return array<string, list<int>> */
    private static function lines(mixed $files): array
    {
        $lines = [];

        foreach (is_array($files) ? $files : [] as $file => $numbers) {
            if (is_string($file) && is_array($numbers)) {
                $lines[$file] = self::numbers($numbers);
            }
        }

        return $lines;
    }

    /**
     * @param  array<mixed> $numbers
     * @return list<int>
     */
    private static function numbers(array $numbers): array
    {
        $kept = [];

        foreach ($numbers as $number) {
            if (is_int($number)) {
                $kept[] = $number;
            }
        }

        return $kept;
    }

    /** @param array<mixed> $line */
    private static function text(array $line, string $key): string
    {
        $text = self::entry($line, $key);

        return is_string($text) ? $text : '';
    }

    /** @param array<mixed> $line */
    private static function entry(array $line, string $key): mixed
    {
        return array_key_exists($key, $line) ? $line[$key] : NotGiven::value();
    }
}

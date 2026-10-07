<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function count;
use function explode;
use function file_get_contents;
use function implode;

use Infection\Framework\Str;
use Infection\Mutant\Mutant;

use function is_file;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function trim;

/**
 * The mutants a patched Infection stopped at their silence limit (see
 * Silence), one JSON list each in the file the gate names, by what
 * Infection's own log says of each: its original file, its first line, its
 * mutator and its diff, cleaned as the log cleans it, then the silence
 * limit.
 */
final readonly class Silenced
{
    /** What each line holds: the file, the line, the mutator, the diff and the seconds. */
    private const int FIELDS = 5;

    /** @param array<string, float> $limits each silence limit, by the mutant it stopped */
    private function __construct(private array $limits)
    {
    }

    /** No mutant stopped at its silence limit. */
    public static function none(): self
    {
        return new self([]);
    }

    /** The line that records a mutant stopped at this silence limit. */
    public static function line(Mutant $mutant, Seconds $limit): string
    {
        $mutation = $mutant->getMutation();
        $record = Json::items(
            $mutation->getOriginalFilePath(),
            $mutation->getOriginalStartingLine(),
            $mutation->getMutatorName(),
            Str::convertToUtf8(Str::cleanForDisplay($mutant->getDiff()->get())),
            $limit->seconds(),
        );

        return sprintf("%s\n", $record->line());
    }

    /** The mutants a file records; none where there is no file, or a line is not in shape. */
    public static function in(string $file): self
    {
        $limits = [];
        $text = is_file($file) ? (string) file_get_contents($file) : '';

        try {
            foreach (explode("\n", trim($text)) as $line) {
                $limits += $line === '' ? [] : self::read(Node::decode($line));
            }
        } catch (NotInShape) {
            return self::none();
        }

        return new self($limits);
    }

    /** The silence limit the mutant Infection's log names so was stopped at; none where it was not. */
    public function of(string $file, int $line, string $mutator, string $diff): Seconds|NotGiven
    {
        $key = self::key($file, $line, $mutator, $diff);

        return array_key_exists($key, $this->limits) ? Seconds::of($this->limits[$key]) : NotGiven::value();
    }

    /**
     * @return array<string, float>
     *
     * @throws NotInShape
     */
    private static function read(Node $record): array
    {
        $fields = $record->items();

        if (count($fields) !== self::FIELDS) {
            throw NotInShape::at($record->at(), 'a file, a line, a mutator, a diff and seconds');
        }

        [$file, $line, $mutator, $diff, $seconds] = $fields;

        return [self::key($file->text(), $line->integer(), $mutator->text(), $diff->text()) => $seconds->number()];
    }

    private static function key(string $file, int $line, string $mutator, string $diff): string
    {
        return implode("\0", [$file, (string) $line, $mutator, $diff]);
    }
}

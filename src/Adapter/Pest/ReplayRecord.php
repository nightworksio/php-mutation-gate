<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_file;
use function json_validate;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordField;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What the plugin recorded of a replay of a kill's own run (see
 * PrefixReplays): how many tests it ran, whether any failed or errored, and
 * the digest of the order it took them in where it stopped. A line that is
 * no record, as one cut short, records nothing, and neither does any other
 * event the plugin writes there.
 */
final readonly class ReplayRecord
{
    private function __construct(private int $ran, private bool $failed, private string|NotGiven $order)
    {
    }

    /**
     * What a replay wrote to this results file, with what its process wrote
     * to the killer file of the copy it served, folded into the results file
     * first, as Pest's parent folds a mutant's.
     */
    public static function in(string $file, string $served): self
    {
        self::fold($file, $served);
        $text = is_file($file) ? file_get_contents($file) : false;
        $ran = 0;
        $failed = false;
        $order = NotGiven::value();

        foreach ($text === false ? [] : explode("\n", $text) as $line) {
            try {
                $record = json_validate($line) ? Node::decode($line) : Node::decode('{}');
                $event = RecordEvent::tryFrom($record->field(RecordField::Event->value)->text());
                $ran += $event === RecordEvent::Ran ? $record->field(RecordField::Count->value)->integer() : 0;
                $failed = $failed || $event === RecordEvent::Killed || $event === RecordEvent::Errored;
                $order = $event === RecordEvent::Stopped ? $record->field(RecordField::Order->value)->text() : $order;
            } catch (NotInShape) {
                continue;
            }
        }

        return new self($ran, $failed, $order);
    }

    /** How many tests the replay ran. */
    public function ran(): int
    {
        return $this->ran;
    }

    /** Whether a test failed or errored in the replay. */
    public function failed(): bool
    {
        return $this->failed;
    }

    /** The digest of the order the replay took its tests in, where it stopped; none where it never did. */
    public function order(): string|NotGiven
    {
        return $this->order;
    }

    /** What the replay's process wrote to its killer file, appended to its results file; the killer file gone. */
    private static function fold(string $file, string $served): void
    {
        $killers = KillerFile::taken(KillerFile::beside($file, $served), $served);

        if ($killers !== []) {
            file_put_contents($file, implode('', $killers), FILE_APPEND);
        }
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_shift;
use function count;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\NoticesChanges;
use NightWorksIO\MutationGate\Core\Watch\Unwatched;

/**
 * What `outbreak serve` tells of the changes it watches, scripted: each ask
 * of the files, or of the power source, answered by the next answer given
 * for it, the last answered again once they run out; each ask kept.
 */
final class NoticesChangesFake implements NoticesChanges
{
    /** @var list<array{list<string>, int, Seconds}> each ask of the files: its directories, since and wait */
    public private(set) array $changes = [];

    /** @var list<array{int, Seconds}> each ask of the power source: since and wait */
    public private(set) array $powers = [];

    /**
     * @param list<int|Unwatched|NotWritten> $changed what each ask of the files answers
     * @param list<int|Unwatched|NotWritten> $powered what each ask of the power source answers
     */
    public function __construct(private array $changed, private array $powered = [])
    {
    }

    public function changed(array $directories, int $since, Seconds $wait): int|Unwatched|NotWritten
    {
        $this->changes[] = [$directories, $since, $wait];

        return $this->next('changed');
    }

    public function powered(int $since, Seconds $wait): int|Unwatched|NotWritten
    {
        $this->powers[] = [$since, $wait];

        return $this->next('powered');
    }

    /** The next answer scripted for the files, or for the power source; the last again once they run out. */
    private function next(string $which): int|Unwatched|NotWritten
    {
        $answers = $which === 'changed' ? $this->changed : $this->powered;
        $answer = $answers[0] ?? NotWritten::because('No answer was scripted.');

        if (count($answers) > 1) {
            array_shift($answers);
        }

        $this->changed = $which === 'changed' ? $answers : $this->changed;
        $this->powered = $which === 'changed' ? $this->powered : $answers;

        return $answer;
    }
}

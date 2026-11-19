<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_numeric;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Core\Config\SlowdownCarry;

use function sprintf;
use function trim;

/**
 * The slowdown a pass's streams start at (ADR-0184): the highest the
 * streams of the pass before measured themselves at, kept beside the run's
 * results where it carries through the shard, so its next run starts there;
 * as fast as the map where nothing carries.
 *
 * @internal
 */
final class StreamSlowdown
{
    /** The file beside a run's results that keeps it. */
    private const string KEPT = '%s.slowdown';

    private float $reached = 0.0;

    /** Nothing carried, where no file is given and it does not carry. */
    public function __construct(
        private readonly string $file = '',
        private readonly bool $carries = false,
        private float $start = 1.0,
    ) {
    }

    /** What carries where it says, for a run whose results are in this file; in this run alone where it names none. */
    public static function carried(SlowdownCarry $carry, string|false $results): self
    {
        $file = $carry === SlowdownCarry::Shard && is_string($results) && $results !== ''
            ? sprintf(self::KEPT, $results)
            : '';
        $kept = $file !== '' && is_file($file) ? trim((string) file_get_contents($file)) : '';

        return new self($file, $carry !== SlowdownCarry::None, is_numeric($kept) ? max(1.0, (float) $kept) : 1.0);
    }

    /** The slowdown the pass's streams start at. */
    public function start(): float
    {
        return $this->start;
    }

    /** A stream of the pass ended having measured itself at this slowdown. */
    public function seen(float $measured): void
    {
        $this->reached = max($this->reached, $measured);
    }

    /** The pass ended: the next one starts at the highest slowdown its streams measured, where it carries. */
    public function passed(): void
    {
        if ($this->carries && $this->reached > 0.0) {
            $this->start = max(1.0, $this->reached);

            if ($this->file !== '') {
                file_put_contents($this->file, sprintf('%F', $this->start));
            }
        }

        $this->reached = 0.0;
    }
}

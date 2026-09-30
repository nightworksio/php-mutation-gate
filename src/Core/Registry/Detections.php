<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Registry;

use Closure;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The variable each registered CI plan's CI marks its jobs with, in the order
 * the plans were registered. A plan this
 * package builds in wins its own CI's variables: another package's plan is
 * detected only where none of this package's is, and is otherwise chosen by
 * name.
 */
final readonly class Detections
{
    /** @param list<array{Name, Origin, CiMarker}> $detections */
    private function __construct(private array $detections)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function with(Name $plan, Origin $origin, CiMarker $marker): self
    {
        return new self([...$this->detections, [$plan, $origin, $marker]]);
    }

    /** These detections, then another registry's. */
    public function merge(self $other): self
    {
        return new self([...$this->detections, ...$other->detections]);
    }

    /**
     * The first plan whose CI's marker the job's environment shows: the first
     * of the package's own, else the first of another package's; none where
     * no plan's is.
     *
     * @param Closure(CiMarker): bool $shows whether the job's environment shows a marker
     */
    public function detected(Closure $shows, Origin $own): Name|NotGiven
    {
        $theirs = NotGiven::value();

        foreach ($this->detections as [$plan, $origin, $marker]) {
            if (! $shows($marker)) {
                continue;
            }

            if ($origin->name() === $own->name()) {
                return $plan;
            }

            $theirs = $theirs instanceof NotGiven ? $plan : $theirs;
        }

        return $theirs;
    }
}

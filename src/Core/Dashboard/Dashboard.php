<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function strcmp;
use function usort;

/**
 * An organisation's dashboard (ADR-0024): every repository it shows, by
 * name, each with its verdict, its score, the trees below their floors and
 * the time the gate saved it over the last 30 days, as of an instant; and
 * `dashboard.json`, `"format": 1`, the same as data.
 *
 * @phpstan-type BelowEntry array{path: string, floor: float, score: float}
 * @phpstan-type RepositoryEntry array{
 *     name: string,
 *     url: string,
 *     private: bool,
 *     commit?: string,
 *     time?: string,
 *     verdict?: string,
 *     score?: float,
 *     belowFloor?: list<BelowEntry>,
 *     savedLast30Days?: float,
 *     unpublished?: string,
 * }
 */
final readonly class Dashboard
{
    public const int FORMAT = 1;

    /** @param list<Listing> $listings by name */
    private function __construct(private string $organisation, private Instant $time, private array $listings)
    {
    }

    public static function of(string $organisation, Instant $time, Listing ...$listings): self
    {
        $named = array_values($listings);
        usort($named, static fn(Listing $one, Listing $other): int => strcmp($one->name(), $other->name()));

        return new self($organisation, $time, $named);
    }

    public function organisation(): string
    {
        return $this->organisation;
    }

    /** When it was built. */
    public function time(): Instant
    {
        return $this->time;
    }

    /** @return list<Listing> by name */
    public function listings(): array
    {
        return $this->listings;
    }

    /** `dashboard.json`. */
    public function json(): string
    {
        return JsonText::encode([
            'format' => self::FORMAT,
            'organisation' => $this->organisation,
            'time' => $this->time->value(),
            'repositories' => array_map(self::entry(...), $this->listings),
        ]);
    }

    /** @return RepositoryEntry */
    private static function entry(Listing $listing): array
    {
        $published = $listing->published();
        $listed = ['name' => $listing->name(), 'url' => $listing->url(), 'private' => $listing->isPrivate()];

        if ($published instanceof CannotJudge) {
            return [...$listed, 'unpublished' => $published->why()];
        }

        $score = $published->score();
        $saved = $published->saved();

        return [
            ...$listed,
            'commit' => $published->commit(),
            'time' => $published->time()->value(),
            'verdict' => $published->verdict()->value,
            ...$score instanceof Score ? ['score' => $score->percent()] : [],
            'belowFloor' => array_map(self::below(...), $published->belowFloor()),
            ...$saved instanceof Seconds ? ['savedLast30Days' => $saved->seconds()] : [],
        ];
    }

    /** @return BelowEntry */
    private static function below(TreeBelow $tree): array
    {
        return ['path' => $tree->path(), 'floor' => $tree->floor()->percent(), 'score' => $tree->score()->percent()];
    }
}

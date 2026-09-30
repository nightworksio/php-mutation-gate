<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_is_list;
use function array_key_exists;

use DateTimeImmutable;

use function is_array;
use function is_int;

use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\Config\Definition\Date;
use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;

/**
 * The one validator every config format goes through (ADR-0002): the
 * untyped tree read into settings, or every problem in it at once, each at
 * its path with what was expected there.
 */
final readonly class Validator
{
    /** @param DateTimeImmutable $now the instant `ignores.maxDays` counts from */
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function validate(Document $document): Settings|Invalid
    {
        $tree = Json::decode($document->json());
        $reading = Definition::config()->read($tree, '');
        $read = $reading->value();
        $problems = [...$reading->problems(), ...$this->late($this->under($tree, 'ignores'))];

        return $read instanceof Fields && $problems === []
            ? Settings::from($read, Json::pretty($reading->shown()), Json::canonical($reading->results()))
            : Invalid::because(...$problems);
    }

    /**
     * Every ignore that does not expire within `ignores.maxDays` of now, where both are written as they must be.
     *
     * @return list<Problem>
     */
    private function late(mixed $ignores): array
    {
        $days = $this->under($ignores, 'maxDays');
        $entries = $this->under($ignores, 'entries');
        $late = [];

        if (! is_int($days) || $days < 1 || ! is_array($entries) || ! array_is_list($entries)) {
            return [];
        }

        $latest = Day::on($this->now->modify(sprintf('+%d days', $days)));

        foreach ($entries as $index => $entry) {
            $expires = $this->under($entry, 'expires');
            $day = Date::written()->read($expires, '')->value();

            if ($this->isLate($expires, $day, $latest)) {
                $late[] = Problem::at(
                    At::key(At::index('ignores.entries', $index), 'expires'),
                    sprintf(
                        'expected a date by %s, within ignores.maxDays of today, got %s',
                        $latest->value(),
                        $day instanceof Day ? sprintf('"%s"', $day->value()) : 'nothing',
                    ),
                );
            }
        }

        return $late;
    }

    /** Whether an ignore never expires, or expires after the latest day allowed; a date written wrong is not. */
    private function isLate(mixed $expires, mixed $day, Day $latest): bool
    {
        return match (true) {
            $expires instanceof Absent => true,
            $day instanceof Day => $latest->isBefore($day),
            default => false,
        };
    }

    /** The value under a key of an object as a config wrote it, or nothing. */
    private function under(mixed $object, string $key): mixed
    {
        return is_array($object) && array_key_exists($key, $object) ? $object[$key] : Absent::setting();
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Definition\At;
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
        $reading = Definition::config()->read(Json::decode($document->json()), '');
        $read = $reading->value();

        if (! $read instanceof Fields) {
            return Invalid::because(...$reading->problems());
        }

        $settings = Settings::from($read, Json::pretty($reading->shown()), Json::canonical($reading->results()));
        $late = $this->late($settings->ignores());

        return $late === [] ? $settings : Invalid::because(...$late);
    }

    /**
     * Every ignore that does not expire within `ignores.maxDays` of now.
     *
     * @return list<Problem>
     */
    private function late(Ignores $ignores): array
    {
        $days = $ignores->maxDays();

        if ($days instanceof Absent) {
            return [];
        }

        $latest = Day::on($this->now->modify(sprintf('+%d days', $days)));
        $late = [];

        foreach ($ignores->entries() as $index => $entry) {
            $expires = $entry->expires();

            if ($expires instanceof Absent || $latest->isBefore($expires)) {
                $late[] = Problem::at(
                    At::key(At::index('ignores.entries', $index), 'expires'),
                    sprintf(
                        'expected a date by %s, within ignores.maxDays of today, got %s',
                        $latest->value(),
                        $expires instanceof Day ? sprintf('"%s"', $expires->value()) : 'nothing',
                    ),
                );
            }
        }

        return $late;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;

/** One mutant the config ignores, by the gate's id, with the reason and when the ignore ends (ADR-0008). */
final readonly class IgnoredMutant implements Ignored
{
    private function __construct(private MutantId $mutant, private string $reason, private Day|Absent $expires)
    {
    }

    public static function of(MutantId $mutant, string $reason, Day|Absent $expires): self
    {
        return new self($mutant, $reason, $expires);
    }

    public function mutant(): MutantId
    {
        return $this->mutant;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function expires(): Day|Absent
    {
        return $this->expires;
    }

    public function matches(Mutant $mutant): bool
    {
        return $mutant->id()->value() === $this->mutant->value();
    }

    public function named(): string
    {
        return $this->mutant->value();
    }

    public function written(PathOrigin $origin): Json
    {
        $written = Json::object(
            Member::of('mutant', $this->mutant->value()),
        )->with(Member::of('reason', $this->reason));

        return $this->expires instanceof Day
            ? $written->with(Member::of('expires', $this->expires->value()))
            : $written;
    }

    public function php(PathOrigin $origin): string
    {
        return sprintf(
            'Ignore::mutant(%s, because: %s%s)',
            PhpCalls::literal($this->mutant->value()),
            PhpCalls::literal($this->reason),
            $this->expires instanceof Day ? sprintf(', until: %s', PhpCalls::literal($this->expires->value())) : '',
        );
    }
}

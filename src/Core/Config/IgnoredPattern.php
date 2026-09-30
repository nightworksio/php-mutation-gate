<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;

/**
 * The mutants of one mutator, by its full name or its family, in the paths a
 * glob matches, which the config ignores with a reason (ADR-0008).
 */
final readonly class IgnoredPattern implements Ignored
{
    private function __construct(
        private string $path,
        private string $mutator,
        private string $reason,
        private Day|Absent $expires,
    ) {
    }

    public static function of(string $path, string $mutator, string $reason, Day|Absent $expires): self
    {
        return new self($path, $mutator, $reason, $expires);
    }

    /** The glob. */
    public function path(): string
    {
        return $this->path;
    }

    public function mutator(): string
    {
        return $this->mutator;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function expires(): Day|Absent
    {
        return $this->expires;
    }

    public function written(): Json
    {
        $written = Json::object()
            ->with(Member::of('path', $this->path))
            ->with(Member::of('mutator', $this->mutator))
            ->with(Member::of('reason', $this->reason));

        return $this->expires instanceof Day
            ? $written->with(Member::of('expires', $this->expires->value()))
            : $written;
    }

    public function php(): string
    {
        return sprintf(
            'Ignore::mutator(%s, in: %s, because: %s%s)',
            PhpCalls::literal($this->mutator),
            PhpCalls::literal($this->path),
            PhpCalls::literal($this->reason),
            $this->expires instanceof Day ? sprintf(', until: %s', PhpCalls::literal($this->expires->value())) : '',
        );
    }
}

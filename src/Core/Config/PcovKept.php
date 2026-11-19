<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * `pest.pcovKept` (ADR-0185): the globs of the test files and source files
 * whose tests keep pcov as PHP is configured where `pest.pcovOff` turns it
 * off in a run's other processes, beside the tests the gate finds reading
 * the coverage driver itself (ADR-0183); every layer's globs together.
 *
 * @internal
 */
final readonly class PcovKept
{
    /** The key, and the Pest runner's option, that lists them. */
    public const string KEY = 'pcovKept';

    /** @param Listed<Glob>|Absent $globs */
    private function __construct(private Listed|Absent $globs)
    {
    }

    /** @param Listed<Glob>|Absent $globs */
    public static function of(Listed|Absent $globs = new Absent()): self
    {
        return new self($globs);
    }

    /** These globs, with a later layer's beside them. */
    public function over(self $later): self
    {
        return new self(Listed::joined($this->globs, $later->globs, static fn(Glob $glob): string => $glob->value()));
    }

    /**
     * The globs, as the repository spells them; none unless a layer lists
     * some.
     *
     * @return Listed<Glob>
     */
    public function globs(): Listed
    {
        return $this->globs instanceof Listed ? $this->globs : Listed::of();
    }

    /** The Pest runner's option that lists them: every glob, as the repository spells it. */
    public function option(): Member
    {
        $globs = [];

        foreach ($this->globs() as $glob) {
            $globs[] = $glob->value();
        }

        return Member::of(self::KEY, Json::items(...$globs));
    }

    /** The key of `pest` a layer writes, spelt from its origin; nothing where it lists none. */
    public function written(PathOrigin $origin): Member
    {
        return Member::of(
            self::KEY,
            $this->globs instanceof Listed ? Json::items(...WrittenPaths::globs($origin, $this->globs)) : $this->globs,
        );
    }

    /** The builder's call that lists them, spelt from the origin; none where a layer lists none. */
    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::globs('PestSpeedUps::pcovKept', $this->globs, $origin);
    }
}

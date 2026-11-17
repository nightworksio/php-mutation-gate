<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How the streams of a split-stream run get through their work (ADR-0182): the `splitStream` keys of speed alone. */
final readonly class StreamWork implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * `splitStream.parentDeadline`, as by default: a stream whose parent runs
     * a test past that test's deadline is stopped, and started again on the
     * tests it had not ended.
     */
    public static function parentDeadline(): self
    {
        return new self(Json::at('splitStream.parentDeadline', value: true));
    }

    /** `splitStream.parentDeadline` off: a stream runs each test for as long as it takes. */
    public static function noParentDeadline(): self
    {
        return new self(Json::at('splitStream.parentDeadline', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

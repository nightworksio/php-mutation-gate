<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\PathRuleMode;

/** What `paths` sets for the files a glob matches, and how a shard applies it (ADR-0074). */
final readonly class PathRule implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * A `paths` entry: the mutators, timeouts and static check these settings set, such as `Mutators::except()`,
     * `Timeouts::seconds()` and `StaticCheck::none()`, for the files the glob matches.
     */
    public static function matching(string $glob, Gate $rules): self
    {
        $entry = Json::object(Member::of('glob', $glob))->merged($rules->written());

        return new self(Json::object(Member::of('paths', Json::items($entry))));
    }

    /** `runner.pathRules`, where a preset or another layer chooses the runner: `PathRuleMode::Partitioned`. */
    public static function applying(PathRuleMode $mode): self
    {
        return new self(Json::object(Member::of('runner', Json::object(Member::of('pathRules', $mode->value)))));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

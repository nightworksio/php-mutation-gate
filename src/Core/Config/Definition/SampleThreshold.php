<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Sample\AutoSample;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * When a run samples of its own accord, `sampling.auto`: `off`, a count of
 * mutants of at least 1, or a duration of runner time.
 *
 * @internal
 *
 * @implements Shape<AutoSample>
 */
final readonly class SampleThreshold implements Shape
{
    public static function written(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $count = Integer::atLeast(1)->read($at)->value();
        $duration = Duration::written()->read($at)->value();

        return match (true) {
            $at->kind() === Kind::Text && $at->text() === AutoSample::OFF => Reading::of(AutoSample::off()),
            is_int($count) => Reading::of(AutoSample::aboveMutants($count)),
            $duration instanceof Seconds => Reading::of(AutoSample::aboveRunnerTime($duration)),
            default => Reading::refused($at->mismatch($this->expected())),
        };
    }

    public function expected(): string
    {
        return sprintf(
            '"%s", a count of mutants of at least 1, or %s',
            AutoSample::OFF,
            Duration::written()->expected(),
        );
    }

    public function schema(): Json
    {
        $choices = Json::items(
            Json::object(Member::of('const', AutoSample::OFF)),
            Integer::atLeast(1)->schema(),
            Duration::written()->schema(),
        );

        return Json::object(Member::of('anyOf', $choices));
    }

    public function effects(): array
    {
        return [];
    }
}

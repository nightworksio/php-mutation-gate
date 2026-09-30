<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * `ci.buildkite.step`: a map of step keys, agents, plugins or env, passed on
 * to Buildkite unread, but for `command`, which the gate runs before its own
 * and so reads: a command, or a list of commands, as text.
 *
 * @implements Shape<Json>
 */
final readonly class StepTemplate implements Shape
{
    private const string COMMANDS = 'a command, or a list of commands, as text';

    public static function buildkite(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $step = OpenObject::any()->read($at);
        $command = $at->field(BuildkiteStep::COMMAND);
        $text = Text::of(self::COMMANDS);
        $commands = $command->kind() === Kind::List || $command->kind() === Kind::Empty
            ? Items::of($text)->read($command)
            : $text->read($command);

        return $command->isPresent() && $commands->problems() !== []
            ? Reading::refused($command->mismatch(self::COMMANDS))
            : $step;
    }

    public function expected(): string
    {
        return OpenObject::any()->expected();
    }

    public function schema(): Json
    {
        $text = Text::of(self::COMMANDS);

        return OpenObject::any()->schema()->with(Member::of(
            'properties',
            Json::object(Member::of(
                BuildkiteStep::COMMAND,
                Json::object(Member::of('anyOf', Json::items($text->schema(), Items::of($text)->schema()))),
            )),
        ));
    }

    public function effects(): array
    {
        return [];
    }
}

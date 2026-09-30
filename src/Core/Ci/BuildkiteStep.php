<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * `ci.buildkite.step`: the keys every step the gate writes for Buildkite is
 * built from, such as `agents`, `plugins` and `env`, passed on unread, and
 * `command`, the commands each step runs before the gate's own.
 */
final readonly class BuildkiteStep
{
    /** The key of a step's command, or list of commands, which the gate runs its own after. */
    public const string COMMAND = 'command';

    private function __construct(private Json $keys)
    {
    }

    public static function none(): self
    {
        return new self(Json::object());
    }

    /** These keys, as a JSON object. */
    public static function of(Json $keys): self
    {
        return new self($keys);
    }

    /** These keys with a later layer's laid over them, key by key. */
    public function merged(self $later): self
    {
        return new self($this->keys->merged($later->keys));
    }

    public function json(): Json
    {
        return $this->keys;
    }
}

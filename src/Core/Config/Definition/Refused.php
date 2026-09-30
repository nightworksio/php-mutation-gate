<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Problem;

/**
 * A key a config may never write, with what to do instead: a webhook's `url`
 * is a credential, so it comes from the environment (ADR-0016).
 */
final readonly class Refused implements Node
{
    private function __construct(private string $instead)
    {
    }

    public static function because(string $instead): self
    {
        return new self($instead);
    }

    public function read(mixed $value, string $at): Reading
    {
        return Reading::refused([Problem::at($at, $this->instead)]);
    }

    public function expected(): string
    {
        return $this->instead;
    }

    public function schema(): array
    {
        return ['not' => Json::object([]), 'description' => $this->instead];
    }

    public function effects(): array
    {
        return [];
    }
}

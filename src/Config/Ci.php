<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** The CI the plan is written for (ADR-0006): `ci`. */
final readonly class Ci implements Setting
{
    private function __construct(private string $json)
    {
    }

    public static function github(): self
    {
        return new self(Json::encode(['ci' => ['plan' => 'github']]));
    }

    public static function gitlab(): self
    {
        return new self(Json::encode(['ci' => ['plan' => 'gitlab']]));
    }

    public static function buildkite(): self
    {
        return new self(Json::encode(['ci' => ['plan' => 'buildkite']]));
    }

    public static function circleci(): self
    {
        return new self(Json::encode(['ci' => ['plan' => 'circleci']]));
    }

    /** The plan as JSON, for any other CI. */
    public static function json(): self
    {
        return new self(Json::encode(['ci' => ['plan' => 'json']]));
    }

    /** A CI plan another extension registers by name, or a class, with its options. */
    public static function uses(string $plan, Option ...$options): self
    {
        return new self(Json::encode(['ci' => ['plan' => Json::decode(Option::choice($plan, ...$options))]]));
    }

    /** `ci.defaultBranch` */
    public static function defaultBranch(string $branch): self
    {
        return new self(Json::encode(['ci' => ['defaultBranch' => $branch]]));
    }

    /** `ci.gitlab.template`: the file whose hidden `.mutation-gate` job the generated jobs extend. */
    public static function gitlabTemplate(string $path): self
    {
        return new self(Json::encode(['ci' => ['gitlab' => ['template' => $path]]]));
    }

    /** `ci.buildkite.step`: the step every generated Buildkite step is built from. */
    public static function buildkiteStep(Option ...$keys): self
    {
        return new self(Json::encode(['ci' => ['buildkite' => ['step' => Json::decode(Option::object(...$keys))]]]));
    }

    public function written(): string
    {
        return $this->json;
    }
}

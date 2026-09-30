<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function array_values;

use NightWorksIO\MutationGate\Core\Format\Json;

/** `runner`: the tool that mutates (ADR-0004). */
final readonly class Runner
{
    private function __construct(private Json|string $json)
    {
    }

    public static function pest(): self
    {
        return self::uses('pest');
    }

    public static function infection(): self
    {
        return self::uses('infection');
    }

    /** A runner another extension registers by name, or a class, with its options. */
    public static function uses(string $runner, Option ...$options): self
    {
        return new self(Option::choice($runner, ...$options));
    }

    /**
     * This runner, withholding these environment variables from the project's tests besides those every run
     * withholds (ADR-0004): `runner.withhold`, names or globs such as `DEPLOY_*`.
     */
    public function withholding(string ...$globs): self
    {
        $runner = $this->json instanceof Json ? $this->json : Json::object()->with('use', $this->json);

        return new self($runner->with('withhold', Json::items(array_values($globs))));
    }

    public function written(): Json|string
    {
        return $this->json;
    }
}

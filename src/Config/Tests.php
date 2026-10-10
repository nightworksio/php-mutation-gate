<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * The order each mutant's covering tests run in (ADR-0008), `tests.order`,
 * and the suites whose tests judge them (ADR-0002), `tests.suites`.
 */
final readonly class Tests implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** The tests that killed a mutant before run first. */
    public static function killersFirst(): self
    {
        return new self(Json::at('tests.order', TestOrder::KillersFirst->value));
    }

    /** The tests run in the order the runner gives them. */
    public static function inRunnerOrder(): self
    {
        return new self(Json::at('tests.order', TestOrder::Runner->value));
    }

    /** The `<testsuite>`s of the PHPUnit config whose tests judge, by name; every suite where this is left out. */
    public static function suites(string $first, string ...$more): self
    {
        return new self(Json::at('tests.suites', Json::items($first, ...$more)));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

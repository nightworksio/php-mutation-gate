<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Format\Json;

/** The order each mutant's covering tests run in (ADR-0008): `tests.order`. */
final readonly class Tests implements Setting
{
    private function __construct(private TestOrder $order)
    {
    }

    /** The tests that killed a mutant before run first. */
    public static function killersFirst(): self
    {
        return new self(TestOrder::KillersFirst);
    }

    /** The tests run in the order the runner gives them. */
    public static function inRunnerOrder(): self
    {
        return new self(TestOrder::Runner);
    }

    public function written(): Json
    {
        return Json::object()->with('tests', Json::object()->with('order', $this->order->value));
    }
}

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\SampleThreshold;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Sample\AutoSample;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('reads off, a count of mutants, or a duration of runner time', function (string $written, AutoSample $read): void {
    expect(SampleThreshold::written()->read(Node::config($written))->value())->toEqual($read);
})->with([
    'off' => fn(): array => ['"off"', AutoSample::off()],
    'one mutant' => fn(): array => ['1', AutoSample::aboveMutants(1)],
    'a count' => fn(): array => ['20000', AutoSample::aboveMutants(20000)],
    'a duration' => fn(): array => ['"2h"', AutoSample::aboveRunnerTime(Seconds::of(7200))],
]);

it('refuses anything else', function (string $written, string $got): void {
    expect(SampleThreshold::written()->read(Node::config($written))->problems())->toEqual([
        Problem::at('', sprintf('expected "off", a count of mutants of at least 1, or a duration such as 90s, 15m, 1h30m or 7d, got %s', $got)),
    ]);
})->with([
    'no mutants' => ['0', '0'],
    'a negative count' => ['-3', '-3'],
    'a fraction' => ['1.5', '1.5'],
    'other text' => ['"on"', '"on"'],
    'off in capitals' => ['"OFF"', '"OFF"'],
    'true' => ['true', 'true'],
]);

it('writes its three choices into the schema, and has no effect of its own', function (): void {
    expect(SampleThreshold::written()->schema())->toEqual(Json::parse(
        '{"anyOf": [{"const": "off"}, {"type": "integer", "minimum": 1}, '
        . '{"type": "string", "pattern": "^(?=.)(?:[0-9]+d)?(?:[0-9]+h)?(?:[0-9]+m)?(?:[0-9]+s)?$"}]}',
    ))->and(SampleThreshold::written()->effects())->toBe([]);
});

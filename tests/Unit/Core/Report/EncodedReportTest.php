<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\EncodedReport;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\ManyMutants;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('holds the JSON report of a verdict as its data and as the text that data encodes', function (): void {
    $report = EncodedReport::of(Verdicts::failing());

    expect($report->data())->toBe(JsonReport::of(Verdicts::failing()))
        ->and($report->text())->toBe(JsonReport::encode(Verdicts::failing()));
});

it('builds and encodes the JSON report in time linear in its mutants', function (): void {
    $report = static function (int $size): Closure {
        $verdict = ManyMutants::verdict($size);

        return static fn(): int => strlen(EncodedReport::of($verdict)->text());
    };

    expect(EncodedReport::of(ManyMutants::verdict(8))->data()['mutants'][7]['coveredBy'])->toBe([0, 1, 2, 3])
        ->and(Growth::of(250, $report))->toBeLessThan(Growth::LINEAR);
});

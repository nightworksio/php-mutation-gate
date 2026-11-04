<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\JsonReportFile;
use NightWorksIO\MutationGate\Cli\Flow\Reported;
use NightWorksIO\MutationGate\Core\Report\EncodedReport;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('hands a reporter that writes from the JSON report the one the verdict built, and every other the verdict', function (): void {
    $file = sprintf('%s/report.json', Scratch::directory());
    $fake = new ReporterFake();

    $said = Reported::by(Verdicts::failing(), EncodedReport::of(Verdicts::passing()), [JsonReportFile::at($file), $fake]);

    expect($said)->toBe([sprintf('Wrote %s.', $file), 'Wrote memory.'])
        ->and(file_get_contents($file))->toBe(JsonReport::encode(Verdicts::passing()))
        ->and($fake->reported)->toEqual([Verdicts::failing()]);
});

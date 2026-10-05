<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Report;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** A `reports` entry of this reporter, with these options and no path. */
function reportOf(string $reporter, string $options): Report
{
    return Report::of(Choice::of($reporter, Configs::options($options)), Absent::setting());
}

it('names the variables an alert channel reads its URL and secret from', function (): void {
    expect(reportOf('webhook', '{"urlEnv": "HOOK_URL", "secretEnv": "HOOK_KEY"}')->secrets())
        ->toEqual(Withheld::of('HOOK_URL', 'HOOK_KEY'))
        ->and(reportOf('slack', '{"urlEnv": "TEAM_HOOK", "secretEnv": 3}')->secrets())->toEqual(Withheld::of('TEAM_HOOK'));
});

it('names none for a reporter that sends no alert, whatever its options hold', function (string $reporter): void {
    expect(reportOf($reporter, '{"urlEnv": "HOOK_URL", "secretEnv": "HOOK_KEY"}')->secrets())->toEqual(Withheld::nothing());
})->with(['json', 'otlp', 'Acme\\Reporter']);

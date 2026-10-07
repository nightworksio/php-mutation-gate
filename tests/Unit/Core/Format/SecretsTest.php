<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Format\Secrets;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

it('hides every secret in text, the longest first, so one inside another leaves none of it', function (): void {
    $secrets = Secrets::of('abcdefgh', 'abcdefgh-ijkl');

    expect($secrets->hidden('a abcdefgh-ijkl b abcdefgh c'))->toBe('a *** b *** c')
        ->and(Secrets::none()->hidden('a abcdefgh'))->toBe('a abcdefgh');
});

it('takes as secrets the values of the variables the gate withholds, each long enough not to be a word', function (): void {
    $variables = Variables::of([
        'GITHUB_TOKEN' => 'ghs_abcdefghijklmnop',
        'AWS_REGION' => 'eu-west-1',
        'ACTIONS_STEP_DEBUG' => 'true',
        'HOME' => '/home/runner/longer-than-eight',
        'SONAR_TOKEN' => 'eightchr',
        'AWS_PROFILE' => 'sevench',
    ]);
    $secrets = Secrets::withheldIn($variables, Withheld::standard());

    expect($secrets->hidden('ghs_abcdefghijklmnop eu-west-1 true /home/runner/longer-than-eight eightchr sevench'))
        ->toBe('*** *** true /home/runner/longer-than-eight *** sevench');
});

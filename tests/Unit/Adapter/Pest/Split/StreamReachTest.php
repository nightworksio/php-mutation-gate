<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamReach;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;

afterEach(function (): void {
    putenv(GateVariable::SplitReach->value);
});

it('hands a stream the options pcov collects from every tree of the project and the schemata with, as the gate told them', function (): void {
    $reach = PcovReach::under('/project', Path::of('vendor'))->keeping(Schemata::inVendor());

    foreach (StreamReach::told($reach) as $name => $value) {
        putenv(sprintf('%s=%s', $name, $value));
    }

    expect(StreamReach::options())->toBe($reach->options())
        ->and($reach->options()[3])->toBe('pcov.exclude=~^/project/vendor/(?!pestphp/pest\-plugin\-mutate/\.temp/schemata/)~');
});

it('hands a stream no options where the gate told none, or told them in a shape it cannot read', function (string|false $told): void {
    putenv($told === false ? GateVariable::SplitReach->value : sprintf('%s=%s', GateVariable::SplitReach->value, $told));

    expect(StreamReach::options())->toBe([]);
})->with([
    'none' => [false],
    'empty' => [''],
    'not JSON' => ['-d pcov.directory=/project'],
    'not a list' => ['"-d"'],
]);

it('hands a stream only the options that are text', function (): void {
    putenv(sprintf('%s=%s', GateVariable::SplitReach->value, '["-d",3,"pcov.directory=/project"]'));

    expect(StreamReach::options())->toBe(['-d', 'pcov.directory=/project']);
});

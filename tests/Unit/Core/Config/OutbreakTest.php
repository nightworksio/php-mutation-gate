<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Outbreak;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('runs the shipped binary by default, and what a later layer sets wins', function (): void {
    $off = Outbreak::of(enabled: false, binary: 'bin/outbreak');

    expect([Outbreak::of()->enabled(), $off->enabled(), $off->over(Outbreak::of(enabled: true))->enabled(), $off->over(Outbreak::of())->enabled()])
        ->toBe([true, false, true, false])
        ->and(Outbreak::of()->binary())->toEqual(NotGiven::value())
        ->and($off->binary())->toBe('bin/outbreak')
        ->and($off->over(Outbreak::of(binary: 'other'))->binary())->toBe('other');
});

it('stops a run after five minutes and splits on every core by default, and what a later layer sets wins', function (): void {
    $set = Outbreak::of(timeout: Seconds::of(90), threads: 2);

    expect(Outbreak::of()->timeout())->toEqual(Seconds::minutes(5))
        ->and(Outbreak::of()->threads())->toEqual(NotGiven::value())
        ->and([$set->timeout(), $set->threads()])->toEqual([Seconds::of(90), 2])
        ->and($set->over(Outbreak::of())->timeout())->toEqual(Seconds::of(90))
        ->and($set->over(Outbreak::of(timeout: Seconds::of(30), threads: 4))->threads())->toBe(4)
        ->and($set->over(Outbreak::of(timeout: Seconds::of(30)))->timeout())->toEqual(Seconds::of(30));
});

it('lays its settings beneath the Pest runner\'s options, and leaves any other runner\'s as they are', function (): void {
    expect(Outbreak::of(binary: 'bin/outbreak')->beneath(Choice::of('pest', Configs::options('{"patch": true}'))))
        ->toEqual(Choice::of('pest', Configs::options(
            '{"patch": true, "outbreak": {"enabled": true, "binary": "bin/outbreak", "timeout": 300.0}}',
        )))
        ->and(Outbreak::of(enabled: false, timeout: Seconds::of(90), threads: 2)->beneath(Choice::of('pest', Configs::options('{}'))))
        ->toEqual(Choice::of('pest', Configs::options('{"outbreak": {"enabled": false, "timeout": 90.0, "threads": 2}}')))
        ->and(Outbreak::of(enabled: false)->beneath(Choice::of('infection', Configs::options('{"x": 1}'))))
        ->toEqual(Choice::of('infection', Configs::options('{"x": 1}')));
});

it('writes what a layer sets, as a config file and as the PHP config calls it, and nothing where it sets none', function (): void {
    $set = Outbreak::of(enabled: false, binary: 'bin/outbreak', timeout: Seconds::of(45), threads: 2);

    expect($set->written())->toEqual(Json::object(Member::of(
        'outbreak',
        Json::object(
            Member::of('enabled', value: false),
            Member::of('binary', 'bin/outbreak'),
            Member::of('timeout', '45s'),
            Member::of('threads', 2),
        ),
    )))
        ->and(Outbreak::of()->written())->toEqual(Json::object())
        ->and($set->php())->toEqual(PhpCalls::inWith(
            'Outbreak::off()',
            "Outbreak::binary('bin/outbreak')",
            "Outbreak::timeout('45s')",
            'Outbreak::threads(2)',
        ))
        ->and(Outbreak::of(enabled: true)->php())->toEqual(PhpCalls::inWith('Outbreak::on()'))
        ->and(Outbreak::of()->php())->toEqual(PhpCalls::inWith());
});

it('is read from a config\'s outbreak section into the shards part, and refuses a value of another shape', function (): void {
    $outbreak = Configs::settings(['runner' => 'pest', 'outbreak' => [
        'enabled' => false,
        'binary' => 'bin/outbreak',
        'timeout' => '90s',
        'threads' => 2,
    ]])->shards()->outbreak();

    expect([$outbreak->enabled(), $outbreak->binary(), $outbreak->timeout(), $outbreak->threads()])
        ->toEqual([false, 'bin/outbreak', Seconds::of(90), 2])
        ->and(Configs::settings(['runner' => 'pest'])->shards()->outbreak()->enabled())->toBeTrue();

    foreach ([['binary' => 7], ['threads' => 0], ['timeout' => 'soon']] as $refused) {
        expect(Configs::validated(['runner' => 'pest', 'outbreak' => $refused]))->not->toBeInstanceOf(Settings::class);
    }
});

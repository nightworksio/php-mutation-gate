<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathRule;
use NightWorksIO\MutationGate\Core\Config\PathRules;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** The `paths` entries a config writes. */
$rules = static fn(array $paths): PathRules => Configs::valid(['paths' => $paths])->setup()->paths();

it('lays the entries a file matches least specific first: by the length of the literal prefix, then as written', function () use ($rules): void {
    $paths = $rules([
        ['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60]],
        ['glob' => 'src/**', 'timeouts' => ['seconds' => 20]],
        ['glob' => 'src/Legacy/Old.php', 'timeouts' => ['seconds' => 90]],
        ['glob' => 'src/*', 'timeouts' => ['seconds' => 30]],
        ['glob' => 'lib/**', 'timeouts' => ['seconds' => 5]],
    ]);

    expect(Configs::decoded($paths->for(Path::of('src/Legacy/Old.php'))->layer()))
        ->toBe(['timeouts' => ['seconds' => 90]])
        ->and(Configs::decoded($paths->for(Path::of('src/Legacy/New.php'))->layer()))
        ->toBe(['timeouts' => ['seconds' => 60]])
        ->and(Configs::decoded($paths->for(Path::of('src/Money.php'))->layer()))
        ->toBe(['timeouts' => ['seconds' => 30]])
        ->and($paths->for(Path::of('tests/MoneyTest.php'))->isNone())->toBeTrue();
});

it('lays every match setting by setting, so a more specific entry overrides only what it sets', function () use ($rules): void {
    $paths = $rules([
        ['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60]],
        ['glob' => 'src/**', 'timeouts' => ['seconds' => 20, 'most' => 120], 'staticCheck' => ['tool' => 'none']],
    ]);

    expect(Configs::decoded($paths->for(Path::of('src/Legacy/Old.php'))->layer()))->toBe([
        'timeouts' => ['seconds' => 60, 'most' => 120],
        'staticCheck' => ['tool' => 'none'],
    ]);
});

it('keys a file by what its entries set that affects results, and groups it by everything they set', function () use ($rules): void {
    $paths = $rules([
        ['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60], 'staticCheck' => ['before' => false]],
        ['glob' => 'src/Gen/**', 'staticCheck' => ['before' => false]],
    ]);
    $legacy = $paths->for(Path::of('src/Legacy/Old.php'));
    $generated = $paths->for(Path::of('src/Gen/Made.php'));
    $none = $paths->for(Path::of('src/Money.php'));

    expect($legacy->key())->toBe('{"timeouts":{"seconds":60}}')
        ->and($generated->key())->toBe('')
        ->and($none->key())->toBe('')
        ->and($legacy->group())->toBe('{"timeouts":{"seconds":60},"staticCheck":{"before":false}}')
        ->and($generated->group())->toBe('{"staticCheck":{"before":false}}')
        ->and($none->group())->toBe('');
});

it('makes a file\'s settings by laying its entries over the settings, and lays nothing where none matches', function (): void {
    $settings = Configs::settings(['runner' => 'pest', 'timeouts' => ['seconds' => 10, 'most' => 100], 'paths' => [
        ['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60], 'mutators' => ['except' => ['default/Plus']]],
    ]]);
    $paths = $settings->effective()->setup()->paths();
    $now = new DateTimeImmutable(Configs::NOW);
    $legacy = Settings::settled($settings->effective()->over($paths->for(Path::of('src/Legacy/Old.php'))->layer()), $now);

    expect(Configs::decoded($paths->for(Path::of('src/Money.php'))->layer()))->toBe([])
        ->and($legacy instanceof Settings ? [$legacy->triage()->limits()->limit()->seconds(), $legacy->triage()->limits()->most()->seconds()] : $legacy)
        ->toBe([60.0, 100.0])
        ->and($legacy instanceof Settings ? [...$legacy->setup()->mutators()->except()] : $legacy)->toBe(['default/Plus'])
        ->and([...$settings->setup()->mutators()->except()])->toBe([]);
});

it('follows an earlier layer\'s entries with a later one\'s, dropping one equal to an earlier entry', function () use ($rules): void {
    $earlier = $rules([['glob' => 'src/A/**', 'timeouts' => ['seconds' => 5]], ['glob' => 'src/B/**', 'timeouts' => ['seconds' => 6]]]);
    $later = $rules([['glob' => 'src/B/**', 'timeouts' => ['seconds' => 6]], ['glob' => 'src/C/**', 'timeouts' => ['seconds' => 7]]]);

    expect(Configs::decoded(Layer::of(Setup::of(paths: $earlier->over($later)))))->toBe(['paths' => [
        ['glob' => 'src/A/**', 'timeouts' => ['seconds' => 5]],
        ['glob' => 'src/B/**', 'timeouts' => ['seconds' => 6]],
        ['glob' => 'src/C/**', 'timeouts' => ['seconds' => 7]],
    ]])
        ->and($earlier->over(PathRules::none()))->toBe($earlier)
        ->and(PathRules::none()->over($later))->toBe($later)
        ->and(Configs::decoded(Layer::of(Setup::of(paths: PathRules::none()))))->toBe([])
        ->and(Configs::decoded(Layer::of(Setup::of(paths: PathRules::standard()))))->toBe(['paths' => []]);
});

it('names the globs of each entry added, removed, changed or moved since earlier entries', function () use ($rules): void {
    $then = $rules([
        ['glob' => 'src/Kept/**', 'timeouts' => ['seconds' => 5]],
        ['glob' => 'src/Changed/**', 'timeouts' => ['seconds' => 6]],
        ['glob' => 'src/Removed/**', 'timeouts' => ['seconds' => 7]],
    ]);
    $now = $rules([
        ['glob' => 'src/Kept/**', 'timeouts' => ['seconds' => 5]],
        ['glob' => 'src/Changed/**', 'timeouts' => ['seconds' => 60]],
        ['glob' => 'src/Added/**', 'timeouts' => ['seconds' => 8]],
    ]);
    $values = static function (array $globs): array {
        $written = [];

        foreach ($globs as $glob) {
            $written[] = $glob instanceof Glob ? $glob->value() : '';
        }

        return $written;
    };

    expect($values([...$now->movedSince($then)]))
        ->toBe(['src/Changed/**', 'src/Added/**', 'src/Changed/**', 'src/Removed/**'])
        ->and([...$now->movedSince($now)])->toBe([])
        ->and($values([...$now->movedSince(PathRules::none())]))->toBe(['src/Kept/**', 'src/Changed/**', 'src/Added/**']);
});

it('refuses an entry whose timeouts, laid over the settings, leave timeouts.most under timeouts.seconds, at its path', function (): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'timeouts' => ['seconds' => 10, 'most' => 100], 'paths' => [
        ['glob' => 'src/A/**', 'timeouts' => ['seconds' => 50]],
        ['glob' => 'src/B/**', 'timeouts' => ['seconds' => 200]],
    ]])))->toBe(['paths[1].timeouts.most: expected at least timeouts.seconds, 200, got 100']);
});

it('ranks a glob by its literal prefix, the characters before its first wildcard', function () use ($rules): void {
    $prefixes = array_map(
        static fn(PathRule $rule): string => $rule->prefix(),
        [...$rules([['glob' => 'src/Le?acy/**'], ['glob' => 'src/**'], ['glob' => 'src/Money.php'], ['glob' => '**/*.php']])->rules()],
    );

    expect($prefixes)->toBe(['src/Le', 'src/', 'src/Money.php', '']);
});

it('writes each entry as the PHP builder\'s PathRule::matching, its calls nested a level deeper', function () use ($rules): void {
    expect(Layer::of(Setup::of(paths: $rules([['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60]]])))->php(ProjectRoot::origin())->code())
        ->toBe(<<<'PHP'

            ->with(PathRule::matching('src/Legacy/**', Gate::configure()
                    ->with(Timeouts::seconds(60))))
        PHP);
});

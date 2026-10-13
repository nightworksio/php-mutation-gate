<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\PhpMigration;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Migration\MapValue;
use NightWorksIO\MutationGate\Core\Migration\Migrated;
use NightWorksIO\MutationGate\Core\Migration\Migration;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Move;
use NightWorksIO\MutationGate\Core\Migration\Remove;
use NightWorksIO\MutationGate\Core\Migration\Rename;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\Migration\Split;
use NightWorksIO\MutationGate\Core\Migration\SplitPart;
use NightWorksIO\MutationGate\Core\NotGiven;

/** A release that renames a method of the chain and a static call, removes one of each, changes a value, and splits one. */
function phpMigrationSteps(): Migrations
{
    return Migrations::of(Migration::in(
        '2.0.0',
        Rename::of('newCodes', 'newCode')->spelt(Spelling::replacing('Gate::newCodes', 'Gate::newCode')),
        Remove::of('legacy', 'nothing reads it')->spelt(Spelling::retiring('Gate::legacy')),
        Move::of('everywhere', 'reach.everything')->spelt(Spelling::replacing('Reach::everywhere', 'Reach::everything')),
        Move::of('limit', 'shards.max')->spelt(Spelling::replacing('Limits::shards', 'Shards::max')),
        Remove::of('reach.hotSpot', 'held code is warned of anyway')->spelt(Spelling::retiring('Reach::hotSpot')),
        MapValue::of('shards.seconds', 1, 600)->spelt(Spelling::retiring('Shards::seconds')),
        Split::of('limits', SplitPart::of('seconds', 'timeouts.seconds'))->spelt(Spelling::retiring('Timeouts::limits')),
        Rename::of('unspelt', 'spelt'),
    ));
}

function phpMigrationOf(string $code): Migrated
{
    $migrated = PhpMigration::of('mutation-gate.php', $code, phpMigrationSteps());

    return $migrated instanceof Migrated ? $migrated : throw new LogicException('Not PHP.');
}

const PHP_MIGRATION_RETIRING = <<<'PHP'
    <?php

    declare(strict_types=1);

    use NightWorksIO\MutationGate\Config\Floor;
    use NightWorksIO\MutationGate\Config\Gate;
    use NightWorksIO\MutationGate\Config\Reach;
    use NightWorksIO\MutationGate\Config\Shards;
    use NightWorksIO\MutationGate\Config\Limits;

    // The gate's config.
    return Gate::configure()
        ->newCodes(Floor::of(100)) // a floor for new code
        ->legacy()
        ->with(
            Reach::everywhere('composer.lock'), // what reaches everything
            Reach::hotSpot(0.5),
            Shards::seconds(1),
            Limits::shards(4),
        )
        ->with(Reach::hotSpot(0.4));

    PHP;

it('rewrites only the calls a release retired, keeping every comment, the layout and each class as the file names it', function (): void {
    $migrated = phpMigrationOf(PHP_MIGRATION_RETIRING);

    expect($migrated->after())->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        use NightWorksIO\MutationGate\Config\Floor;
        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Reach;
        use NightWorksIO\MutationGate\Config\Shards;
        use NightWorksIO\MutationGate\Config\Limits;

        // The gate's config.
        return Gate::configure()
            ->newCode(Floor::of(100)) // a floor for new code
            ->with(
                Reach::everything('composer.lock'), // what reaches everything
                Shards::seconds(600),
                \NightWorksIO\MutationGate\Config\Shards::max(4),
            );

        PHP)
        ->and($migrated->left())->toEqual(NotGiven::value());
});

it('leaves a call it cannot rewrite for a hand edit, at its line: a split, a value not written as a literal, and a removal outside with()', function (): void {
    $migrated = phpMigrationOf(<<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Reach;
        use NightWorksIO\MutationGate\Config\Shards;
        use NightWorksIO\MutationGate\Config\Timeouts;

        $seconds = 1;
        $spot = Reach::hotSpot(0.5);

        return Gate::configure()
            ->with(
                Timeouts::limits(5),
                Shards::seconds($seconds),
                Shards::seconds(-1),
                Shards::seconds(true),
            );
        PHP);

    expect($migrated->left())->toEqual(Invalid::because(
        Problem::at('line 9', '`reach.hotSpot` was removed (held code is warned of anyway) in 2.0.0, and migrate cannot make that change here: edit it by hand'),
        Problem::at('line 13', '`limits` became `timeouts.seconds` in 2.0.0, and migrate cannot make that change here: edit it by hand'),
        Problem::at('line 14', '`shards.seconds`: 1 became 600 in 2.0.0, and migrate cannot make that change here: edit it by hand'),
    ))
        ->and($migrated->after())->toContain('Shards::seconds(-1)')
        ->and($migrated->after())->toContain('Shards::seconds(true)');
});

it('lists, wherever a step names a spelling, each with() argument that is no builder call, since only running it says what it writes', function (): void {
    $migrated = phpMigrationOf(<<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Reach;

        $setting = Reach::everything('composer.lock');

        return Gate::configure()->with($setting, Reach::packages('packages/*'), Other::thing(), ...[]);
        PHP);
    $unknown = '`with()` is given what is no builder call, so migrate cannot tell what it writes: check it by hand';
    $quiet = PhpMigration::of('mutation-gate.php', "<?php\nreturn Gate::configure()->with(\$setting);\n", Migrations::of(Migration::in('2.0.0', Rename::of('a', 'b'))));

    expect($migrated->left())->toEqual(Invalid::because(
        Problem::at('line 8', $unknown),
        Problem::at('line 8', $unknown),
        Problem::at('line 8', $unknown),
    ))
        ->and($migrated->changes())->toBeFalse()
        ->and($quiet instanceof Migrated ? $quiet->left() : $quiet)->toEqual(NotGiven::value());
});

it('cuts a removed link that shares its line with others, and a with() its removals empty, on its own line or not', function (): void {
    $uses = "<?php\nuse NightWorksIO\\MutationGate\\Config\\Gate;\nuse NightWorksIO\\MutationGate\\Config\\Reach;\n";
    $migrated = phpMigrationOf(sprintf("%sreturn Gate::configure()->legacy()->with(Reach::hotSpot(1))->newCodes(Floor::of(9));\n", $uses));
    $shared = phpMigrationOf(sprintf(
        "%sreturn Gate::configure()->with(Reach::hotSpot(1), Reach::packages('a'))->with(Reach::packages('b'), Reach::hotSpot(2));\n",
        $uses,
    ));

    expect($migrated->after())->toBe(sprintf("%sreturn Gate::configure()->newCode(Floor::of(9));\n", $uses))
        ->and($shared->after())->toBe(sprintf("%sreturn Gate::configure()->with(Reach::packages('a'))->with(Reach::packages('b'));\n", $uses));
});

it('names the first call a release retired that a config which cannot load still makes, at its line; nothing where it makes none', function (): void {
    expect(PhpMigration::pending('mutation-gate.php', PHP_MIGRATION_RETIRING, phpMigrationSteps()))
        ->toBe('mutation-gate.php line 13: `newCodes` became `newCode` in 2.0.0: run `mutation-gate migrate`')
        ->and(PhpMigration::pending('mutation-gate.php', "<?php\nreturn Gate::configure();\n", phpMigrationSteps()))->toEqual(NotGiven::value());
});

it('cannot migrate a file that is no PHP it can parse, saying why', function (): void {
    expect(PhpMigration::of('mutation-gate.php', "<?php\nreturn Gate::configure(\n", phpMigrationSteps()))
        ->toEqual(CannotJudge::because('mutation-gate.php cannot be migrated: it is not PHP the gate can parse (Syntax error, unexpected EOF on line 3).'));
});

it('finds a call however its class and method are cased, as PHP does', function (): void {
    $migrated = phpMigrationOf(<<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Reach;

        return Gate::configure()->with(reach::Everywhere('composer.lock'));
        PHP);

    expect($migrated->after())->toContain("->with(reach::everything('composer.lock'))");
});

it('rewrites only a literal that holds the retired value, and leaves a call with an argument that is no literal for a hand edit', function (): void {
    $migrated = phpMigrationOf(<<<'PHP_WRAP'
    <?php
    
    use NightWorksIO\MutationGate\Config\Gate;
    use NightWorksIO\MutationGate\Config\Shards;
    
    return Gate::configure()
        ->with(
            Shards::seconds(false),
            Shards::seconds(1.5),
            Shards::seconds('1'),
            Shards::seconds(PHP_INT_MAX),
        );
    
    $callable = Shards::seconds(...);
    PHP_WRAP);
    $message = '`shards.seconds`: 1 became 600 in 2.0.0, and migrate cannot make that change here: edit it by hand';

    expect($migrated->left())->toEqual(Invalid::because(Problem::at('line 11', $message), Problem::at('line 14', $message)))
        ->and($migrated->after())->toContain("Shards::seconds(false),\n        Shards::seconds(1.5),\n        Shards::seconds('1'),");
});

it('cuts only the outer of two nested removals', function (): void {
    $uses = "<?php\nuse NightWorksIO\\MutationGate\\Config\\Gate;\nuse NightWorksIO\\MutationGate\\Config\\Reach;\n";
    $migrated = phpMigrationOf(sprintf("%sreturn Gate::configure()->with(Reach::hotSpot(Gate::configure()->legacy()))->newCode(1);\n", $uses));

    expect($migrated->after())->toBe(sprintf("%sreturn Gate::configure()->newCode(1);\n", $uses));
});

it('cuts a retired link and retired arguments out of a chain written on one line, whatever blanks stand around them', function (): void {
    $migrated = phpMigrationOf(<<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Reach;

        return Gate::configure()-> legacy()->with(Reach::hotSpot(0.5) , Reach::hotSpot(0.4), Reach::paths('src'));

        PHP);

    expect($migrated->after())->toBe(<<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Reach;

        return Gate::configure()->with(Reach::paths('src'));

        PHP);
});

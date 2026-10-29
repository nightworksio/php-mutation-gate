<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Hierarchy;
use NightWorksIO\MutationGate\Core\Php\Reads;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('reads each kind of value the way that kind is read, and no reference names the rest', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        class Money { const RATE = 1; public static $count = 2; public $cents = 3; }
        enum Status { case Paid; }
        function tax($rate = 4) { }
        Money::RATE;
        Money::$count;
        new Money();
        Status::Paid;
        tax();
        PHP);
    $hierarchy = Hierarchy::of($source);
    $read = static fn(Symbol $symbol): array => Php::sites(Reads::of($symbol, $source, $hierarchy));

    expect($read(Symbol::constant('App\Money', 'RATE')))->toBe(['src/A.php:6'])
        ->and($read(Symbol::property('App\Money', 'count', static: true)))->toBe(['src/A.php:7'])
        ->and($read(Symbol::property('App\Money', 'cents', static: false)))->toBe(['src/A.php:8'])
        ->and($read(Symbol::enumCase('App\Status', 'Paid')))->toBe(['src/A.php:9'])
        ->and($read(Symbol::parameter('App\tax', 'rate')))->toBe(['src/A.php:10']);
});

it('reads a trait\'s values through the class that uses it', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        trait Priced { const RATE = 1; public static $count = 2; public $cents = 3; }
        class Money { use Priced; }
        Money::RATE;
        Money::$count;
        new Money();
        PHP);
    $hierarchy = Hierarchy::of($source);
    $read = static fn(Symbol $symbol): array => Php::sites(Reads::of($symbol, $source, $hierarchy));

    expect($read(Symbol::constant('App\Priced', 'RATE')))->toBe(['src/A.php:5'])
        ->and($read(Symbol::property('App\Priced', 'count', static: true)))->toBe(['src/A.php:6'])
        ->and($read(Symbol::property('App\Priced', 'cents', static: false)))->toBe(['src/A.php:7']);
});

it('reads a global constant by its name', function (): void {
    $source = Php::source("<?php\nnamespace App;\nconst LIMIT = 1;\necho LIMIT;\n");

    expect(Php::sites(Reads::of(Symbol::globalConstant('App\LIMIT'), $source, Hierarchy::of($source))))->toBe(['src/A.php:4']);
});

it('looks for a read only in a file whose names end in a word the read must spell', function (): void {
    $holds = static fn(Symbol $symbol, string $code): bool => Reads::mayHold($symbol, Php::source($code));
    $rate = Symbol::constant('App\Money', 'RATE');
    $limit = Symbol::globalConstant('App\LIMIT');
    $paid = Symbol::enumCase('App\Status', 'Paid');

    expect([
        $holds($rate, "<?php\necho Money::RATE;"),
        $holds($rate, "<?php\necho constant(\$name);"),
        $holds($rate, "<?php\nnew ReflectionClass(\$money);"),
        $holds($rate, "<?php\necho Money::OTHER;"),
        $holds($limit, "<?php\necho \\App\\LIMIT;"),
        $holds($limit, "<?php\necho CONSTANT('App\\\\LIMIT');"),
        $holds($limit, "<?php\necho FLOOR;"),
        $holds($paid, "<?php\nuse App\\Status as S;\nS::from('p');"),
        $holds($paid, "<?php\necho Money::Paid;"),
        $holds($paid, "<?php\necho \\App\\STATUS::Paid;"),
        $holds(Symbol::property('App\Money', 'cents', static: false), "<?php\necho 1;"),
    ])->toBe([true, true, true, false, true, true, false, true, false, true, true]);
});

it('reads an attribute\'s argument wherever its owner is named, ambiguous, and looks only in a file that names it', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        use Attribute;
        #[Attribute(Attribute::TARGET_PROPERTY)]
        final class Marked { }
        $marked = (new \ReflectionProperty(Money::class, 'cents'))->getAttributes(Marked::class);
        PHP);
    $argument = Symbol::attributeArgument('App\Marked', 'Attribute');
    $read = Reads::of($argument, $source, Hierarchy::of($source));

    expect(Php::sites($read))->toBe(['src/A.php:6'])
        ->and($read->isAmbiguous())->toBeTrue()
        ->and(Reads::mayHold($argument, Php::source("<?php\necho 1;")))->toBeFalse();
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\SourceWords;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('finds the files that spell any of some words, each once, in the codebase\'s order', function (): void {
    $words = SourceWords::of([
        Php::source("<?php\necho Money::RATE;", 'src/A.php'),
        Php::source("<?php\necho constant('X');", 'src/B.php'),
        Php::source("<?php\nuse App\\Money as M;\necho M::RATE;", 'src/C.php'),
        Php::source("<?php\necho 1;", 'src/D.php'),
    ]);
    $paths = static function (array $sources): array {
        $values = [];

        foreach ($sources as $source) {
            $values[] = $source instanceof Source ? $source->path()->value() : '';
        }

        return $values;
    };

    expect($paths($words->spelling('constant', 'Money')))->toBe(['src/A.php', 'src/B.php', 'src/C.php'])
        ->and($paths($words->spelling('RATE')))->toBe(['src/A.php', 'src/C.php'])
        ->and($paths($words->spelling('money', 'M')))->toBe(['src/A.php', 'src/C.php'])
        ->and($paths($words->spelling('Nowhere')))->toBe([])
        ->and($paths($words->spelling()))->toBe([]);
});

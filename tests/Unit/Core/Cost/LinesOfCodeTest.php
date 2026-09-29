<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\LinesOfCode;
use NightWorksIO\MutationGate\Core\File\Contents;

it('counts the lines holding code, and not whitespace, comments, the opening tag or a lone brace', function (): void {
    $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        // a comment
        /** a docblock */
        final class Money
        {
            # another comment
            public function amount(): int
            {
                return 1;
            }
        }
        PHP;

    expect(LinesOfCode::in(Contents::of($source)))->toBe(4);
});

it('counts a line once however much code it holds', function (): void {
    expect(LinesOfCode::in(Contents::of("<?php\n\$a = 1; \$b = 2; \$c = 3;\n")))->toBe(1);
});

it('counts nothing in an empty file', function (): void {
    expect(LinesOfCode::in(Contents::of('')))->toBe(0);
});

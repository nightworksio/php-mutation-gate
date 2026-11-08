<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;

it('marks a comment with its run one blank line after its last, whatever lines it ended with', function (): void {
    expect(CommentWrite::of(Mode::New, 'https://ci.example/7')->marked("Body\n\n\n"))
        ->toBe("Body\n\n<!-- mutation-gate run: https://ci.example/7 -->\n")
        ->and(CommentWrite::of(Mode::Sticky, 'https://ci.example/7')->marked("Body\n\n\n"))->toBe("Body\n\n\n");
});

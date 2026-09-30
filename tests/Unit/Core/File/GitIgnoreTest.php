<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;

it('names a directory with or without its slashes, and not where no line names it alone', function (string $text, bool $named): void {
    expect(GitIgnore::of($text)->names(Path::of('.mutation-gate')))->toBe($named);
})->with([
    'with a trailing slash' => ["/vendor/\n.mutation-gate/\n", true],
    'with no slash' => ['.mutation-gate', true],
    'with both slashes and blanks' => ['  /.mutation-gate/  ', true],
    'not at all' => ["/vendor/\n", false],
    'only within another pattern' => ['.mutation-gate/cache', false],
    'nothing' => ['', false],
]);

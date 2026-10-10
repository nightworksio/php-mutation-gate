<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Hunk;
use NightWorksIO\MutationGate\Core\Composer\VendorPatch;

/** A patch of one hunk in acme/tool, by the command tool:patch. */
function vendorPatch(): VendorPatch
{
    return VendorPatch::of('tool:patch', 'acme/tool', Hunk::in('Run.php', "a();\n", sprintf("%s b.\nb();\n", VendorPatch::MARKED)));
}

it('says what it wrote, and why it could not write the files it changes', function (): void {
    expect(vendorPatch()->done(1))->toBe('tool:patch patched 1 of the 1 files it changes in tool.')
        ->and(vendorPatch()->done(0))->toBe('tool:patch found its patch already in place in the 1 files it changes in tool.')
        ->and(vendorPatch()->unwritten('/v'))
        ->toEqual(CannotJudge::because('tool:patch cannot write /v/acme/tool/src. Make the vendor directory writable.'));
});

it('marks each hunk with a comment naming its command, and finds it applied only without another gate\'s mark', function (): void {
    $patched = vendorPatch()->patched('/v', ['Run.php' => "x();\na();\n"], ['Run.php' => "x();\na();\n"], static fn(): bool => true);
    $applied = is_array($patched) ? $patched['Run.php'] : '';

    expect($applied)->toBe("x();\n// mutation-gate tool:patch: b.\nb();\n")
        ->and(vendorPatch()->isAppliedTo(['Run.php' => $applied], ['Run.php' => $applied]))->toBeTrue()
        ->and(vendorPatch()->isAppliedTo(
            ['Run.php' => $applied],
            ['Run.php' => $applied, 'Other.php' => "// mutation-gate tool:patch: old.\n"],
        ))->toBeFalse();
});

it('cannot patch where another gate\'s patch marks a file, naming the first by its path', function (): void {
    $other = "// mutation-gate tool:patch: old.\n";

    expect(vendorPatch()->patched('/v', ['Run.php' => "a();\n"], ['Run.php' => "a();\n", 'B.php' => $other, 'A.php' => $other, 'C.php' => $other], static fn(): bool => true))
        ->toEqual(CannotJudge::because('tool:patch patched nothing: /v/acme/tool/src/A.php holds another gate\'s patch. Run composer reinstall acme/tool.'));
});

it('cannot patch a file it changes that cannot be written, naming it', function (): void {
    expect(vendorPatch()->patched('/v', ['Run.php' => "a();\n"], ['Run.php' => "a();\n"], static fn(string $path): bool => $path !== '/v/acme/tool/src/Run.php'))
        ->toEqual(CannotJudge::because('tool:patch cannot write /v/acme/tool/src/Run.php. Make the vendor directory writable.'));
});

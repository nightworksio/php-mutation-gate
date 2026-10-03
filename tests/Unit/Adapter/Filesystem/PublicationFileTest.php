<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\PublicationFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** What writing a publication answered, with any warning PHP raises on the way held back. */
function publicationWritten(Publication $publication): Written|CannotJudge
{
    set_error_handler(static fn(): bool => true);
    $written = PublicationFile::written($publication);
    restore_error_handler();

    return $written;
}

it('prints a printed publication to the output', function (): void {
    ob_start();
    $written = PublicationFile::written(Publication::printed('{"shards": []}'));
    $printed = ob_get_clean();

    expect($written)->toEqual(Written::to('php://output'))
        ->and($printed)->toBe('{"shards": []}');
});

it('writes a publication as the whole of its file, making every directory it needs', function (): void {
    $file = sprintf('%s/build/.mutation-gate/pipeline.yml', Scratch::directory());
    $first = PublicationFile::written(Publication::written($file, 'first'));

    expect($first)->toEqual(Written::to($file))
        ->and(PublicationFile::written(Publication::written($file, 'second')))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe('second');
});

it('appends a publication to its file, after what the file already held', function (): void {
    $file = sprintf('%s/output', Scratch::directory());
    file_put_contents($file, "earlier=1\n");

    expect(PublicationFile::written(Publication::appended($file, "shards=[]\n")))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe("earlier=1\nshards=[]\n");
});

it('cannot judge a publication it cannot put where it says', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'file', 'a file where a directory should be');
    mkdir(sprintf('%s/taken.yml', $root));
    $unwritten = static fn(string $path): CannotJudge => CannotJudge::because(sprintf('%s could not be written.', $path));

    expect(publicationWritten(Publication::written(sprintf('%s/file/pipeline.yml', $root), 'x')))
        ->toEqual($unwritten(sprintf('%s/file/pipeline.yml', $root)))
        ->and(publicationWritten(Publication::written(sprintf('%s/taken.yml', $root), 'x')))
        ->toEqual($unwritten(sprintf('%s/taken.yml', $root)))
        ->and(publicationWritten(Publication::appended(sprintf('%s/missing/output', $root), 'x')))
        ->toEqual($unwritten(sprintf('%s/missing/output', $root)));
});

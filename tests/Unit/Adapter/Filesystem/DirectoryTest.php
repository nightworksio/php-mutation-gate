<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

/** Whether this process may take away its room to write files, and give it back. */
function roomToWriteCanBeTaken(): bool
{
    return function_exists('posix_setrlimit') && function_exists('pcntl_signal')
        && posix_getrlimit(POSIX_RLIMIT_FSIZE) === ['unlimited', 'unlimited'];
}

/**
 * What a write gives while the process may write no byte to a file, its room given back after.
 *
 * @param Closure(): (Written|CannotJudge) $write
 */
function withoutRoomToWrite(Closure $write): Written|CannotJudge
{
    pcntl_signal(SIGXFSZ, SIG_IGN);
    set_error_handler(static fn(): bool => true);
    posix_setrlimit(POSIX_RLIMIT_FSIZE, 0, POSIX_RLIMIT_INFINITY);
    $written = $write();
    posix_setrlimit(POSIX_RLIMIT_FSIZE, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);
    restore_error_handler();
    pcntl_signal(SIGXFSZ, SIG_DFL);

    return $written;
}

describe('Directory', function (): void {
    afterEach(function (): void {
        Scratch::sweep();
    });

    it('reads a file it holds', function (): void {
        $root = Scratch::directory();
        file_put_contents(sprintf('%s/plan.json', $root), '{"format": 1}');

        expect(Directory::at($root)->read(Path::of('plan.json')))->toEqual(Contents::of('{"format": 1}'));
    });

    it('names a file it does not hold', function (): void {
        $root = Scratch::directory();

        expect(Directory::at($root)->read(Path::of('plan.json')))->toEqual(Missing::at(Path::of('plan.json')));
    });

    it('cannot judge with a directory where a file should be', function (): void {
        $root = Scratch::directory();
        mkdir(sprintf('%s/plan.json', $root));

        expect(Directory::at($root)->read(Path::of('plan.json')))->toEqual(CannotJudge::because(sprintf('%s/plan.json could not be read.', $root)));
    });

    it('writes a file, creating the directories it needs, and says where', function (): void {
        $root = Scratch::directory();
        $written = Directory::at(sprintf('%s/', $root))->write(Path::of('.mutation-gate/results/1.json'), Contents::of('{"shard": 1}'));

        expect($written)->toEqual(Written::to(sprintf('%s/.mutation-gate/results/1.json', $root)))
            ->and(file_get_contents(sprintf('%s/.mutation-gate/results/1.json', $root)))->toBe('{"shard": 1}');
    });

    it('replaces what a file held', function (): void {
        $root = Scratch::directory();
        $directory = Directory::at($root);
        $directory->write(Path::of('baseline.json'), Contents::of('old'));

        expect($directory->write(Path::of('baseline.json'), Contents::of('new')))->toEqual(Written::to(sprintf('%s/baseline.json', $root)))
            ->and($directory->read(Path::of('baseline.json')))->toEqual(Contents::of('new'));
    });

    it('cannot judge writing over a directory', function (): void {
        $root = Scratch::directory();
        mkdir(sprintf('%s/report', $root));

        expect(Directory::at($root)->write(Path::of('report'), Contents::of('x')))->toEqual(CannotJudge::because(sprintf('%s/report could not be written.', $root)));
    });

    it('cannot judge a file it cannot read', function (): void {
        $root = Scratch::directory();
        Scratch::write($root, 'plan.json', '{}');
        chmod(sprintf('%s/plan.json', $root), 0o000);
        set_error_handler(static fn(): bool => true);
        $read = Directory::at($root)->read(Path::of('plan.json'));
        restore_error_handler();

        expect($read)->toEqual(CannotJudge::because(sprintf('%s/plan.json could not be read.', $root)));
    });

    it('cannot judge a file it could not write', function (): void {
        $root = Scratch::directory();
        chmod($root, 0o500);
        set_error_handler(static fn(): bool => true);
        $written = Directory::at($root)->write(Path::of('plan.json'), Contents::of('{}'));
        restore_error_handler();
        chmod($root, 0o700);

        expect($written)->toEqual(CannotJudge::because(sprintf('%s/plan.json could not be written.', $root)));
    });

    it('cannot judge a file whose directory is a file, and warns of nothing', function (): void {
        $root = Scratch::directory();
        Scratch::write($root, 'report', 'a file where a directory should be');
        $written = Directory::at($root)->write(Path::of('report/index.html'), Contents::of('<html>'));

        expect($written)->toEqual(CannotJudge::because(sprintf('%s/report/index.html could not be written.', $root)));
    });

    it('streams a file piece by piece, creating the directories it needs, and replaces what it held', function (): void {
        $root = Scratch::directory();
        $pieces = static function (): Generator {
            yield "mutant,test\r\n";
            yield "3f9a1c2b7d04,MoneyTest::fits\r\n";
        };
        Scratch::write($root, 'build/kill-matrix.csv', 'old');

        expect(Directory::at($root)->stream(Path::of('build/kill-matrix.csv'), $pieces()))->toEqual(Written::to(sprintf('%s/build/kill-matrix.csv', $root)))
            ->and(file_get_contents(sprintf('%s/build/kill-matrix.csv', $root)))->toBe("mutant,test\r\n3f9a1c2b7d04,MoneyTest::fits\r\n")
            ->and(Directory::at($root)->stream(Path::of('fresh/empty.csv'), []))->toEqual(Written::to(sprintf('%s/fresh/empty.csv', $root)))
            ->and(file_get_contents(sprintf('%s/fresh/empty.csv', $root)))->toBe('');
    });

    it('cannot judge streaming over a directory, into one it cannot write, or under a file', function (): void {
        $root = Scratch::directory();
        mkdir(sprintf('%s/report', $root));
        mkdir(sprintf('%s/locked', $root));
        Scratch::write($root, 'file', 'a file where a directory should be');
        chmod(sprintf('%s/locked', $root), 0o500);
        set_error_handler(static fn(): bool => true);
        $overDirectory = Directory::at($root)->stream(Path::of('report'), ['x']);
        $locked = Directory::at($root)->stream(Path::of('locked/matrix.csv'), ['x']);
        $underFile = Directory::at($root)->stream(Path::of('file/matrix.csv'), ['x']);
        restore_error_handler();
        chmod(sprintf('%s/locked', $root), 0o700);

        expect($overDirectory)->toEqual(CannotJudge::because(sprintf('%s/report could not be written.', $root)))
            ->and($locked)->toEqual(CannotJudge::because(sprintf('%s/locked/matrix.csv could not be written.', $root)))
            ->and($underFile)->toEqual(CannotJudge::because(sprintf('%s/file/matrix.csv could not be written.', $root)));
    });

    it('cannot judge a stream whose pieces could not all be written, and leaves the file it would replace as it was', function (): void {
        $root = Scratch::directory();
        Scratch::write($root, 'kill-matrix.csv', 'old');
        $written = withoutRoomToWrite(static fn(): Written|CannotJudge => Directory::at($root)->stream(Path::of('kill-matrix.csv'), ['mutant,test', "\r\n"]));

        expect($written)->toEqual(CannotJudge::because(sprintf('%s/kill-matrix.csv could not be written.', $root)))
            ->and(file_get_contents(sprintf('%s/kill-matrix.csv', $root)))->toBe('old')
            ->and(scandir($root))->toBe(['.', '..', 'kill-matrix.csv']);
    })->skip(fn(): bool => ! roomToWriteCanBeTaken(), 'Only a system that lets a process limit the size of the files it writes can refuse every write.');

    it('refuses a path that leads out of it, reading and writing alike', function (string $path): void {
        $root = sprintf('%s/store', Scratch::directory());
        mkdir($root);
        $refusal = CannotJudge::because(sprintf('%s leads out of %s, so the gate does not read or write it.', Path::of($path)->value(), $root));
        $directory = Directory::at($root);

        expect($directory->read(Path::of($path)))->toEqual($refusal)
            ->and($directory->write(Path::of($path), Contents::of('forged')))->toEqual($refusal)
            ->and($directory->stream(Path::of($path), ['forged']))->toEqual($refusal)
            ->and(file_exists(sprintf('%s/ledger.json', dirname($root))))->toBeFalse();
    })->with([
        'up through ..' => ['../ledger.json'],
        'up from inside' => ['refs/heads/a/../../../ledger.json'],
        'absolute' => ['/tmp/ledger.json'],
    ]);

    it('refuses a path through a link that leads elsewhere', function (): void {
        $scratch = Scratch::directory();
        mkdir(sprintf('%s/store', $scratch));
        mkdir(sprintf('%s/elsewhere', $scratch));
        symlink(sprintf('%s/elsewhere', $scratch), sprintf('%s/store/refs', $scratch));

        expect(Directory::at(sprintf('%s/store', $scratch))->write(Path::of('refs/ledger.json'), Contents::of('forged')))
            ->toBeInstanceOf(CannotJudge::class)
            ->and(file_exists(sprintf('%s/elsewhere/ledger.json', $scratch)))->toBeFalse();
    });

    it('writes through a link that stays inside it', function (): void {
        $root = Scratch::directory();
        mkdir(sprintf('%s/real', $root));
        symlink(sprintf('%s/real', $root), sprintf('%s/alias', $root));

        expect(Directory::at($root)->write(Path::of('alias/plan.json'), Contents::of('{}')))->toBeInstanceOf(Written::class)
            ->and(file_get_contents(sprintf('%s/real/plan.json', $root)))->toBe('{}');
    });

    it('writes into a directory that is not there yet', function (): void {
        $root = sprintf('%s/not/yet', Scratch::directory());

        expect(Directory::at($root)->write(Path::of('plan.json'), Contents::of('{}')))->toBeInstanceOf(Written::class);
    });

    it('says where it is', function (): void {
        $root = Scratch::directory();

        expect(Directory::at($root)->root())->toEqual(Root::of($root))
            ->and(Directory::in(Root::of($root))->root())->toEqual(Root::of($root));
    });

    it('removes a file it holds, and names one it does not hold as missing', function (): void {
        $root = Scratch::directory();
        $directory = Directory::at($root);
        $directory->write(Path::of('staticcheck/a.php'), Contents::of('<?php'));

        expect($directory->remove(Path::of('staticcheck/a.php')))->toEqual(Missing::at(Path::of('staticcheck/a.php')))
            ->and(is_file(sprintf('%s/staticcheck/a.php', $root)))->toBeFalse()
            ->and($directory->remove(Path::of('staticcheck/b.php')))->toEqual(Missing::at(Path::of('staticcheck/b.php')))
            ->and($directory->remove(Path::of('../outside.php')))->toEqual(CannotJudge::because(sprintf('../outside.php leads out of %s, so the gate does not read or write it.', $root)));
    });

    it('cannot judge removing a file from a directory it may not change', function (): void {
        $root = Scratch::directory();
        $directory = Directory::at($root);
        $directory->write(Path::of('locked/a.php'), Contents::of('<?php'));
        chmod(sprintf('%s/locked', $root), 0o555);
        $removed = $directory->remove(Path::of('locked/a.php'));
        chmod(sprintf('%s/locked', $root), 0o755);

        expect($removed)->toEqual(CannotJudge::because(sprintf('%s/locked/a.php could not be removed.', $root)));
    });

    it('reads a file up to a number of bytes, and refuses one past them without holding it whole', function (): void {
        $root = Scratch::directory();
        Scratch::write($root, 'four', 'abcd');
        $directory = Directory::at($root);

        expect($directory->readAtMost(Path::of('four'), 4))->toEqual(Contents::of('abcd'))
            ->and($directory->readAtMost(Path::of('four'), 3))
            ->toEqual(TooLarge::because(sprintf('%s/four is past 3 bytes, so it is not read.', $root)))
            ->and($directory->readAtMost(Path::of('none'), 3))->toEqual(Missing::at(Path::of('none')))
            ->and($directory->readAtMost(Path::of('../four'), 3))->toBeInstanceOf(CannotJudge::class);
    });

    it('cannot read up to a number of bytes a directory where a file should be', function (): void {
        $root = Scratch::directory();
        Scratch::write($root, 'inner/file', 'x');

        expect(Directory::at($root)->readAtMost(Path::of('inner'), 3))
            ->toEqual(CannotJudge::because(sprintf('%s/inner could not be read.', $root)));
    });

    it('holds no more than a byte past the limit of a file far larger, while it refuses it', function (): void {
        $root = Scratch::directory();
        $handle = fopen(sprintf('%s/huge', $root), 'wb');
        if ($handle !== false && ftruncate($handle, 512 * 1024 * 1024)) {
            fclose($handle);
        }
        $before = memory_get_usage();
        memory_reset_peak_usage();

        expect(Directory::at($root)->readAtMost(Path::of('huge'), 1024))->toBeInstanceOf(TooLarge::class)
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(1024 * 1024);
    });

    it('refuses to write where the path is a link, dangling or not, and leaves where it leads as it was', function (): void {
        $scratch = Scratch::directory();
        $root = sprintf('%s/store', $scratch);
        Scratch::write($root, 'kept.json', 'kept');
        symlink(sprintf('%s/planted.json', $scratch), sprintf('%s/dangling.json', $root));
        symlink(sprintf('%s/kept.json', $root), sprintf('%s/alias.json', $root));
        $directory = Directory::at($root);
        $refusal = static fn(string $file): CannotJudge => CannotJudge::because(sprintf('%s/%s is a link, so the gate does not write through it.', $root, $file));

        expect($directory->write(Path::of('dangling.json'), Contents::of('forged')))->toEqual($refusal('dangling.json'))
            ->and($directory->stream(Path::of('dangling.json'), ['forged']))->toEqual($refusal('dangling.json'))
            ->and($directory->write(Path::of('alias.json'), Contents::of('forged')))->toEqual($refusal('alias.json'))
            ->and(file_exists(sprintf('%s/planted.json', $scratch)))->toBeFalse()
            ->and(file_get_contents(sprintf('%s/kept.json', $root)))->toBe('kept');
    });

    it('writes a file whole beside its place and moves it there, open as far as the umask allows, leaving nothing else', function (): void {
        $root = Scratch::directory();
        Directory::at($root)->write(Path::of('plan.json'), Contents::of('{}'));

        expect(scandir($root))->toBe(['.', '..', 'plan.json'])
            ->and(fileperms(sprintf('%s/plan.json', $root)) & 0o777)->toBe(0o666 & ~umask());
    });

    it('leaves no file anywhere for a write into a directory it cannot write', function (): void {
        $root = Scratch::directory();
        mkdir(sprintf('%s/locked', $root), 0o500);
        $beside = static fn(): array|false => glob(sprintf('%s/.unwritable-plan.json.*', sys_get_temp_dir()));
        $before = $beside();
        set_error_handler(static fn(): bool => true);
        $written = Directory::at($root)->write(Path::of('locked/unwritable-plan.json'), Contents::of('{}'));
        restore_error_handler();
        chmod(sprintf('%s/locked', $root), 0o700);

        expect($written)->toEqual(CannotJudge::because(sprintf('%s/locked/unwritable-plan.json could not be written.', $root)))
            ->and($beside())->toBe($before)
            ->and(scandir(sprintf('%s/locked', $root)))->toBe(['.', '..']);
    });

    it('takes no piece of a stream it has made no file for in the directory it writes to', function (): void {
        $root = Scratch::directory();
        mkdir(sprintf('%s/locked', $root), 0o500);
        $taken = new ArrayObject();
        $pieces = (static function () use ($taken): Generator {
            $taken->append('first');

            yield 'x';
        })();
        set_error_handler(static fn(): bool => true);
        $written = Directory::at($root)->stream(Path::of('locked/matrix.csv'), $pieces);
        restore_error_handler();
        chmod(sprintf('%s/locked', $root), 0o700);

        expect($written)->toEqual(CannotJudge::because(sprintf('%s/locked/matrix.csv could not be written.', $root)))
            ->and($taken->getArrayCopy())->toBe([]);
    });

    it('removes a link where the path is one, dangling or not, and leaves where it leads as it was', function (): void {
        $root = Scratch::directory();
        Scratch::write($root, 'kept.php', '<?php');
        symlink(sprintf('%s/gone.php', $root), sprintf('%s/dangling.php', $root));
        symlink(sprintf('%s/kept.php', $root), sprintf('%s/alias.php', $root));
        $directory = Directory::at($root);

        expect($directory->remove(Path::of('dangling.php')))->toEqual(Missing::at(Path::of('dangling.php')))
            ->and($directory->remove(Path::of('alias.php')))->toEqual(Missing::at(Path::of('alias.php')))
            ->and(scandir($root))->toBe(['.', '..', 'kept.php']);
    });
})->group('holds:src/Adapter/Filesystem/Directory.php');

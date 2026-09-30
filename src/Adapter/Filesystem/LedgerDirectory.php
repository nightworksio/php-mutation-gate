<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use FilesystemIterator;

use function is_dir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedger;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\ProofStore;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function usort;

/**
 * The proof store `directory`: one ledger file per scope,
 * `<path>/<scope>/ledger.json.gz`, where the path is `.mutation-gate/ledger`
 * unless `with: {path: …}` names another. It is the default locally, and
 * what every CI cache keeps.
 */
final readonly class LedgerDirectory implements Configurable, ProofStore
{
    private function __construct(private Directory $directory, private string $path)
    {
    }

    public static function at(string $path): self
    {
        return new self(Directory::at($path), $path);
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = $options->text(Key::of('path'));

        return match (true) {
            $path instanceof Problem => Invalid::because($path),
            $path instanceof NotGiven => Invalid::because(
                Problem::at('path', 'expected the directory the ledgers are kept in, got nothing'),
            ),
            default => self::at($path),
        };
    }

    public function read(Scope $scope): Ledger
    {
        $file = $this->fileOf($scope);
        $contents = $file instanceof Path ? $this->directory->read($file) : $file;

        return $contents instanceof Contents ? LedgerFile::decode($contents->text()) : Ledger::empty();
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        $file = $this->fileOf($scope);
        $written = $file instanceof Path
            ? $this->directory->write($file, Contents::of(LedgerFile::encode($ledger)))
            : $file;

        return $written instanceof CannotJudge ? NotWritten::because($written->why()) : $written;
    }

    /** Every ledger kept here, one per scope, with its size as it is kept, compressed. */
    public function kept(): KeptLedgers
    {
        if (! is_dir($this->path)) {
            return KeptLedgers::of();
        }

        $kept = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $kept = $file instanceof SplFileInfo ? [...$kept, ...$this->ledgerIn($file)] : $kept;
        }

        usort(
            $kept,
            static fn(KeptLedger $one, KeptLedger $other): int => $one->file()->value() <=> $other->file()->value(),
        );

        return KeptLedgers::of(...$kept);
    }

    /**
     * The ledger a file of the directory is, where it is one.
     *
     * @return list<KeptLedger>
     */
    private function ledgerIn(SplFileInfo $file): array
    {
        $size = $file->getFilename() === LedgerFile::NAME ? $file->getSize() : false;

        return $size !== false
            ? [KeptLedger::of(Path::of($file->getPathname()), $size)]
            : [];
    }

    /** Where a scope's ledger is, for a scope that is a branch's or a pull request's ref and nothing else. */
    private function fileOf(Scope $scope): Path|CannotJudge
    {
        $parsed = Scope::parse($scope->ref());

        return $parsed instanceof Scope ? Path::of($parsed->ref())->child(Path::of(LedgerFile::NAME)) : $parsed;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function filesize;

use FilesystemIterator;

use function is_dir;
use function is_file;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedger;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\CompanionRead;
use NightWorksIO\MutationGate\Core\Proof\EncodedLedger;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerObject;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
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
    private function __construct(private ProjectPath $path, private LedgerLimits $limits)
    {
    }

    /** The ledgers under this directory: from the project and inside it, or absolute. */
    public static function at(string $path): self
    {
        return new self(ProjectPath::of($path), LedgerLimits::standard());
    }

    /** Reading and writing ledgers within these limits. */
    public function within(LedgerLimits $limits): self
    {
        return new self($this->path, $limits);
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

    /**
     * The scope's ledger; an empty one where the file is not there; or why the file could not be read, or holds
     * no ledger this gate reads.
     */
    public function read(Scope $scope): Ledger|Unreadable
    {
        $file = $this->fileOf($scope);

        if (! $file instanceof ProjectPath) {
            return Ledger::empty();
        }

        $contents = $this->path->directory()->read($file->inside());

        return match (true) {
            $contents instanceof Missing => Ledger::empty(),
            $contents instanceof CannotJudge
                => Unreadable::because(UnreadReason::Refused, $file->value(), $contents->why()),
            default => $this->decoded($contents, $file),
        };
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        $file = $this->fileOf($scope);
        $encoded = EncodedLedger::within($ledger, $this->limits);
        $written = $file instanceof ProjectPath
            ? $this->path->directory()->write($file->inside(), Contents::of($encoded->bytes()))
            : $file;

        return $written instanceof CannotJudge ? NotWritten::because($written->why()) : $encoded->written($written);
    }

    /**
     * The bytes of an object kept beside the scope's ledger; none where the
     * file is not there; or why it could not be read, or is larger than one
     * read.
     */
    public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge
    {
        $file = $this->companionFile($scope, $companion);

        if (! $file instanceof ProjectPath) {
            return Missing::at(Path::of($companion->value));
        }

        $size = is_file($file->value()) ? filesize($file->value()) : false;

        return is_int($size) && ! CompanionRead::limitsOf($companion)->admitsPacked($size)
            ? CompanionRead::unread($companion, $file->value(), CompanionRead::limitsOf($companion)->pastPacked())
            : $this->companionIn($file, $companion);
    }

    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
    {
        $file = $this->companionFile($scope, $companion);
        $written = $file instanceof ProjectPath ? $this->path->directory()->write($file->inside(), $bytes) : $file;

        return $written instanceof CannotJudge ? NotWritten::because($written->why()) : $written;
    }

    /** Every ledger kept here, one per scope, with its size as it is kept, compressed. */
    public function kept(): KeptLedgers
    {
        if (! is_dir($this->path->value())) {
            return KeptLedgers::of();
        }

        $kept = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path->value(), FilesystemIterator::SKIP_DOTS),
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
    private function fileOf(Scope $scope): ProjectPath|CannotJudge
    {
        $key = LedgerObject::under('')->of($scope);

        return is_string($key) ? $this->path->child($key) : $key;
    }

    /** Where an object beside a scope's ledger is, for a scope that is a branch's or a pull request's ref. */
    private function companionFile(Scope $scope, Companion $companion): ProjectPath|CannotJudge
    {
        $key = LedgerObject::under('')->companionOf($scope, $companion);

        return is_string($key) ? $this->path->child($key) : $key;
    }

    /** What a file beside a ledger holds; none where it is not there; or why it could not be read. */
    private function companionIn(ProjectPath $file, Companion $companion): Contents|Missing|CannotJudge
    {
        $contents = $this->path->directory()->read($file->inside());

        return $contents instanceof CannotJudge
            ? CompanionRead::unread($companion, $file->value(), $contents->why())
            : $contents;
    }

    /** The ledger a file's contents are; or why they are none this gate reads. */
    private function decoded(Contents $contents, ProjectPath $file): Ledger|Unreadable
    {
        $read = LedgerFile::read($contents->text(), $this->limits);

        return $read instanceof Ledger ? $read : Unreadable::notRead($file->value(), $read);
    }
}

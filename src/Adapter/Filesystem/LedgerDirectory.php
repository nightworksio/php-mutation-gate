<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * The proof store `directory`: one ledger file per scope,
 * `<path>/<scope>/ledger.json.gz`, where the path is `.mutation-gate/ledger`
 * unless `with: {path: …}` names another. It is the default locally, and
 * what every CI cache keeps.
 */
final readonly class LedgerDirectory implements Configurable, ProofStore
{
    /** Where the ledgers are kept unless the config says otherwise. */
    public const string PATH = '.mutation-gate/ledger';

    private function __construct(private Directory $directory)
    {
    }

    public static function at(string $path): self
    {
        return new self(Directory::at($path));
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = Node::decode($options->json())->field('path');

        try {
            return self::at($path->isPresent() ? $path->text() : self::PATH);
        } catch (NotInShape) {
            return Invalid::because(Problem::at('path', 'The directory the ledgers are kept in is a path, as text.'));
        }
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

    /** Where a scope's ledger is, for a scope that is a branch's or a pull request's ref and nothing else. */
    private function fileOf(Scope $scope): Path|CannotJudge
    {
        $parsed = Scope::parse($scope->ref());

        return $parsed instanceof Scope ? Path::of($parsed->ref())->child(Path::of(LedgerFile::NAME)) : $parsed;
    }
}

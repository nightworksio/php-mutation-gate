<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\DeclarationHeaders;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Proof\Key\SourceReach;

/**
 * A suite's name graph, read once from the files on disk: every PHP file of
 * its sources and outside its test directories, each with the files it
 * names, and each file outside the test directories as it was read, which a
 * unit's key reads the files it reaches from (ADR-0007).
 *
 * @internal
 */
final readonly class SuiteNames
{
    /**
     * @param array<string, Contents> $outside each PHP file outside the test directories, by its path
     * @param array<string, PhpFile>  $read    each of them, read
     */
    private function __construct(private NamedFiles $graph, private array $outside, private array $read)
    {
    }

    /**
     * @param array<string, Contents> $outside
     * @param array<string, PhpFile>  $read
     */
    public static function of(NamedFiles $graph, array $outside, array $read): self
    {
        return new self($graph, $outside, $read);
    }

    public function graph(): NamedFiles
    {
        return $this->graph;
    }

    /**
     * Which files outside the suite's test directories each unit's key reads
     * on its own, through the graph and what a map shows its tests ran.
     */
    public function reach(CoverageMap $map): SourceReach
    {
        $headers = array_map(
            static fn(Contents $contents): PhpFile => PhpFile::read(DeclarationHeaders::in($contents)->asPhp()),
            $this->outside,
        );

        return SourceReach::of($this->graph, NamedFiles::readAs($this->read, $headers), $map);
    }
}

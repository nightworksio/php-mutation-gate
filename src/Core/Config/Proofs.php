<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_keys;
use function array_map;
use function array_values;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Proof\Writing;

use function sprintf;

/**
 * Where proofs are kept, what they leave out, and whether the verdict writes
 * them (ADR-0007): `proofs`; and whether a run keeps the coverage map it
 * measures beside them to measure again only what moved (ADR-0023):
 * `coverage`.
 */
final readonly class Proofs implements Part
{
    /** Where the directory store keeps its ledgers. */
    private const string PATH = 'path';

    /** @param Listed<Glob>|Absent $ignore */
    private function __construct(
        private Choice|Absent $store,
        private Listed|Absent $ignore,
        private Writing|Absent $write,
        private bool|Absent $incremental,
    ) {
    }

    /** @param Listed<Glob>|Absent $ignore */
    public static function of(
        Choice|Absent $store = new Absent(),
        Listed|Absent $ignore = new Absent(),
        Writing|Absent $write = new Absent(),
        bool|Absent $incremental = new Absent(),
    ): self {
        return new self($store, $ignore, $write, $incremental);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->store(), $none->ignore(), $none->write(), $none->incrementalCoverage());
    }

    /** A store is chosen whole; the globs a later layer ignores add to an earlier one's. */
    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->store, $later->store),
                match (true) {
                    $later->ignore instanceof Absent => $this->ignore,
                    $this->ignore instanceof Absent => $later->ignore,
                    default => $this->ignore->and($later->ignore, static fn(Glob $glob): string => $glob->value()),
                },
                Absent::laid($this->write, $later->write),
                Absent::laid($this->incremental, $later->incremental),
            )
            : $this;
    }

    /** Whether the store keeps its ledgers in a directory on this machine: the `directory` store. */
    public function keptOnDisk(): bool
    {
        return $this->store()->use()->value() === BuiltinStore::Directory->value;
    }

    public function store(): Choice
    {
        return $this->store instanceof Choice
            ? $this->store
            : Builtins::stores(ProjectRoot::origin())->standard(BuiltinStore::Directory->value);
    }

    /** @return Listed<Glob> the globs of the files no test reads */
    public function ignore(): Listed
    {
        return $this->ignore instanceof Listed ? $this->ignore : Listed::of();
    }

    public function write(): Writing
    {
        return $this->write instanceof Writing ? $this->write : Writing::Auto;
    }

    /**
     * Whether a run measures again only the test files whose coverage could
     * have moved, keeping the rest from the map kept beside the ledger
     * (ADR-0023, decision 1): `coverage.incremental`, yes where nothing says.
     */
    public function incrementalCoverage(): bool
    {
        return $this->incremental instanceof Absent || $this->incremental;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::unlessEmpty(
                'proofs',
                Json::object(
                    Member::of(
                        'store',
                        $this->store instanceof Choice ? $this->storeFrom($origin)->written() : $this->store,
                    ),
                    Member::of(
                        'ignore',
                        $this->ignore instanceof Listed
                            ? Json::items(...WrittenPaths::globs($origin, $this->ignore))
                            : $this->ignore,
                    ),
                    Member::of('write', $this->write instanceof Writing ? $this->write->value : $this->write),
                ),
            ),
            Member::unlessEmpty('coverage', Json::object(Member::of('incremental', $this->incremental))),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->store instanceof Choice ? [$this->storeCall($this->storeFrom($origin))] : [],
            ...$this->ignore instanceof Listed
                ? [sprintf('Proofs::ignore(%s)', PhpCalls::literals(...WrittenPaths::globs($origin, $this->ignore)))]
                : [],
            ...$this->write instanceof Writing ? [match ($this->write) {
                Writing::Auto => 'Proofs::writing()',
                Writing::Never => 'Proofs::readOnly()',
            }] : [],
            ...$this->incremental instanceof Absent
                ? []
                : [$this->incremental ? 'Coverage::incremental()' : 'Coverage::full()'],
        ]);
    }

    /** The store chosen, with the directory store's path named from the origin. */
    private function storeFrom(PathOrigin $origin): Choice
    {
        $store = $this->store();

        return $store->use()->value() === BuiltinStore::Directory->value
            ? WrittenPaths::choice($store, $origin, self::PATH)
            : $store;
    }

    /** The proof store, by `Proofs::directory()`, `::s3()`, `::gcs()` or `::azure()` for the built-in ones. */
    private function storeCall(Choice $store): string
    {
        $options = $store->options();
        $texts = [];

        foreach ($options as $key) {
            $text = $options->text($key);
            $texts = is_string($text) ? [...$texts, $key->value() => $text] : $texts;
        }

        return match ($store->use()->value()) {
            BuiltinStore::Directory->value => sprintf(
                'Proofs::directory(%s)',
                PhpCalls::literals(...array_values($texts)),
            ),
            BuiltinStore::S3->value, BuiltinStore::Gcs->value, BuiltinStore::Azure->value => sprintf(
                'Proofs::%s(%s)',
                $store->use()->value(),
                implode(
                    ', ',
                    array_map(
                        static fn(string $option, string $value): string => sprintf(
                            '%s: %s',
                            $option,
                            PhpCalls::literal($value),
                        ),
                        array_keys($texts),
                        $texts,
                    ),
                ),
            ),
            default => PhpCalls::chosen($store, AdapterBuilder::Proofs),
        };
    }
}

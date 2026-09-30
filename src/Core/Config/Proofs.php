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

/** Where proofs are kept, what they leave out, and whether the verdict writes them (ADR-0007): `proofs`. */
final readonly class Proofs implements Part
{
    /** Where the directory store keeps its ledgers. */
    private const string PATH = 'path';

    /** @param Listed<Glob>|Absent $ignore */
    private function __construct(
        private Choice|Absent $store,
        private Listed|Absent $ignore,
        private Writing|Absent $write,
    ) {
    }

    /** @param Listed<Glob>|Absent $ignore */
    public static function of(
        Choice|Absent $store = new Absent(),
        Listed|Absent $ignore = new Absent(),
        Writing|Absent $write = new Absent(),
    ): self {
        return new self($store, $ignore, $write);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->store(), $none->ignore(), $none->write());
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
            )
            : $this;
    }

    /** Whether the store keeps its ledgers in a directory on this machine: the `directory` store. */
    public function keptOnDisk(): bool
    {
        return $this->store()->use() === self::STORE;
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

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
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
        ));
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

    /** The proof store, by `Proofs::directory()` or `Proofs::s3()` for the built-in ones. */
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
            BuiltinStore::S3->value => sprintf(
                'Proofs::s3(%s)',
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
            default => PhpCalls::chosen($store, 'Proofs'),
        };
    }
}

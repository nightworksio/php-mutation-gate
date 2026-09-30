<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_keys;
use function array_map;
use function array_values;
use function implode;

use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Definition\Enumerated;
use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Items;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/** Where proofs are kept, what they leave out, and whether the verdict writes them (ADR-0007): `proofs`. */
final readonly class Proofs implements Part
{
    /** The store when no layer names one, and where it keeps its ledgers. */
    private const string STORE = 'directory';

    private const string LEDGERS = '.mutation-gate/ledger';

    private const string S3 = 's3';

    /** @param Listed<string>|Absent $ignore */
    private function __construct(
        private Choice|Absent $store,
        private Listed|Absent $ignore,
        private ProofWriting|Absent $write,
    ) {
    }

    /** @param Listed<string>|Absent $ignore */
    public static function of(
        Choice|Absent $store = new Absent(),
        Listed|Absent $ignore = new Absent(),
        ProofWriting|Absent $write = new Absent(),
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

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $store = Field::optional('store', Adapter::choosing(Builtins::stores()), $judges);
        $ignore = Field::optional('ignore', Items::of(Text::of('a glob')), $judges);
        $write = Field::optional('write', Enumerated::of(ProofWriting::cases()), $judges);

        return [Field::section(
            'proofs',
            Section::of(
                static function (Node $proofs) use ($store, $ignore, $write): Layer|Invalid {
                    $kept = $store->read($proofs);
                    $left = $ignore->read($proofs);
                    $writing = $write->read($proofs);

                    return Reading::built(
                        static fn(): Layer => Layer::of(self::of(
                            $kept->value(),
                            $left->value(),
                            $writing->value(),
                        )),
                        $kept,
                        $left,
                        $writing,
                    );
                },
                $store,
                $ignore,
                $write,
            ),
        )];
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
                    default => $this->ignore->and($later->ignore, static fn(string $glob): string => $glob),
                },
                Absent::laid($this->write, $later->write),
            )
            : $this;
    }

    public function store(): Choice
    {
        return $this->store instanceof Choice
            ? $this->store
            : Choice::of(self::STORE, Json::object()->with('path', self::LEDGERS));
    }

    /** @return Listed<string> the globs of the files no test reads */
    public function ignore(): Listed
    {
        return $this->ignore instanceof Listed ? $this->ignore : Listed::of([]);
    }

    public function write(): ProofWriting
    {
        return $this->write instanceof ProofWriting ? $this->write : ProofWriting::Auto;
    }

    public function written(Origin $origin): Json
    {
        $proofs = $this->store instanceof Choice
            ? Json::object()->with('store', $this->store->written())
            : Json::object();
        $proofs = $this->ignore instanceof Listed
            ? $proofs->with('ignore', Json::items([...$this->ignore]))
            : $proofs;
        $proofs = $this->write instanceof ProofWriting ? $proofs->with('write', $this->write->value) : $proofs;

        return $proofs->isEmpty() ? Json::object() : Json::object()->with('proofs', $proofs);
    }

    public function php(Origin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->store instanceof Choice ? [self::storeCall($this->store)] : [],
            ...$this->ignore instanceof Listed
                ? [sprintf('Proofs::ignore(%s)', PhpCalls::literals([...$this->ignore]))]
                : [],
            ...$this->write instanceof ProofWriting ? [match ($this->write) {
                ProofWriting::Auto => 'Proofs::writing()',
                ProofWriting::Never => 'Proofs::readOnly()',
            }] : [],
        ]);
    }

    /** The proof store, by `Proofs::directory()` or `Proofs::s3()` for the built-in ones. */
    private static function storeCall(Choice $store): string
    {
        $options = Node::config($store->options()->line());
        $entries = $options->kind() === Kind::Map ? $options->entries() : [];

        return match ($store->use()) {
            self::STORE => sprintf(
                'Proofs::directory(%s)',
                PhpCalls::literals(array_map(
                    static fn(Node $option): string => $option->text(),
                    array_values($entries),
                )),
            ),
            self::S3 => sprintf(
                'Proofs::s3(%s)',
                implode(
                    ', ',
                    array_map(
                        static fn(string $option, Node $value): string => sprintf(
                            '%s: %s',
                            $option,
                            PhpCalls::literal($value->text()),
                        ),
                        array_keys($entries),
                        $entries,
                    ),
                ),
            ),
            default => $store->php('Proofs', []),
        };
    }
}

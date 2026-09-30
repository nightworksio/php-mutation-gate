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

use function sprintf;

/** Where proofs are kept, what they leave out, and whether the verdict writes them (ADR-0007): `proofs`. */
final readonly class Proofs implements Part
{
    /** The store when no layer names one, and where it keeps its ledgers. */
    private const string STORE = 'directory';


    private const string S3 = 's3';

    /** Where the directory store keeps its ledgers. */
    private const string PATH = 'path';

    /** @param Listed<Glob>|Absent $ignore */
    private function __construct(
        private Choice|Absent $store,
        private Listed|Absent $ignore,
        private ProofWriting|Absent $write,
    ) {
    }

    /** @param Listed<Glob>|Absent $ignore */
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

    public function store(): Choice
    {
        return $this->store instanceof Choice
            ? $this->store
            : Builtins::stores(ProjectRoot::origin())->standard(self::STORE);
    }

    /** @return Listed<Glob> the globs of the files no test reads */
    public function ignore(): Listed
    {
        return $this->ignore instanceof Listed ? $this->ignore : Listed::of();
    }

    public function write(): ProofWriting
    {
        return $this->write instanceof ProofWriting ? $this->write : ProofWriting::Auto;
    }

    public function written(Origin $origin): Json
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
                Member::of('write', $this->write instanceof ProofWriting ? $this->write->value : $this->write),
            ),
        ));
    }

    public function php(Origin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->store instanceof Choice ? [$this->storeCall($this->storeFrom($origin))] : [],
            ...$this->ignore instanceof Listed
                ? [sprintf('Proofs::ignore(%s)', PhpCalls::literals(...WrittenPaths::globs($origin, $this->ignore)))]
                : [],
            ...$this->write instanceof ProofWriting ? [match ($this->write) {
                ProofWriting::Auto => 'Proofs::writing()',
                ProofWriting::Never => 'Proofs::readOnly()',
            }] : [],
        ]);
    }

    /** The store chosen, with the directory store's path named from the origin. */
    private function storeFrom(Origin $origin): Choice
    {
        $store = $this->store();

        return $store->use()->value() === self::STORE ? WrittenPaths::choice($store, $origin, self::PATH) : $store;
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
            self::STORE => sprintf('Proofs::directory(%s)', PhpCalls::literals(...array_values($texts))),
            self::S3 => sprintf(
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

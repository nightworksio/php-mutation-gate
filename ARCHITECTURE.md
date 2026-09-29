# Architecture

This document is the contract. Every rule below names what enforces it, and
`tests/Arch/TheRulesAreRealTest.php` fails when a rule names nothing that
carries its identifier, or when something enforces an identifier this document
does not hold. `tests/Guards` plants a violation of every rule in a throwaway
copy of the tree and fails when the rule does not report it.

Three machines enforce what follows, and they overlap on purpose:

| Machine | Enforces | Fails at |
|---|---|---|
| PHPStan at level max, with `disallowed-calls`, ergebnis, shipmonk, type coverage, cognitive complexity and the rules in `phpstan/Rules` | what code may call, and how | static analysis |
| Pest (`tests/Arch`) | layers, shapes, names, the tests themselves | the test suite |
| `composer-dependency-analyser` | what the shipped code may depend on | the dependency check |

## The shape

```text
src/
  Core/        decides. Values and the steps that turn one into the next. No I/O.
  Port/        the interfaces the core asks. Public API.
  Config/      the typed config and its builder. Public API.
  Extension/   the Extension interface and the Extensions registry. Public API.
  Adapter/     one directory per outside thing: Pest, Infection, git, GitHub, S3.
  Cli/         the composition root and the command line.
phpstan/Rules/ this repository's own analyser rules.
tests/
  Arch/        the rules in this document that the suite enforces.
  Unit/        one test file per source file, mirroring src.
  Contract/    one directory per port: its suite, run against its fake and every adapter.
  Fakes/       a hand-written fake of every port.
  Guards/      the planted violation of every rule.
  Support/     what the Arch suite and the guards read the tree with, and what other tests share.
```

The layers are ordered: Core, Port, Config, Extension, Adapter, Cli. A layer
names only itself and the layers before it, so nothing but an adapter and the
CLI can name an adapter, and the CLI is the only place adapters are wired.

## A — layers

| Rule | Says | Enforced by |
|---|---|---|
| **A1** | Core, Port, Config and Extension name nothing outside the package but PHP and `Psr\Clock` | arch: every class name each of their files writes |
| **A2** | A port is an interface | arch: every declaration under `src/Port` |
| **A3** | An adapter names no other adapter | arch: every class name each adapter's files write |
| **A4** | A layer names only itself and the layers before it | arch: every class name each file under `src` writes |

## B — input and output

The core is handed everything it reads. An adapter or the CLI reads the world
and hands the core a value, which is what lets every decision be tested
without a repository, a runner or a CI.

| Rule | Says | Enforced by |
|---|---|---|
| **B1** | Time is read through `Psr\Clock\ClockInterface`; only an adapter or the CLI reads the system clock | phpstan `disallowed-calls`, scoped by path |
| **B2** | Nothing in `src` draws a random number | phpstan `disallowed-calls` |
| **B3** | The filesystem and the standard streams belong to adapters and the CLI | phpstan `disallowed-calls`, scoped by path |
| **B4** | Running a program belongs to adapters | phpstan `disallowed-calls`, scoped by path |
| **B5** | The network belongs to adapters | phpstan `disallowed-calls`, scoped by path |
| **B6** | The environment is read by the CLI and the adapters only | phpstan `disallowed-calls`, scoped by path |
| **B7** | Only an adapter waits | phpstan `disallowed-calls`, scoped by path |

## C — refusals and absence

| Rule | Says | Enforced by |
|---|---|---|
| **C1** | A port answers with a value or an outcome, never with nothing | arch: no port method answers `void` |
| **C2** | No `null` crosses the public API, in either direction, and no type in the core is nullable | arch: every public method of the API surface, and every method and property in the core |
| **C3** | Every thrown exception is the package's own | phpstan `disallowed-calls` on the bare exceptions' constructors |
| **C4** | No `@` suppression | phpstan: ergebnis `NoErrorSuppressionRule` |
| **C5** | `match`, never `switch`; and no `else` | phpstan: ergebnis `NoSwitchRule` + `disallowedControlStructures` |
| **C6** | No empty catch, and no `Throwable` or `Exception` caught without rethrowing | phpstan: own rule |
| **C7** | No `empty()` | phpstan: own rule |
| **C8** | No `?->` in the core | phpstan: own rule, scoped by path |
| **C9** | No nested ternary, and no `??` on an array subscript outside the tests | phpstan: own rule |

## D — types

The API surface is every class in Port, Config and Extension, and every core
type their public signatures reach (ADR-0001).

| Rule | Says | Enforced by |
|---|---|---|
| **D1** | No `array` in a public signature on the API surface | arch: every public method of the API surface |
| **D2** | A string, int or float is taken only by a named constructor on the API surface | arch: every public method of the API surface |
| **D3** | Nothing is untyped, and nothing on the API surface is `mixed` | phpstan: type coverage at 100, plus arch for `mixed` itself |
| **D4** | A closed set is an enum, not a class named for being one | arch: no class named `Status`, `State`, `Type` or `Kind` |
| **D5** | No bare `true` or `false` at a call site | phpstan: own rule |
| **D6** | No unnamed number in a method body | phpstan: own rule |
| **D7** | Every class is final, and every class on the API surface or in the core that is not an exception is readonly | arch: reflection over every class under `src` |

## H — names and size

| Rule | Says | Enforced by |
|---|---|---|
| **H1** | No `Manager`, `Helper`, `Util`, `Service`, `Data` or `Info` suffix | arch |
| **H2** | No `Interface` suffix and no `Abstract` prefix | arch |
| **H3** | A class declares at most twenty methods, and nothing is too complex to read | phpstan: own rule + `cognitive_complexity` |
| **H4** | A test file mirrors the source file it tests | arch: an orphan test fails, a class without one does not |
| **H5** | A string with a value in it is built with `sprintf`, and a message is one literal | phpstan: own rule, one per node type |
| **H6** | An exception is named for what happened, not for being an exception | arch |
| **H7** | A test is named for the behaviour it pins, never with an identifier | arch: every test description |
| **H8** | A method returns from at most three places | phpstan: own rule |
| **W1** | A file under `src` declares one class, and it is the one its path names | arch |

## K — comments

| Rule | Says | Enforced by |
|---|---|---|
| **K1** | A comment states what is true now; it tells no history and makes no promise | arch: the markers of history and promise, over every comment; review for the rest |

## P, Q, L — the language

| Rule | Says | Enforced by |
|---|---|---|
| **P1** | No `__get`, `__set`, `__isset`, `__unset`, `__call` or `__callStatic` | phpstan: own rule |
| **P2** | No variable variable and no dynamic class, method or property name | phpstan: own rule |
| **P3** | No `func_get_args()`, no `#[AllowDynamicProperties]` | phpstan `disallowed-calls` |
| **P4** | No reflection in `src` | phpstan `disallowed-calls`, scoped by path |
| **Q1** | Nothing reconfigures the runtime: `ini_set`, `setlocale`, error handlers | phpstan `disallowed-calls` |
| **Q3** | No `static::` and no `new static` | phpstan: own rule |
| **Q4** | No `echo` or `print` in `src` | phpstan: own rule |
| **L3** | Multibyte-safe string functions only | phpstan `disallowed-calls` |

## S — security

| Rule | Says | Enforced by |
|---|---|---|
| **S1** | The dangerous, execution, insecure and non-timing-safe call bundles are on | phpstan `disallowed-calls`, the four shipped bundles |
| **S2** | No package with a published advisory resolves | `roave/security-advisories` + `composer audit` in ci |

Secrets and vulnerable dependencies are scanned in CI by gitleaks and
osv-scanner, which read the repository rather than the code.

## G — tests

| Rule | Says | Enforced by |
|---|---|---|
| **G1** | No mocking library; every port has a hand-written fake | arch: over the text of every test |
| **G2** | Every port has a fake in `tests/Fakes` and one contract suite in `tests/Contract/<Port>`, run against the fake and every adapter | arch: every interface under `src/Port` |
| **G4** | No dev dependency is reachable from `src`, and every dependency is used | `composer-dependency-analyser` |
| **G5** | One assertion idiom: Pest's `expect()`, never PHPUnit's `assert*` | arch: over the text of every test |
| **G6** | No committed `->only()`, and no `->skip()` without the reason | arch: over the text of every test |
| **G7** | Every line of `src` is covered | ci: `pest --coverage --min=100` |
| **G8** | Every mutant of `src` is killed | ci: `pest --mutate --everything --min=100` |
| **G9** | A diagnostic fails the run, and no setting exempts one | arch: the settings, read out of `phpunit.xml` |

## R — the rules themselves

| Rule | Says | Enforced by |
|---|---|---|
| **R1** | Every rule here names an artifact carrying its identifier, and every identifier an artifact carries is here | test: `TheRulesAreRealTest` |
| **R2** | Every rule refuses a planted violation | test: the `Guards` suite |
| **R3** | The analyser, the refactorer and the Arch suite read the same trees | arch: `phpstan.neon` and `rector.php`, compared |

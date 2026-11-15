# Config loaders

A config loader reads one config format into a layer of config: the gate's own
read PHP, JSON, YAML and NEON
([ADR-0002](../decisions/0002-one-typed-config-from-several-formats.md)). It
implements `NightWorksIO\MutationGate\Port\ConfigLoader`, and is registered
with `Extensions::withConfigLoader()` under the file extension it reads: the
gate reads a config file that `--config` names with the loader registered for
its extension.

## What the port asks

| Method | What it answers |
|--------|-----------------|
| `load(ConfigFile)` | The file read into its layer, its paths named from the file's own directory; every problem in it at once, each at its path; or `CannotJudge`, why it could not be read |
| `reads(ConfigFile)` | The files the config reads beside itself, found without running it, each named from the project's root; none where its format reads no other file; or why it reads one that cannot be named, which makes every change reach everything ([what a change reaches](../features/reach.md)) |

A loader decodes its format into JSON and reads it with its `ConfigFile`'s `read()`,
so the gate's own definition judges every file whatever its format.

## Holding it to the contract

`ConfigLoaderContract::failures($loader, $fixtures, $extension)` reads one
fixture of each case, in the loader's format, from the directory `$fixtures`
names, each file named for its case and ending in `.<extension>`:

| Fixture | Writes |
|---------|--------|
| `valid` | `{"runner": "pest", "trees": [{"path": "src", "floor": 100}], "newCode": {"floor": 100}}` |
| `invalid` | `{"newCode": {"floor": 120}}` |
| `dated` | `{"runner": "pest", "ignores": {"entries": [{"path": "src/**", "mutator": "Plus", "reason": "Equivalent", "expires": "2027-01-31"}]}}`, the date as the format writes a date where it has its own way to |
| `up` | `{"runner": "pest", "trees": [{"path": "../src", "floor": 100}]}` |
| `adapter` | `{"proofs": {"store": {"use": "Acme\\Store", "with": {"path": "../cache"}}}}` |
| `broken` | nothing the format can read |
| `unquoted` | `{"ignores": {"entries": [{"mutant": 123456789012, "reason": "Equivalent"}]}}`, only where the format can write a mutant id as a number |

A loader keeps the contract where the valid file reads into that layer, with
its paths named from the file's directory; a date reads back as
`YYYY-MM-DD`; a path that goes up from the file's directory, and one among an
adapter's own options, is named from the project; the invalid file, and a
mutant id read as a number, read into the problems the gate finds in them; a
file that is broken or not there cannot be judged; and the valid file reads no
other file beside itself.

```php
expect([...ConfigLoaderContract::failures($loader, Path::of('tests/fixtures'), 'toml')])->toBe([]);
```

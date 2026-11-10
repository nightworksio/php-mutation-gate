# Survivors in your editor

## VS Code

```sh
vendor/bin/mutation-gate init --editor=vscode
```

The task this writes shows each survivor in the Problems list, beside its
line.

## PhpStorm

Add an External Tool (*Settings | Tools | External Tools*):

- **Program:** `vendor/bin/mutation-gate`
- **Arguments:** `run --output=problems --only=changed`
- **Working directory:** `$ProjectFileDir$`
- **Output filter** (*Advanced Options*): `$FILE_PATH$:$LINE$:$COLUMN$`

Each survivor in the Run window then links to its line.

## Any editor with a language client

```sh
vendor/bin/mutation-gate lsp
```

is a language server on standard input and output. Once the editor starts
it, it runs `watch` in the workspace's root and shows each survivor as a
warning over the line its site starts on, coded with the mutant's id; its
code actions explain the mutant with its diff, and show or write a failing
test for it. It needs the Outbreak binary the package ships for your
machine.

- `--severity=error|warning|information|hint` sets how a survivor shows.
- Every other option, and those after `--`, go to `watch`, such as
  `vendor/bin/mutation-gate lsp --config=mutation-gate.local.php`.

A survivor stays until a later round judges it otherwise; once its file
changed, it says it was judged against an earlier version of that file.

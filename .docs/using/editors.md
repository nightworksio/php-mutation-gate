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

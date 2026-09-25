# hkm-plugin-ground

> **Ground is part of the HKM kernel now.** Since kernel v1.8.0 the plugin
> test bench ships inside the kernel as `modules/ground`, namespace
> `AlfacodeTeam\Ground\`, and every plugin gets it with no extra require.
>
> This package is a **compatibility shim**. It holds no harness of its own. It
> exists so that tests written against the old `Plugins\Ground\…` names keep
> running, against the kernel's harness.

[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)

The documentation for ground itself — the API, the fakes, `ground check`,
`ground migrate`, `ground serve`, `ground dev` — lives with the code, in the
kernel: [`modules/ground/README.md`](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules/ground).

## Do you need this package?

Usually not. The kernel already aliases the old names lazily, so a test that
`extends PluginGroundTestCase` or calls `PluginGround::for(...)` works with no
require at all.

Install it when a test **type-hints** an old name before anything has loaded
it:

```php
use Plugins\Ground\Ground\PluginGround;

$this->bootGround(fn (PluginGround $g) => $g->essential(...));   // TypeError without this package
```

PHP does not autoload for a type check or an `instanceof`, so the lazy alias
has not been declared yet and the kernel's `AlfacodeTeam\Ground\PluginGround`
fails the check. This package declares every old name up front
([`aliases.php`](aliases.php)); being a dev dependency, it can afford to.

```bash
composer require --dev alfacode-team/hkm-plugin-ground
```

## Migrating off it

Import the kernel's names and drop the package:

```diff
-use Plugins\Ground\Ground\PluginGroundTestCase;
+use AlfacodeTeam\Ground\PluginGroundTestCase;

-use Plugins\Ground\Fakes\FakeQueue;
+use AlfacodeTeam\Ground\Fakes\FakeQueue;
```

The rule is the one the aliases follow: `Plugins\Ground\Ground\X` becomes
`AlfacodeTeam\Ground\X`, and every other `Plugins\Ground\…` name keeps its path
under `AlfacodeTeam\Ground\`.

## What changed in v1.0.0

| v0.1.0 | v1.0.0 |
|---|---|
| Its own copy of the harness under `Plugins\Ground\` | Every old name is an alias of the kernel's class |
| `bin/ground` | `vendor/bin/ground`, installed by the kernel, or `hkm ground` |
| `bin/link-local` | `ground install`, or the kernel's `modules/ground/bin/link-local` |
| Kernel `^1.0` | Kernel `^1.8.0` — the first release that ships ground |

v0.1.0's copy predated everything ground gained inside the kernel (`ground
migrate`, `ground init`, `ground dev`, the `.env` handling). Installed next to
a current kernel, its `Plugins\Ground\` classes loaded ahead of the kernel's
aliases, so a plugin requiring it was silently tested on the old harness.

Every class v0.1.0 shipped still resolves, and every public method it declared
exists on the kernel class with the same signature. The package's original
test suite runs unchanged against the kernel through the old names.

## License

MIT — see [LICENSE](LICENSE).

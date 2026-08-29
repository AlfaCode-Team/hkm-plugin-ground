<?php

declare(strict_types=1);

namespace Plugins\Ground\Inspection;

use Plugins\Ground\Ground\PluginManifest;

/**
 * Find installed plugins and their Provider classes.
 *
 * The Provider class is read from the `namespace` declaration in Provider.php
 * rather than derived from the directory name. Directory names vary by install
 * route — `plugins/Task/` in a project, `hkm-plugin-task/` in a development
 * checkout, `vendor/alfacode-team/hkm-plugin-task/` via composer — while the
 * namespace inside the file is the same in all three. A convention-based guess
 * would work in exactly one of them.
 */
final class PluginLocator
{
    /** Directories, relative to the root, that hold modules. */
    private const ROOTS = ['plugins', 'modules'];

    public function __construct(
        private readonly string $root,
    ) {}

    public static function fromCwd(): self
    {
        return new self(getcwd() ?: '.');
    }

    /**
     * Every plugin found, keyed by its module.json "name".
     *
     * @return array<string, PluginManifest>
     */
    public function all(): array
    {
        $found = [];

        foreach (self::ROOTS as $dir) {
            foreach (glob($this->root . '/' . $dir . '/*/module.json') ?: [] as $path) {
                $provider = $this->providerClassIn(\dirname($path));
                if ($provider === null) {
                    continue;
                }

                try {
                    $manifest = PluginManifest::fromPath($provider, $path);
                } catch (\InvalidArgumentException) {
                    // Unreadable or malformed JSON: `plugin:check` reports that
                    // for a NAMED plugin, but it must not stop a listing.
                    continue;
                }

                $found[$manifest->name()] ??= $manifest;
            }
        }

        ksort($found);

        return $found;
    }

    /** One plugin by its module.json name, or null. */
    public function find(string $name): ?PluginManifest
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * The fully-qualified Provider class declared in a plugin directory.
     *
     * Only the namespace is parsed — the class is Provider by contract, and
     * every plugin in this framework names it so.
     */
    private function providerClassIn(string $dir): ?string
    {
        $file = $dir . '/Provider.php';
        if (!is_file($file)) {
            return null;
        }

        // Read the head only: the namespace is always in the first few lines,
        // and some providers are long.
        $head = (string) file_get_contents($file, length: 4096);

        if (preg_match('/^namespace\s+([^;]+);/m', $head, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) . '\\Provider';
    }
}

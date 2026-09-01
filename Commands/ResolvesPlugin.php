<?php

declare(strict_types=1);

namespace Plugins\Ground\Commands;

use Plugins\Ground\Ground\PluginManifest;
use Plugins\Ground\Inspection\PluginLocator;

/**
 * "Which plugin do you mean?" — answered once, for every command.
 *
 * The answer is almost always "the one I am standing in", so that is the
 * default. Naming a plugin is for reaching a DIFFERENT one:
 *
 *     cd hkm-plugin-user && ground check          → user
 *     ground check view                           → view
 *
 * The four commands each resolved this themselves, with four copies of the
 * same not-found message and four chances to drift.
 */
trait ResolvesPlugin
{
    private ?PluginLocator $locator = null;

    /** The scan, honouring --path and --here. Memoized: the scan globs a workspace. */
    protected function locator(): PluginLocator
    {
        if ($this->locator !== null) {
            return $this->locator;
        }

        $path = (string) ($this->option('path') ?? '');

        return $this->locator = new PluginLocator(
            $path !== '' ? rtrim($path, '/') : (getcwd() ?: '.'),
            includeSiblings: !$this->hasOption('here'),
        );
    }

    /**
     * The plugin this invocation is about, or null after reporting why not.
     *
     * @param string $argument the argument holding an explicit name
     */
    protected function resolvePlugin(string $argument = 'name'): ?PluginManifest
    {
        $locator = $this->locator();
        $name    = trim((string) ($this->argument($argument) ?? ''));

        if ($name === '') {
            $current = $locator->current();

            if ($current !== null) {
                return $current;
            }

            $this->error('No plugin named, and this directory is not a plugin.');
            $this->muted('Either cd into a plugin, or name one: ' . implode(', ', array_keys($locator->all())));

            return null;
        }

        $found = $locator->find($name);

        if ($found !== null) {
            return $found;
        }

        $this->error("No plugin named '{$name}'.");

        // Where it looked matters more than the fact it found nothing: a plugin
        // repo one directory off looks identical to one that is not there.
        $known = array_keys($locator->all());

        if ($known !== []) {
            $this->muted('Installed: ' . implode(', ', $known));
        } else {
            $this->muted('Searched:');
            foreach ($locator->searchedPaths() as $glob) {
                $this->muted('  ' . $glob);
            }
        }

        return null;
    }
}

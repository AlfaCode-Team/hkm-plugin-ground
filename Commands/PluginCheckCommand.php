<?php

declare(strict_types=1);

namespace Plugins\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Ground\Inspection\Finding;
use Plugins\Ground\Inspection\Inspector;
use Plugins\Ground\Inspection\PluginLocator;

/**
 * plugin:check [name] — static conformance checks, without booting anything.
 *
 * With no name, checks every installed plugin. Exits 1 when any ERROR is found,
 * so it gates CI; warnings and notes report and exit 0.
 */
final class PluginCheckCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'plugin:check';
        $this->description = 'Check a plugin against the GDA rules the boot cannot catch';

        $this->addArgument('name', 'Plugin name from module.json (omit to check all)');
        $this->addOption('strict', 's', 'Treat warnings as failures too');
        $this->addOption('json', 'j', 'Machine-readable output');
    }

    protected function handle(): int
    {
        $locator = PluginLocator::fromCwd();
        $name    = (string) ($this->argument('name') ?? '');

        $targets = $name === '' ? $locator->all() : array_filter([$name => $locator->find($name)]);

        if ($targets === []) {
            $this->error(
                $name === ''
                    ? 'No plugins found under plugins/ or modules/.'
                    : "No plugin named '{$name}'. Run `plugin:check` with no argument to list what is installed.",
            );

            return self::FAILURE;
        }

        $results = [];
        foreach ($targets as $pluginName => $manifest) {
            $results[$pluginName] = (new Inspector($manifest))->run();
        }

        return $this->hasOption('json')
            ? $this->renderJson($results)
            : $this->renderHuman($results);
    }

    /** @param array<string, list<Finding>> $results */
    private function renderHuman(array $results): int
    {
        $errors = $warnings = $notes = 0;

        foreach ($results as $plugin => $findings) {
            $this->newLine();
            $this->section($plugin);

            if ($findings === []) {
                $this->success('  Clean.');

                continue;
            }

            foreach ($findings as $finding) {
                if ($finding->severity === Finding::ERROR) {
                    $this->error('  ✗ ' . $finding->message);
                    $errors++;
                } elseif ($finding->severity === Finding::WARNING) {
                    $this->warning('  ! ' . $finding->message);
                    $warnings++;
                } else {
                    $this->muted('  · ' . $finding->message);
                    $notes++;
                }

                if ($finding->fix !== '') {
                    $this->muted('      ' . $finding->fix);
                }
                $this->muted('      [' . $finding->code . ']');
            }
        }

        $this->newLine();
        $this->info(sprintf('%d error(s), %d warning(s), %d note(s).', $errors, $warnings, $notes));

        if ($errors > 0) {
            return self::FAILURE;
        }

        // --strict exists because manifest drift is not an error today and IS a
        // bug tomorrow: a Provider that disagrees with its manifest is wrong the
        // moment anyone reads the method. Teams that want that gated can gate it.
        return $warnings > 0 && $this->hasOption('strict') ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, list<Finding>> $results */
    private function renderJson(array $results): int
    {
        $payload = [];
        $errors  = 0;

        foreach ($results as $plugin => $findings) {
            $payload[$plugin] = array_map(
                static fn(Finding $f): array => [
                    'severity' => $f->severity,
                    'code'     => $f->code,
                    'message'  => $f->message,
                    'fix'      => $f->fix,
                    'file'     => $f->file,
                ],
                $findings,
            );

            $errors += \count(array_filter($findings, static fn(Finding $f): bool => $f->blocking()));
        }

        $this->info((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

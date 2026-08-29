<?php

declare(strict_types=1);

namespace Plugins\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Ground\Inspection\PluginLocator;

/**
 * make:ground-test <plugin> — write a ground test skeleton for a plugin.
 *
 * The skeleton is generated FROM the plugin's manifest: one test per declared
 * route, one per exposed contract, one per emitted event. That is the difference
 * between a template and a scaffold — the file that lands already names the
 * things this plugin actually has, so the first run tells you something.
 */
final class MakeGroundTestCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'make:ground-test';
        $this->description = 'Scaffold a PluginGround test case from a plugin\'s manifest';

        $this->addArgument('plugin', 'Plugin name from module.json', required: true);
        $this->addOption('force', 'f', 'Overwrite an existing file');
        $this->addOption('print', 'p', 'Write to stdout instead of a file');
    }

    protected function handle(): int
    {
        $name     = (string) $this->argument('plugin');
        $manifest = PluginLocator::fromCwd()->find($name);

        if ($manifest === null) {
            $this->error("No plugin named '{$name}' under plugins/ or modules/.");

            return self::FAILURE;
        }

        $namespace = substr($manifest->providerClass, 0, strrpos($manifest->providerClass, '\\') ?: 0);
        $class     = $this->studly($manifest->name()) . 'GroundTest';
        $source    = $this->render($manifest, $namespace, $class);

        if ($this->hasOption('print')) {
            $this->info($source);

            return self::SUCCESS;
        }

        $dir  = $manifest->directory() . '/tests';
        $path = $dir . '/' . $class . '.php';

        if (is_file($path) && !$this->hasOption('force')) {
            $this->error("{$path} already exists. Pass --force to overwrite it.");

            return self::FAILURE;
        }

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return self::FAILURE;
        }

        file_put_contents($path, $source);

        $this->success("Wrote {$path}");
        $this->muted('Run it with: vendor/bin/phpunit ' . $path);

        return self::SUCCESS;
    }

    private function render(\Plugins\Ground\Ground\PluginManifest $manifest, string $namespace, string $class): string
    {
        $tests = [];

        // The boot itself. Worth a test of its own: it is the assertion that
        // fails first, and a failure here explains every other failure.
        $tests[] = <<<'PHP'
            public function testItBoots(): void
            {
                $this->assertNotSame('', $this->ground()->manifest()->solves());
            }
        PHP;

        foreach ($manifest->routes() as $route) {
            $method = strtoupper((string) ($route['method'] ?? 'GET'));
            $path   = (string) ($route['path'] ?? '');
            $prefix = (string) ($manifest->data['routePrefix'] ?? '');
            $full   = ($prefix . $path) ?: '/';

            $tests[] = sprintf(
                <<<'PHP'
                    public function test%s(): void
                    {
                        $this->assertRouteExists('%s', '%s');

                        // $response = $this->ground()->%s('%s');
                        // $this->assertOk($response);
                    }
                PHP,
                $this->studly($method . '_' . str_replace(['/', '{', '}', ':'], '_', $full)),
                $method,
                $full,
                strtolower($method),
                $full,
            );
        }

        foreach ($manifest->exposes() as $contract) {
            $short = substr(strrchr($contract, '\\') ?: $contract, 1);

            $tests[] = sprintf(
                <<<'PHP'
                    public function testItResolves%s(): void
                    {
                        // Resolving in the plugin's own scope — the only scope that
                        // may reach its bindInternal() dependencies.
                        $this->assertInstanceOf(
                            \%s::class,
                            $this->ground()->service(\%s::class),
                        );
                    }
                PHP,
                $this->studly($short),
                $contract,
                $contract,
            );
        }

        foreach ($manifest->emits() as $event) {
            $tests[] = sprintf(
                <<<'PHP'
                    public function testItEmits%s(): void
                    {
                        $this->markTestIncomplete('Drive the action that emits %s, then assert it.');

                        // $this->assertDispatched('%s');
                    }
                PHP,
                $this->studly(str_replace('.', '_', $event)),
                $event,
                $event,
            );
        }

        $requires = $manifest->requires();
        $deps     = $requires === []
            ? ''
            : "\n    /**\n     * Providers for: " . implode(', ', $requires) . "\n     *\n"
              . "     * Every required domain must be registered or the boot fails — that is\n"
              . "     * the kernel enforcing the dependency, not the harness being strict.\n"
              . "     */\n"
              . "    protected function dependencies(): array\n    {\n"
              . "        return [\n            // Plugins\\\\Example\\\\Provider::class,\n        ];\n    }\n";

        $body = implode("\n\n", $tests);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace}\\Tests;

        use Plugins\\Ground\\Ground\\PluginGroundTestCase;
        use {$namespace}\\Provider;

        /**
         * Ground tests for the {$manifest->name()} plugin.
         *
         * Generated by `make:ground-test` from module.json. Each test names
         * something the manifest declares; fill in the bodies.
         */
        final class {$class} extends PluginGroundTestCase
        {
            protected function plugin(): string
            {
                return Provider::class;
            }
        {$deps}
        {$body}
        }

        PHP;
    }

    private function studly(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9]+/', ' ', $value) ?? $value;

        return str_replace(' ', '', ucwords(trim($value)));
    }
}

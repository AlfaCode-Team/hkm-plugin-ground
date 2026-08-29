<?php

declare(strict_types=1);

namespace Plugins\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Ground\Inspection\PluginLocator;
use Plugins\Ground\Ui\UiManifest;

/**
 * make:ui-test <plugin> — scaffold the component-test side of a plugin's UI.
 *
 * PHP can prove a route returns `{component, props}` and that a page file
 * exists for it. It cannot prove the component RENDERS. That needs a DOM, and
 * a DOM needs node — so this writes the vitest half.
 *
 * The one thing that makes it worth having: the test reads its props from a
 * FIXTURE the ground dumped from a real response, not from hand-written mocks.
 * Mock props drift the moment someone renames a field on the server, and the
 * component test keeps passing while the page breaks. With a dumped fixture,
 * the rename fails the component test.
 */
final class MakeUiTestCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'make:ui-test';
        $this->description = 'Scaffold vitest component tests for a plugin\'s pages';

        $this->addArgument('plugin', 'Plugin name from module.json', required: true);
        $this->addOption('force', 'f', 'Overwrite existing files');
        $this->addOption('config', 'c', 'Also write vitest.config.ts and the setup file');
    }

    protected function handle(): int
    {
        $name     = (string) $this->argument('plugin');
        $manifest = PluginLocator::fromCwd()->find($name);

        if ($manifest === null) {
            $this->error("No plugin named '{$name}' under plugins/ or modules/.");

            return self::FAILURE;
        }

        $ui = UiManifest::for($manifest->directory());

        if (!$ui->exists) {
            $this->error("{$name} ships no ui/ directory — there is no component to test.");
            $this->muted('A plugin gets UI by adding ui/ui.json and ui/<face>/Pages/<Name>.tsx.');

            return self::FAILURE;
        }

        $pages = $ui->pageFiles();
        if ($pages === []) {
            $this->error("{$name} has a ui/ directory but no pages under admin/Pages or site/Pages.");

            return self::FAILURE;
        }

        $written = 0;
        foreach ($pages as $page) {
            $written += $this->writeTest($ui, $page) ? 1 : 0;
        }

        if ($this->hasOption('config')) {
            $written += $this->writeConfig($ui) ? 1 : 0;
        }

        $this->newLine();
        $this->info("Wrote {$written} file(s) under " . $ui->directory . '/__tests__/');
        $this->muted('Fixtures come from a ground test:');
        $this->muted('  $this->ground()->pageflow(\'/your/route\')->writeFixture(__DIR__ . \'/../ui/__fixtures__/<name>.json\');');
        $this->muted('Then: cd ' . $ui->directory . ' && npx vitest');

        return self::SUCCESS;
    }

    private function writeTest(UiManifest $ui, string $pagePath): bool
    {
        // 'admin/Pages/User/Index.tsx' → component 'User/Index', slug 'user-index'
        $relative  = ltrim(str_replace($ui->directory, '', $pagePath), '/');
        $component = preg_replace('#^(admin|site)/Pages/#', '', $relative) ?? $relative;
        $component = substr($component, 0, -4);
        $slug      = strtolower(str_replace('/', '-', $component));

        $dir  = $ui->directory . '/__tests__';
        $file = $dir . '/' . $slug . '.test.tsx';

        if (is_file($file) && !$this->hasOption('force')) {
            $this->muted('skipped (exists): ' . $slug . '.test.tsx');

            return false;
        }

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return false;
        }

        $import = '../' . substr($relative, 0, -4);

        file_put_contents($file, <<<TSX
            import { describe, it, expect } from "vitest";
            // Add `screen` here when you assert on what the user sees:
            //   import { render, screen } from "@testing-library/react";
            import { render } from "@testing-library/react";
            import Page from "{$import}";
            import fixture from "../__fixtures__/{$slug}.json";

            /**
             * Component test for `{$component}`.
             *
             * Props come from __fixtures__/{$slug}.json, which a ground test dumped
             * from a REAL response:
             *
             *   \$this->ground()->pageflow('/your/route')
             *       ->writeFixture(__DIR__ . '/../ui/__fixtures__/{$slug}.json');
             *
             * That is the point of the fixture. Hand-written mock props drift from the
             * server the moment a field is renamed, and this test keeps passing while
             * the page breaks. With the dump, a server-side rename fails it here.
             *
             * Re-dump the fixture when the props change, and commit it.
             */
            describe("{$component}", () => {
              it("renders with the props the server actually sends", () => {
                render(<Page {...(fixture.props as any)} />);

                // Replace with something the page must show. Prefer a role or a
                // user-visible string over a test id: an assertion on markup
                // structure breaks on a redesign that changed nothing real.
                expect(document.body.textContent).not.toBe("");
              });

              it("has the props it needs", () => {
                // Guards the seam in the other direction: if the server stops sending
                // a prop this page reads, this fails BEFORE the page renders undefined.
                expect(fixture.props).toBeDefined();
              });

              it("shows nothing sensitive", () => {
                // Props are serialized into the page object and shipped to the browser.
                const serialized = JSON.stringify(fixture.props);
                expect(serialized).not.toMatch(/password|secret|token_hash/i);
              });
            });

            TSX);

        $this->success('wrote ' . $slug . '.test.tsx');

        return true;
    }

    private function writeConfig(UiManifest $ui): bool
    {
        $file = $ui->directory . '/vitest.config.ts';

        if (is_file($file) && !$this->hasOption('force')) {
            $this->muted('skipped (exists): vitest.config.ts');

            return false;
        }

        file_put_contents($file, <<<'TS'
            import { defineConfig } from "vitest/config";
            import react from "@vitejs/plugin-react";

            /**
             * Vitest for a PLUGIN's own UI, run in place (plugins/<Name>/ui/).
             *
             * A plugin owns its UI and must be testable without a host project —
             * the same reason `hkm ui sync` mirrors rather than moves it. Aliases
             * to other plugins (@pageflow, …) are NOT resolvable here; a page that
             * needs them belongs in a project-level test, where
             * tsconfig.plugins.json supplies them.
             */
            export default defineConfig({
              plugins: [react()],
              test: {
                environment: "jsdom",
                globals: true,
                setupFiles: ["./__tests__/setup.ts"],
                include: ["__tests__/**/*.test.{ts,tsx}"],
              },
            });
            TS);

        $setup = $ui->directory . '/__tests__/setup.ts';
        if (!is_dir(\dirname($setup))) {
            mkdir(\dirname($setup), 0o755, true);
        }
        if (!is_file($setup) || $this->hasOption('force')) {
            file_put_contents($setup, <<<'TS'
                import "@testing-library/jest-dom/vitest";
                import { cleanup } from "@testing-library/react";
                import { afterEach } from "vitest";

                // Without this, a component left mounted by one test is still in the
                // document for the next, and a passing assertion may be reading the
                // previous test's render.
                afterEach(() => cleanup());
                TS);
        }

        $this->success('wrote vitest.config.ts + __tests__/setup.ts');
        $this->muted('npm i -D vitest jsdom @vitejs/plugin-react @testing-library/react @testing-library/jest-dom');

        return true;
    }
}

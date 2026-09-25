<?php

declare(strict_types=1);

/**
 * Declare every name this package used to ship, up front.
 *
 * Ground moved into the kernel (`modules/ground`, namespace
 * `AlfacodeTeam\Ground\`) in kernel v1.8.0. The kernel keeps the old
 * `Plugins\Ground\…` names working with a LAZY alias: an autoloader that calls
 * class_alias() the first time something asks for an old name.
 *
 * Lazy is right for the kernel, which must not load PHPUnit-dependent classes
 * into production. It is not enough for tests written against this package,
 * because PHP does not autoload for a type check. `fn (PluginGround $g) => …`
 * and `$x instanceof PluginGround` compare against a class NAME, and until that
 * name has been autoloaded once it does not exist — so the kernel's object
 * fails the check with "must be of type Plugins\Ground\Ground\PluginGround,
 * AlfacodeTeam\Ground\PluginGround given".
 *
 * This package is a dev dependency, so it can afford to declare all of them
 * eagerly. Each is a real alias of the kernel class: a test extending or
 * type-hinting an old name is using the kernel's harness, not a copy of it.
 *
 * The list is exactly what v0.1.0 shipped. Classes the kernel added later
 * (Database, Init, …) never had an old name and are imported from
 * `AlfacodeTeam\Ground\` directly.
 */
(static function (): void {
    $names = [
        'Commands\\MakeGroundTestCommand',
        'Commands\\MakeUiTestCommand',
        'Commands\\PluginCheckCommand',
        'Commands\\PluginProbeCommand',
        'Commands\\PluginServeCommand',
        'Commands\\ResolvesPlugin',
        'Fakes\\FakeCache',
        'Fakes\\FakeDatabase',
        'Fakes\\FakeEncrypter',
        'Fakes\\FakeHasher',
        'Fakes\\FakeLock',
        'Fakes\\FakeLogger',
        'Fakes\\FakeMail',
        'Fakes\\FakeQueue',
        'Fakes\\FakeSms',
        'Fakes\\FakeStorage',
        'Fakes\\FrozenClock',
        'Fakes\\OpenGateLayer',
        'Fakes\\StubFilterStage',
        'Ground\\BootedGround',
        'Ground\\CliResult',
        'Ground\\EventRecorder',
        'Ground\\GroundResponse',
        'Ground\\PageflowResponse',
        'Ground\\PluginGround',
        'Ground\\PluginGroundTestCase',
        'Ground\\PluginManifest',
        'Ground\\Workspace',
        'Inspection\\Finding',
        'Inspection\\Inspector',
        'Inspection\\PluginLocator',
        'Inspection\\UiInspector',
        'Provider',
        'Ui\\PageResolver',
        'Ui\\UiManifest',
        'Ui\\UiWorkspace',
    ];

    foreach ($names as $name) {
        $old = 'Plugins\\Ground\\' . $name;

        // `Plugins\Ground\Ground\X` → `AlfacodeTeam\Ground\X`: the doubled
        // segment existed only because the package root was the namespace root.
        $target = 'AlfacodeTeam\\Ground\\' . (str_starts_with($name, 'Ground\\') ? substr($name, 7) : $name);

        if (class_exists($old, false) || interface_exists($old, false) || trait_exists($old, false)) {
            continue;
        }

        try {
            $exists = class_exists($target) || interface_exists($target) || trait_exists($target);
        } catch (\Throwable) {
            // A kernel too old to ship this class, or a parent (PHPUnit's
            // TestCase) that is not installed. Leave the name undeclared — the
            // caller gets a plain "class not found" naming the old class.
            continue;
        }

        if ($exists) {
            class_alias($target, $old);
        }
    }
})();

<?php

declare(strict_types=1);

namespace Plugins\Ground\Tests;

use AlfacodeTeam\Ground\PluginGround as KernelPluginGround;
use Plugins\Ground\Ground\PluginGround;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * This package no longer ships a harness of its own: every old name must be
 * the KERNEL's class, declared before anything asks for it.
 *
 * The second test is the reason `aliases.php` exists. The kernel aliases old
 * names lazily, and PHP does not autoload for a type check, so a closure typed
 * with an old name rejected the kernel's object until something else happened
 * to autoload that name first.
 */
final class CompatibilityTest extends TestCase
{
    public function testEveryOldNameIsTheKernelsClass(): void
    {
        $kernelDir = \dirname((string) (new \ReflectionClass(KernelPluginGround::class))->getFileName());

        foreach (['Ground\\PluginGround', 'Ground\\PluginGroundTestCase', 'Fakes\\FakeDatabase', 'Provider'] as $name) {
            $class = new \ReflectionClass('Plugins\\Ground\\' . $name);

            self::assertStringStartsWith('AlfacodeTeam\\Ground\\', $class->getName(), $name);
            self::assertStringStartsWith($kernelDir, (string) $class->getFileName(), $name);
        }
    }

    // Its own process: any earlier test that touched the old name would have
    // autoloaded it, and the check would pass without aliases.php.
    #[RunInSeparateProcess]
    public function testAnOldNameIsDeclaredBeforeAnythingAutoloadsIt(): void
    {
        $typed = static fn (PluginGround $ground): string => $ground::class;

        $ground = (new \ReflectionClass(KernelPluginGround::class))->newInstanceWithoutConstructor();

        self::assertTrue(class_exists(PluginGround::class, false));
        self::assertSame(KernelPluginGround::class, $typed($ground));
    }
}

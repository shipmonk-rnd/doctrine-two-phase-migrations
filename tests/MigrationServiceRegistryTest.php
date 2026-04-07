<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use LogicException;
use PHPUnit\Framework\TestCase;

class MigrationServiceRegistryTest extends TestCase
{

    public function testSingleNamespaceWithoutExplicitName(): void
    {
        $service = $this->createMock(MigrationService::class);
        $registry = new MigrationServiceRegistry(['default' => $service]);

        self::assertSame($service, $registry->get());
        self::assertSame($service, $registry->get(null));
        self::assertSame($service, $registry->get('default'));
        self::assertTrue($registry->isSingleNamespace());
        self::assertSame(['default'], $registry->getNames());
    }

    public function testMultipleNamespacesRequiresExplicitName(): void
    {
        $service1 = $this->createMock(MigrationService::class);
        $service2 = $this->createMock(MigrationService::class);
        $registry = new MigrationServiceRegistry(['default' => $service1, 'analytics' => $service2]);

        self::assertSame($service1, $registry->get('default'));
        self::assertSame($service2, $registry->get('analytics'));
        self::assertFalse($registry->isSingleNamespace());
        self::assertSame(['default', 'analytics'], $registry->getNames());
    }

    public function testMultipleNamespacesThrowsWithoutName(): void
    {
        $registry = new MigrationServiceRegistry([
            'default' => $this->createMock(MigrationService::class),
            'analytics' => $this->createMock(MigrationService::class),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Multiple migration namespaces are registered');
        $this->expectExceptionMessage('default, analytics');

        $registry->get();
    }

    public function testUnknownNamespace(): void
    {
        $registry = new MigrationServiceRegistry([
            'default' => $this->createMock(MigrationService::class),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Migration namespace 'unknown' is not registered");

        $registry->get('unknown');
    }

    public function testEmptyRegistryThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('At least one migration service must be registered');

        new MigrationServiceRegistry([]);
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\WithEntityManagerTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use const PHP_EOL;

class MigrationCheckCommandTest extends TestCase
{

    use WithEntityManagerTestCase;

    public function testCheck(): void
    {
        $diffSql = 'SELECT 1';

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::exactly(2))
            ->method('getExecutedVersions')
            ->willReturnOnConsecutiveCalls(['fakeversion'], []);

        $migrationService->expects(self::exactly(2))
            ->method('getPreparedVersions')
            ->willReturn(['fakeversion']);

        $migrationService->expects(self::once())
            ->method('generateDiffSqls')
            ->willReturn([$diffSql]);

        $output = new BufferedOutput();
        $command = new MigrationCheckCommand($migrationService);
        $command->run(new ArrayInput([]), $output);

        self::assertSame(
            'Phase before fully executed, no awaiting migrations' . PHP_EOL
            . 'Phase after not fully executed, awaiting migrations:' . PHP_EOL
            . ' > fakeversion' . PHP_EOL
            . 'Database is not synced with entities, missing updates:' . PHP_EOL
            . ' > ' . $diffSql . PHP_EOL,
            $output->fetch(),
        );
    }

}

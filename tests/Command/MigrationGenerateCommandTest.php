<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use PHPUnit\Framework\TestCase;
use ShipMonk\Doctrine\Migration\MigrationFile;
use ShipMonk\Doctrine\Migration\MigrationService;
use ShipMonk\Doctrine\Migration\WithEntityManagerTest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationGenerateCommandTest extends TestCase
{

    use WithEntityManagerTest;

    public function testCheck(): void
    {
        $diffSql = 'SELECT 1';

        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::once())
            ->method('generateDiffSqls')
            ->willReturn([$diffSql]);

        $migrationService->expects(self::once())
            ->method('generateMigrationFile')
            ->with([$diffSql])
            ->willReturn(new MigrationFile('fakepath', 'fakeversion'));

        $output = new BufferedOutput();
        $command = new MigrationGenerateCommand($migrationService);
        $command->run(new ArrayInput([]), $output);

        self::assertSame("Migration version fakeversion was generated\n", $output->fetch());
    }

}

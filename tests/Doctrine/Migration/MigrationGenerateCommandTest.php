<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrationGenerateCommandTest extends TestCase
{

    use WithEntityManagerTest;

    public function testCheck(): void
    {
        $migrationService = $this->createMock(MigrationService::class);
        $migrationService->expects(self::once())
            ->method('shouldIncludeDropTableInDatabaseSync')
            ->willReturn(false);

        $migrationService->expects(self::once())
            ->method('generateMigrationFile')
            ->with([
                'CREATE TABLE entity (id VARCHAR(255) NOT NULL, PRIMARY KEY(id))',
            ])
            ->willReturn(new MigrationFile('fakepath', 'fakeversion'));

        $output = new BufferedOutput();
        $command = new MigrationGenerateCommand($this->getEntityManager(), $migrationService);
        $command->run(new ArrayInput([]), $output);

        self::assertSame("Migration version fakeversion was generated\n", $output->fetch());
    }

}

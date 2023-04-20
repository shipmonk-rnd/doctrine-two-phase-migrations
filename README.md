## Two-phase migrations for Doctrine
This lightweight library allows you to perform safer Doctrine migrations during deployment in cluster-based environments like kubernetes where rolling-update takes place.
Each migration has two *up* phases, no *down* phase.

- **before**
  - to be called before any traffic hits the new application version
  - typically contains ADD COLUMN etc.
- **after**
  - to be called after the deployment is done and no traffic is hitting the old application version
  - typically contains DROP COLUMN etc.

You can see [Czech talk about this library on YouTube](https://youtu.be/7OVO8itXUt0?t=3380).

### Installation:

```sh
composer require shipmonk/doctrine-two-phase-migrations
```

### Configuration in symfony application:

If your `Doctrine\ORM\EntityManagerInterface` is autowired, just register few services in your DIC and tag the commands:
```yml
_instanceof:
    Symfony\Component\Console\Command\Command:
        tags:
            - console.command

services:
    ShipMonk\Doctrine\Migration\Command\MigrationInitCommand:
    ShipMonk\Doctrine\Migration\Command\MigrationRunCommand:
    ShipMonk\Doctrine\Migration\Command\MigrationSkipCommand:
    ShipMonk\Doctrine\Migration\Command\MigrationCheckCommand:
    ShipMonk\Doctrine\Migration\Command\MigrationGenerateCommand:
    ShipMonk\Doctrine\Migration\MigrationService:
    ShipMonk\Doctrine\Migration\MigrationConfig:
        $migrationsDir: "%kernel.project_dir%/migrations"

    # more optional parameters:
        $migrationClassNamespace: 'YourCompany\Migrations'
        $migrationTableName: 'doctrine_migration'
        $migrationClassPrefix: 'Migration' # will be appended with date('YmDHis') by default
        $excludedTables: ['my_tmp_table'] # migration table ($migrationTableName) is always added to excluded tables automatically
        $templateFilePath: "%kernel.project_dir%/migrations/my-template.txt" # customizable according to your coding style
        $templateIndent: "\t\t" # defaults to spaces
```

### Commands:

#### Initialization:

After installation, you need to create `migration` table in your database. It is safe to run it even when the table was already initialized.

```bash
$ bin/console migration:init

# example output:
Creating migration table... done.
```

#### Generating new migration:

You can generate migration from database <=> entity diff automatically.
This puts all the queries generated by Doctrine to before stage, which will NOT be correct for any destructive actions.
Be sure to verify the migration and move the queries to proper stage or adjust them.
When no diff is detected, empty migration class is generated.

```bash
$ bin/console migration:generate

# example output:
Migration version 20230217063818 was generated
```

The generated file then looks like this:
```php
<?php declare(strict_types = 1);

namespace App\Migrations;

use ShipMonk\Doctrine\Migration\Migration;
use ShipMonk\Doctrine\Migration\MigrationExecutor;

class Migration20230217063818 implements Migration
{

    public function before(MigrationExecutor $executor): void
    {
        $executor->executeQuery('CREATE INDEX IDX_542819F35080ECDE ON my_table (my_column)');
    }

    public function after(MigrationExecutor $executor): void
    {
    }

}
```

You can adjust it by providing custom `$templateFilePath` to `MigrationConfig`, but it needs to implement `Migration` interface.

#### Status verification:

You can check awaiting migrations and entity sync status:

```bash
$ bin/console migration:check

# example success output:
Phase before fully executed, no awaiting migrations
Phase after fully executed, no awaiting migrations
Database is synced with entities, no migration needed.
```

```bash
$ bin/console migration:check

# example failure output:
Phase before fully executed, no awaiting migrations
Phase after has executed migrations not present in /app/migrations: 20220208123456
Database is not synced with entities, missing updates:
 > DROP INDEX IDX_9DA1A2026EA0B6CA ON my_table
```

#### Skipping all migrations:

You can also mark all migrations as already executed, e.g. when you just created fresh schema from entities.
This will mark all not executed migrations in all stages as migrated.

```bash
$ bin/console migration:skip

# example output:
Migration 20230214154154 phase after skipped.
Migration 20230214155401 phase after skipped.
Migration 20230215050511 phase after skipped.
Migration 20230217061357 phase after skipped.
```

#### Executing migration:

Execution is performed without any interaction and does not fail nor warn when no migration is present for execution.

```bash
$ bin/console migration:run before

# example output:
Executing migration 20220224045126 phase before... done, 0.032 s elapsed.
Executing migration 20220224081809 phase before... done, 0.019 s elapsed.
Executing migration 20220224114846 phase before... done, 0.015 s elapsed.

$ bin/console migration:run after

# example output:
Executing migration 20220224045126 phase after... done, 0.033 s elapsed.
Executing migration 20220224081809 phase after... done, 0.006 s elapsed.
Executing migration 20220224114846 phase after... done, 0.000 s elapsed.
```

When executing all the migrations (e.g. in test environment) you probably want to achieve one-by-one execution. You can do that by:

```bash
$ bin/console migration:run both

# example output:
Executing migration 20220224045126 phase before... done, 0.032 s elapsed.
Executing migration 20220224045126 phase after... done, 0.033 s elapsed.
Executing migration 20220224081809 phase before... done, 0.019 s elapsed.
Executing migration 20220224081809 phase after... done, 0.006 s elapsed.
```

### Advanced usage

#### Run custom code for each executed query:

You can hook into migration execution by implementing `MigrationExecutor` interface and registering your implementations as a service.
Implement `executeQuery()` to run checks or other code before/after each query.
Interface of this method mimics interface of `Doctrine\DBAL\Connection::executeQuery()`.

#### Run all queries within transaction:

You can change your template (or a single migration) to extend; `TransactionalMigration`.
That causes each phases to be executed within migration.
Be aware that many databases (like MySQL) does not support transaction over DDL operations (ALTER and such).

#### Checking execution duration:

Migration table has `started_at` and `finished_at` columns with datetime data with microseconds.
But those columns are declared as VARCHARs by default, because there is [no microsecond support in doctrine/dbal](https://github.com/doctrine/dbal/issues/2873) yet.
That may complicate datetime manipulations (like duration calculation).
You can adjust the structure to your needs (e.g. use `DATETIME(6)` for MySQL) manually in some migration.

```
+----------------+--------+-----------------------------+---------------------------+
| version        | phase  | started_at                  | finished_at               |
+----------------+--------+-----------------------------+---------------------------+
| 20220224045126 | before | 2023-02-17 05:19:50.225048 | 2023-02-17 05:19:50.672871 |
| 20220224045126 | after  | 2023-02-17 05:23:11.265727 | 2023-02-17 05:23:11.982982 |
+------------------------------------------------------+----------------------------+
```

### Differences from doctrine/migrations

The underlying diff checking and generation is equal to what happens in doctrine/migrations as it uses doctrine/dbal features.
Main difference is that we do not provide any downgrade phases.

This library is aiming to provide only core functionality needed for safe migrations within rolling-update deployments.
Basically all the logic is inside `MigrationService`, which has only ~300 lines.
We try to keep it as lightweight as possible, we do not plan to copy features from doctrine/migrations.

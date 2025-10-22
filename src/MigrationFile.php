<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

readonly class MigrationFile
{

    public function __construct(
        public string $filePath,
        public string $version,
        public string $content,
    )
    {
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

class MigrationFile
{

    private string $filePath;

    private string $version;

    public function __construct(string $filePath, string $version)
    {
        $this->filePath = $filePath;
        $this->version = $version;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

}

<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;

class MigrationRun
{

    private string $version;

    private string $phase;

    private float $duration;

    private DateTimeImmutable $finishedAt;

    public function __construct(string $version, string $phase, float $duration, DateTimeImmutable $finishedAt)
    {
        $this->version = $version;
        $this->phase = $phase;
        $this->duration = $duration;
        $this->finishedAt = $finishedAt;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getFinishedAt(): DateTimeImmutable
    {
        return $this->finishedAt;
    }

}

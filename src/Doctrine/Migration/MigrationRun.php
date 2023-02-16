<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use DateTimeImmutable;

class MigrationRun
{

    private string $version;

    private string $phase;

    private DateTimeImmutable $startedAt;

    private DateTimeImmutable $finishedAt;

    public function __construct(
        string $version,
        string $phase,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt
    )
    {
        $this->version = $version;
        $this->phase = $phase;
        $this->startedAt = $startedAt;
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
        return ($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp())
            + ($this->finishedAt->format('u') - $this->startedAt->format('u')) / 1_000_000;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): DateTimeImmutable
    {
        return $this->finishedAt;
    }

}

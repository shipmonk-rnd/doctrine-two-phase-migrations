<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration\Command;

use Psr\Log\AbstractLogger;
use Stringable;
use function array_column;
use function in_array;
use function is_string;

class TestLogger extends AbstractLogger
{

    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private array $records = [];

    public function log(
        mixed $level,
        string|Stringable $message,
        array $context = [],
    ): void
    {
        /** @var array<string, mixed> $context */
        $this->records[] = [
            'level' => is_string($level) ? $level : '',
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasMessage(string $message): bool
    {
        return in_array($message, array_column($this->records, 'message'), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContextFor(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record['context'];
            }
        }

        return null;
    }

}

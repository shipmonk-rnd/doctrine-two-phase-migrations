<?php declare(strict_types = 1);

namespace ShipMonk\Doctrine\Migration;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use function is_string;

class CachingSqlLogger implements LoggerInterface
{

    use LoggerTrait;

    /**
     * @var list<string>
     */
    private array $queries = [];

    /**
     * @param mixed[] $context
     */
    public function log(
        mixed $level,
        string|Stringable $message,
        array $context = [],
    ): void
    {
        if (isset($context['sql'])) {
            if (!is_string($context['sql'])) {
                throw new LogicException('Query should be a string.');
            }

            $this->queries[] = $context['sql'];
        } elseif ($context === []) {
            $this->queries[] = (string) $message;
        }
    }

    /**
     * @return list<string>
     */
    public function getQueriesPerformed(): array
    {
        return $this->queries;
    }

    public function clean(): void
    {
        $this->queries = [];
    }

}

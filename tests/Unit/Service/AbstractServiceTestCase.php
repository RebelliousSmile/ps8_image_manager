<?php

declare(strict_types=1);

namespace ScImageManager\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for service tests with DBAL Connection mocking helpers
 */
abstract class AbstractServiceTestCase extends TestCase
{
    protected Connection&MockObject $connection;
    protected string $prefix = 'ps_';

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    /**
     * Configure fetchOne to return values in order for sequential calls
     */
    protected function mockFetchOneSequence(array $values): void
    {
        $this->connection
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(...$values);
    }

    /**
     * Configure fetchAllAssociative to return values in order
     */
    protected function mockFetchAllSequence(array $values): void
    {
        $this->connection
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(...$values);
    }

    /**
     * Configure fetchFirstColumn to return values in order
     */
    protected function mockFetchFirstColumnSequence(array $values): void
    {
        $this->connection
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(...$values);
    }
}

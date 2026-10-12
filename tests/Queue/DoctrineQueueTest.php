<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Tests\Queue;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Tools\DsnParser;
use Nyholm\Psr7\Uri;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\Queue\DoctrineQueue;
use Terminal42\Escargot\Queue\QueueInterface;

final class DoctrineQueueTest extends AbstractQueueTestCase
{
    private DoctrineQueue $queue;

    private Connection $connection;

    protected function setUp(): void
    {
        $configuration = new Configuration();
        $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());
        $this->connection = DriverManager::getConnection(
            (new DsnParser(['sqlite' => 'pdo_sqlite']))->parse('sqlite://:memory:'),
            $configuration,
        );

        $this->queue = new DoctrineQueue($this->connection, static fn () => 'foobar');

        $this->queue->createSchema();
    }

    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }

    public function testGetTableSchema(): void
    {
        $table = $this->queue->getTableSchema();

        $this->assertSame('escargot', $table->getName());
        $this->assertSame(['id'], $table->getPrimaryKey()?->getColumns());
        $this->assertFalse($table->getColumn('redirected_to')->getNotnull());
    }

    public function testSchemaMigrationPreservesExistingRows(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $table = $this->queue->getTableSchema();
        $table->dropColumn('redirected_to');
        $schemaManager->dropTable($table->getName());
        $schemaManager->createTable($table);
        $uri = new Uri('https://example.com/original');
        $this->connection->insert('escargot', [
            'job_id' => 'legacy',
            'uri' => (string) $uri,
            'uri_hash' => sha1((string) $uri),
            'level' => 0,
            'processed' => 0,
        ]);

        $this->queue->createSchema();

        $crawlUri = $this->queue->get('legacy', $uri);
        $this->assertInstanceOf(CrawlUri::class, $crawlUri);
        $this->assertNull($crawlUri->getRedirectedTo());
        $crawlUri->markRedirectedTo(new Uri('https://example.com/final'));
        $this->queue->add('legacy', $crawlUri);

        $this->assertSame('https://example.com/final', (string) $this->queue->get('legacy', $uri)?->getRedirectedTo());
    }
}

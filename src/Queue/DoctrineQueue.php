<?php

declare(strict_types=1);

namespace Terminal42\Escargot\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Psr\Http\Message\UriInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\HttpUriFactory;

final class DoctrineQueue implements QueueInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly \Closure $jobIdGenerator,
        private readonly string $tableName = 'escargot',
    ) {
    }

    public function createJobId(BaseUriCollection $baseUris): string
    {
        $jobId = ($this->jobIdGenerator)();

        foreach ($baseUris as $baseUri) {
            $this->add($jobId, new CrawlUri($baseUri, 0));
        }

        return $jobId;
    }

    public function isJobIdValid(string $jobId): bool
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('COUNT(job_id) as count')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->setParameter('jobId', $jobId, Types::STRING)
            ->setMaxResults(1)
        ;

        return (bool) $queryBuilder->executeQuery()->fetchOne();
    }

    public function deleteJobId(string $jobId): void
    {
        $this->connection->createQueryBuilder()
            ->delete($this->tableName)
            ->where('job_id = :jobId')
            ->setParameter('jobId', $jobId, Types::STRING)
            ->executeStatement()
        ;
    }

    public function getBaseUris(string $jobId): BaseUriCollection
    {
        $baseUris = new BaseUriCollection();

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('uri')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->andWhere('level = :level')
            ->setParameter('jobId', $jobId, Types::STRING)
            ->setParameter('level', 0, Types::INTEGER)
        ;

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        foreach ($rows as $row) {
            $baseUris->add(HttpUriFactory::create($row['uri']));
        }

        return $baseUris;
    }

    public function get(string $jobId, UriInterface $uri): CrawlUri|null
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('uri, level, processed, found_on, redirected_to, tags')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->andWhere('uri_hash = :uri_hash')
            ->setParameter('jobId', $jobId, Types::STRING)
            ->setParameter('uri_hash', $this->getUriHash($uri), Types::STRING)
            ->setMaxResults(1)
        ;

        $data = $queryBuilder->executeQuery()->fetchAssociative();

        if (false === $data) {
            return null;
        }

        return $this->createCrawlUriFromRow($data);
    }

    public function add(string $jobId, CrawlUri $crawlUri): void
    {
        $queryBuilder = null === $this->get($jobId, $crawlUri->getUri())
            ? $this->createInsertQueryBuilder($crawlUri)
            : $this->createUpdateQueryBuilder();

        $queryBuilder
            ->setParameter('jobId', $jobId, Types::STRING)
            ->setParameter('uri_hash', $this->getUriHash($crawlUri->getUri()), Types::STRING)
            ->setParameter('processed', $crawlUri->isProcessed(), Types::BOOLEAN)
            ->setParameter('tags', implode(',', $crawlUri->getTags()), Types::TEXT)
            ->setParameter('redirectedTo', $crawlUri->getRedirectedTo(), Types::STRING)
            ->executeStatement()
        ;
    }

    public function getNext(string $jobId, int $skip = 0): CrawlUri|null
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('uri, level, processed, found_on, redirected_to, tags')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->andWhere('processed = :processed')
            ->orderBy('id', 'ASC')
            ->setParameter('jobId', $jobId, Types::STRING)
            ->setParameter('processed', false, Types::BOOLEAN)
            ->setMaxResults(1)
        ;

        if ($skip > 0) {
            $queryBuilder->setFirstResult($skip);
        }

        $data = $queryBuilder->executeQuery()->fetchAssociative();

        if (false === $data) {
            return null;
        }

        return $this->createCrawlUriFromRow($data);
    }

    public function countAll(string $jobId): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(job_id) as count')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->setParameter('jobId', $jobId, Types::STRING)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;
    }

    public function countPending(string $jobId): int
    {
        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(job_id) as count')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->andWhere('processed = :processed')
            ->setParameter('jobId', $jobId, Types::STRING)
            ->setParameter('processed', false, Types::BOOLEAN)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;
    }

    public function getAll(string $jobId): \Generator
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('uri, level, processed, found_on, redirected_to, tags')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->orderBy('id', 'ASC')
            ->setParameter('jobId', $jobId, Types::STRING)
        ;

        $allData = $queryBuilder->executeQuery()->fetchAllAssociative();

        foreach ($allData as $data) {
            yield $this->createCrawlUriFromRow($data);
        }
    }

    public function createSchema(): void
    {
        $table = $this->getTableSchema();

        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist([$this->tableName])) {
            $queries = $this->connection->getDatabasePlatform()->getCreateTableSQL($table);
        } else {
            $comparator = $schemaManager->createComparator();

            $tableDiff = $comparator->compareTables($schemaManager->introspectTable($this->tableName), $table);
            $queries = $this->connection->getDatabasePlatform()->getAlterTableSQL($tableDiff);
        }

        foreach ($queries as $query) {
            $this->connection->executeQuery($query);
        }
    }

    public function getTableSchema(): Table
    {
        $table = new Table($this->tableName);

        $table->addColumn('id', Types::BIGINT)
            ->setAutoincrement(true)
            ->setNotnull(true)
        ;

        $table->addColumn('job_id', Types::GUID)
            ->setNotnull(true)
        ;

        $this->addUriColumns($table);

        $table->addColumn('level', Types::INTEGER)
            ->setNotnull(true)
        ;

        $table->addColumn('processed', Types::BOOLEAN)
            ->setNotnull(true)
        ;

        $table->addColumn('tags', Types::TEXT)
            ->setNotnull(false)
        ;

        $table->setPrimaryKey(['id']);
        $table->addIndex(['job_id']);
        $table->addIndex(['level']);
        $table->addIndex(['uri']);
        $table->addIndex(['processed']);

        return $table;
    }

    private function createInsertQueryBuilder(CrawlUri $crawlUri): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->insert($this->tableName)
            ->values([
                'job_id' => ':jobId',
                'uri' => ':uri',
                'uri_hash' => ':uri_hash',
                'level' => ':level',
                'found_on' => ':foundOn',
                'redirected_to' => ':redirectedTo',
                'processed' => ':processed',
                'tags' => ':tags',
            ])
            ->setParameter('uri', (string) $crawlUri->getUri(), Types::STRING)
            ->setParameter('level', $crawlUri->getLevel(), Types::INTEGER)
            ->setParameter('foundOn', $crawlUri->getFoundOn(), Types::STRING)
        ;
    }

    private function createUpdateQueryBuilder(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->update($this->tableName)
            ->set('processed', ':processed')
            ->set('tags', ':tags')
            ->set('redirected_to', ':redirectedTo')
            ->where('job_id = :jobId')
            ->andWhere('uri_hash = :uri_hash')
        ;
    }

    private function addUriColumns(Table $table): void
    {
        $table->addColumn('uri_hash', Types::STRING)
            ->setLength(40)
            ->setFixed(true)
            ->setNotnull(true)
        ;

        $table->addColumn('uri', Types::TEXT)
            ->setNotnull(true)
        ;

        $table->addColumn('found_on', Types::TEXT)
            ->setNotnull(false)
        ;

        $table->addColumn('redirected_to', Types::TEXT)
            ->setNotnull(false)
        ;
    }

    /**
     * @param array<string, string> $data
     */
    private function createCrawlUriFromRow(array $data): CrawlUri
    {
        $foundOn = null;

        if ($data['found_on']) {
            $foundOn = HttpUriFactory::create($data['found_on']);
        }

        $crawlUri = new CrawlUri(HttpUriFactory::create($data['uri']), (int) $data['level'], (bool) $data['processed'], $foundOn);

        if ($data['tags']) {
            foreach (explode(',', $data['tags']) as $tag) {
                $crawlUri->addTag($tag);
            }
        }

        if ($data['redirected_to']) {
            $crawlUri->markRedirectedTo(HttpUriFactory::create($data['redirected_to']));
        }

        return $crawlUri;
    }

    private function getUriHash(UriInterface $uri): string
    {
        return sha1((string) $uri);
    }
}

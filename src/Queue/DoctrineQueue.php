<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019 - 2021, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Synchronizer\SchemaSynchronizer;
use Doctrine\DBAL\Schema\Synchronizer\SingleDatabaseSynchronizer;
use Doctrine\DBAL\Types\Types;
use Psr\Http\Message\UriInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\CrawlUri;
use Terminal42\Escargot\HttpUriFactory;

final class DoctrineQueue implements QueueInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var \Closure
     */
    private $jobIdGenerator;

    /**
     * @var string
     */
    private $tableName;

    /**
     * @var SchemaSynchronizer
     */
    private $schemaSynchronizer;

    public function __construct(Connection $connection, \Closure $jobIdGenerator, ?string $tableName = null, ?SchemaSynchronizer $schemaSynchronizer = null)
    {
        $this->connection = $connection;
        $this->jobIdGenerator = $jobIdGenerator;
        $this->tableName = $tableName ?? 'escargot';
        $this->schemaSynchronizer = $schemaSynchronizer ?? new SingleDatabaseSynchronizer($connection);
    }

    public function createJobId(BaseUriCollection $baseUris): string
    {
        $jobId = $this->jobIdGenerator->__invoke();

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
            ->setParameter(':jobId', $jobId, Types::STRING)
            ->setMaxResults(1);

        return (bool) $queryBuilder->execute()->fetchColumn();
    }

    public function deleteJobId(string $jobId): void
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->delete($this->tableName)
            ->where('job_id = :jobId')
            ->setParameter(':jobId', $jobId, Types::STRING);

        $queryBuilder->execute();
    }

    public function getBaseUris(string $jobId): BaseUriCollection
    {
        $baseUris = new BaseUriCollection();

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('uri')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->andWhere('level = :level')
            ->setParameter(':jobId', $jobId, Types::STRING)
            ->setParameter(':level', 0, Types::INTEGER);

        $uris = $queryBuilder->execute()->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($uris as $uri) {
            $baseUris->add(HttpUriFactory::create($uri));
        }

        return $baseUris;
    }

    public function get(string $jobId, UriInterface $uri): ?CrawlUri
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('uri, level, processed, found_on, tags')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->andWhere('uri_hash = :uri_hash')
            ->setParameter(':jobId', $jobId, Types::STRING)
            ->setParameter(':uri_hash', $this->getUriHash($uri), Types::STRING)
            ->setMaxResults(1);

        $data = $queryBuilder->execute()->fetch();

        if (false === $data) {
            return null;
        }

        return $this->createCrawlUriFromRow($data);
    }

    public function add(string $jobId, CrawlUri $crawlUri): void
    {
        $queryBuilder = $this->connection->createQueryBuilder();

        if (null === $this->get($jobId, $crawlUri->getUri())) {
            $queryBuilder
                ->insert($this->tableName)
                ->values([
                    'job_id' => ':jobId',
                    'uri' => ':uri',
                    'uri_hash' => ':uri_hash',
                    'level' => ':level',
                    'found_on' => ':foundOn',
                    'processed' => ':processed',
                    'tags' => ':tags',
                ])
                ->setParameter(':uri', (string) $crawlUri->getUri(), Types::STRING)
                ->setParameter(':level', (int) $crawlUri->getLevel(), Types::INTEGER)
                ->setParameter(':foundOn', $crawlUri->getFoundOn(), Types::STRING);
        } else {
            $queryBuilder
                ->update($this->tableName)
                ->set('processed', ':processed')
                ->set('tags', ':tags')
                ->where('job_id = :jobId')
                ->andWhere('uri_hash = :uri_hash');
        }

        $queryBuilder
            ->setParameter(':jobId', $jobId, Types::STRING)
            ->setParameter(':uri_hash', $this->getUriHash($crawlUri->getUri()), Types::STRING)
            ->setParameter(':processed', $crawlUri->isProcessed(), Types::BOOLEAN)
            ->setParameter(':tags', implode(',', $crawlUri->getTags()), Types::TEXT);

        $queryBuilder->execute();
    }

    public function getNext(string $jobId, int $skip = 0): ?CrawlUri
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('uri, level, processed, found_on, tags')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->andWhere('processed = :processed')
            ->orderBy('id', 'ASC')
            ->setParameter(':jobId', $jobId, Types::STRING)
            ->setParameter(':processed', false, Types::BOOLEAN)
            ->setMaxResults(1);

        if ($skip > 0) {
            $queryBuilder->setFirstResult($skip);
        }

        $data = $queryBuilder->execute()->fetch();

        if (false === $data) {
            return null;
        }

        return $this->createCrawlUriFromRow($data);
    }

    public function countAll(string $jobId): int
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('COUNT(job_id) as count')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->setParameter(':jobId', $jobId, Types::STRING)
            ->setMaxResults(1);

        return (int) $queryBuilder->execute()->fetchColumn();
    }

    public function countPending(string $jobId): int
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('COUNT(job_id) as count')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->andWhere('processed = :processed')
            ->setParameter(':jobId', $jobId, Types::STRING)
            ->setParameter(':processed', false, Types::BOOLEAN)
            ->setMaxResults(1);

        return (int) $queryBuilder->execute()->fetchColumn();
    }

    public function getAll(string $jobId): \Generator
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('uri, level, processed, found_on, tags')
            ->from($this->tableName)
            ->where('job_id = :jobId')
            ->orderBy('id', 'ASC')
            ->setParameter(':jobId', $jobId, Types::STRING);
        $allData = $queryBuilder->execute()->fetchAll();

        foreach ($allData as $data) {
            yield $this->createCrawlUriFromRow($data);
        }
    }

    public function createSchema(): void
    {
        $schema = new Schema();
        $table = $schema->createTable($this->tableName);

        $table->addColumn('id', Types::BIGINT)
            ->setAutoincrement(true)
            ->setNotnull(true);

        $table->addColumn('job_id', Types::GUID)
            ->setNotnull(true);

        $table->addColumn('uri_hash', Types::STRING)
            ->setLength(40)
            ->setFixed(true)
            ->setNotnull(true);

        $table->addColumn('uri', Types::TEXT)
            ->setNotnull(true);

        $table->addColumn('found_on', Types::TEXT)
            ->setNotnull(false);

        $table->addColumn('level', Types::INTEGER)
            ->setNotnull(true);

        $table->addColumn('processed', Types::BOOLEAN)
            ->setNotnull(true);

        $table->addColumn('tags', Types::TEXT)
            ->setNotnull(false);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['job_id']);
        $table->addIndex(['level']);
        $table->addIndex(['uri']);
        $table->addIndex(['processed']);

        $this->schemaSynchronizer->createSchema($schema);
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

        return $crawlUri;
    }

    private function getUriHash(UriInterface $uri): string
    {
        return sha1((string) $uri);
    }
}

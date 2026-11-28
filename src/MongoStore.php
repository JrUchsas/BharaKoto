<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

/**
 * Lightweight MongoDB wrapper over PHP's native ext-mongodb driver.
 * Works identically across ext-mongodb 1.x and 2.x without external dependencies.
 */
final class MongoDatabase
{
    private Manager $manager;
    private string $databaseName;

    public function __construct(Manager $manager, string $databaseName)
    {
        $this->manager = $manager;
        $this->databaseName = $databaseName;
    }

    public function getManager(): Manager
    {
        return $this->manager;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function selectCollection(string $name): MongoCollection
    {
        return new MongoCollection($this->manager, $this->databaseName, $name);
    }

    public function __get(string $name): MongoCollection
    {
        return $this->selectCollection($name);
    }

    public function command(array $command): array
    {
        $cursor = $this->manager->executeCommand($this->databaseName, new Command($command));
        $results = [];
        foreach ($cursor as $doc) {
            $results[] = self::normalizeBson($doc);
        }
        return $results;
    }

    /**
     * Atomically get the next sequential integer ID for a collection.
     */
    public function nextSequence(string $collectionName): int
    {
        $doc = $this->selectCollection('counters')->findOneAndUpdate(
            ['_id' => $collectionName],
            ['$inc' => ['seq' => 1]],
            ['upsert' => true, 'new' => true]
        );
        return (int)($doc['seq'] ?? 1);
    }

    /**
     * Ensure sequence counter is at least $minValue (useful after explicit-ID seeding).
     */
    public function syncSequence(string $collectionName, int $minValue): void
    {
        $counters = $this->selectCollection('counters');
        $existing = $counters->findOne(['_id' => $collectionName]);
        $currentSeq = (int)($existing['seq'] ?? 0);
        if ($minValue > $currentSeq) {
            $counters->updateOne(
                ['_id' => $collectionName],
                ['$set' => ['seq' => $minValue]],
                ['upsert' => true]
            );
        }
    }

    /**
     * Recursively convert BSON/stdClass values into plain PHP arrays & Dhaka timestamps.
     */
    public static function normalizeBson(mixed $value): mixed
    {
        if ($value instanceof UTCDateTime) {
            $dt = $value->toDateTime();
            $dt->setTimezone(new DateTimeZone('Asia/Dhaka'));
            return $dt->format('Y-m-d H:i:s');
        }
        if ($value instanceof ObjectId) {
            return (string)$value;
        }
        if ($value instanceof stdClass || $value instanceof Traversable) {
            $arr = [];
            foreach ($value as $k => $v) {
                $arr[$k] = self::normalizeBson($v);
            }
            return $arr;
        }
        if (is_array($value)) {
            $arr = [];
            foreach ($value as $k => $v) {
                $arr[$k] = self::normalizeBson($v);
            }
            return $arr;
        }
        return $value;
    }

    /**
     * Create a UTCDateTime from now or relative seconds offset.
     */
    public static function utcDateTime(?int $timestampSeconds = null): UTCDateTime
    {
        $ts = $timestampSeconds ?? time();
        return new UTCDateTime($ts * 1000);
    }
}

final class MongoCollection
{
    private Manager $manager;
    private string $databaseName;
    private string $collectionName;
    private string $namespace;

    public function __construct(Manager $manager, string $databaseName, string $collectionName)
    {
        $this->manager = $manager;
        $this->databaseName = $databaseName;
        $this->collectionName = $collectionName;
        $this->namespace = "{$databaseName}.{$collectionName}";
    }

    public function find(array $filter = [], array $options = []): array
    {
        $query = new Query((object)$filter, $options);
        $cursor = $this->manager->executeQuery($this->namespace, $query);
        $rows = [];
        foreach ($cursor as $doc) {
            $rows[] = MongoDatabase::normalizeBson($doc);
        }
        return $rows;
    }

    public function findOne(array $filter = [], array $options = []): ?array
    {
        $options['limit'] = 1;
        $rows = $this->find($filter, $options);
        return $rows[0] ?? null;
    }

    public function insertOne(array $document): mixed
    {
        $bulk = new BulkWrite();
        $insertedId = $bulk->insert($document);
        $this->manager->executeBulkWrite($this->namespace, $bulk);
        return $document['id'] ?? ($insertedId instanceof ObjectId ? (string)$insertedId : $insertedId);
    }

    public function insertMany(array $documents): int
    {
        if (empty($documents)) {
            return 0;
        }
        $bulk = new BulkWrite();
        foreach ($documents as $doc) {
            $bulk->insert($doc);
        }
        $result = $this->manager->executeBulkWrite($this->namespace, $bulk);
        return (int)$result->getInsertedCount();
    }

    public function updateOne(array $filter, array $update, array $options = []): int
    {
        $bulk = new BulkWrite();
        $bulk->update((object)$filter, $update, [
            'multi' => false,
            'upsert' => (bool)($options['upsert'] ?? false),
        ]);
        $result = $this->manager->executeBulkWrite($this->namespace, $bulk);
        return (int)$result->getModifiedCount() + (int)$result->getUpsertedCount();
    }

    public function updateMany(array $filter, array $update): int
    {
        $bulk = new BulkWrite();
        $bulk->update((object)$filter, $update, ['multi' => true, 'upsert' => false]);
        $result = $this->manager->executeBulkWrite($this->namespace, $bulk);
        return (int)$result->getModifiedCount();
    }

    public function deleteOne(array $filter): int
    {
        $bulk = new BulkWrite();
        $bulk->delete((object)$filter, ['limit' => 1]);
        $result = $this->manager->executeBulkWrite($this->namespace, $bulk);
        return (int)$result->getDeletedCount();
    }

    public function deleteMany(array $filter): int
    {
        $bulk = new BulkWrite();
        $bulk->delete((object)$filter, ['limit' => 0]);
        $result = $this->manager->executeBulkWrite($this->namespace, $bulk);
        return (int)$result->getDeletedCount();
    }

    public function countDocuments(array $filter = []): int
    {
        $cmd = [
            'count' => $this->collectionName,
            'query' => (object)$filter,
        ];
        $cursor = $this->manager->executeCommand($this->databaseName, new Command($cmd));
        $docs = $cursor->toArray();
        return isset($docs[0]->n) ? (int)$docs[0]->n : 0;
    }

    public function aggregate(array $pipeline): array
    {
        $cmd = [
            'aggregate' => $this->collectionName,
            'pipeline' => $pipeline,
            'cursor' => new stdClass(),
        ];
        $cursor = $this->manager->executeCommand($this->databaseName, new Command($cmd));
        $rows = [];
        foreach ($cursor as $doc) {
            $rows[] = MongoDatabase::normalizeBson($doc);
        }
        return $rows;
    }

    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?array
    {
        $cmd = [
            'findAndModify' => $this->collectionName,
            'query' => (object)$filter,
            'update' => $update,
            'new' => (bool)($options['new'] ?? true),
            'upsert' => (bool)($options['upsert'] ?? false),
        ];
        $cursor = $this->manager->executeCommand($this->databaseName, new Command($cmd));
        $docs = $cursor->toArray();
        if (empty($docs) || !isset($docs[0]->value) || $docs[0]->value === null) {
            return null;
        }
        return MongoDatabase::normalizeBson($docs[0]->value);
    }

    public function createIndex(array $keys, array $options = []): void
    {
        $indexSpec = array_merge([
            'key' => $keys,
            'name' => $options['name'] ?? $this->buildIndexName($keys),
        ], $options);

        $cmd = [
            'createIndexes' => $this->collectionName,
            'indexes' => [$indexSpec],
        ];
        $this->manager->executeCommand($this->databaseName, new Command($cmd));
    }

    public function drop(): void
    {
        try {
            $this->manager->executeCommand($this->databaseName, new Command(['drop' => $this->collectionName]));
        } catch (Throwable $e) {
            // Ignore NamespaceNotFound when collection doesn't exist yet
            if (!str_contains($e->getMessage(), 'ns not found') && $e->getCode() !== 26) {
                throw $e;
            }
        }
    }

    private function buildIndexName(array $keys): string
    {
        $parts = [];
        foreach ($keys as $field => $dir) {
            $parts[] = "{$field}_{$dir}";
        }
        return implode('_', $parts);
    }
}

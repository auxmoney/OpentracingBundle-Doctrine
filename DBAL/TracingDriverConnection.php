<?php

declare(strict_types=1);

namespace Auxmoney\OpentracingDoctrineDBALBundle\DBAL;

use Auxmoney\OpentracingBundle\Service\Tracing;
use Doctrine\DBAL\Driver\Connection as DBALDriverConnection;
use Doctrine\DBAL\Driver\Statement as DoctrineStatement;

/**
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
final class TracingDriverConnection implements DBALDriverConnection
{
    private $decoratedConnection;
    private $tracing;
    private $spanFactory;
    private $username;

    public function __construct(
        DBALDriverConnection $decoratedConnection,
        Tracing $tracing,
        SpanFactory $spanFactory,
        ?string $username
    ) {
        $this->decoratedConnection = $decoratedConnection;
        $this->tracing = $tracing;
        $this->spanFactory = $spanFactory;
        $this->username = $username;
    }

    /**
     * @param string $prepareString
     * @return iterable<DoctrineStatement>
     */
    public function prepare($prepareString)
    {
        $statement = $this->decoratedConnection->prepare($prepareString);
        return new TracingStatement($statement, $this->spanFactory, $prepareString, $this->username);
    }

    /**
     * @return iterable<DoctrineStatement>
     */
    public function query()
    {
        $args = func_get_args();
        $parameters = array_slice($args, 1);
        $this->spanFactory->beforeOperation($args[0], $parameters, $this->username);
        $result = $this->decoratedConnection->query(...$args);
        usleep(15000); // FIXME
        $this->spanFactory->afterOperation($args[0], $parameters, $this->username, $result->rowCount());
        return $result;
    }

    /**
     * @param string $input
     * @param int $type
     * @return string
     */
    public function quote($input, $type = 2) // we do not want a hard dependency on PDO
    {
        return $this->decoratedConnection->quote($input, $type);
    }

    /**
     * @inheritDoc
     */
    public function exec($statement)
    {
        $this->spanFactory->beforeOperation($statement, [], $this->username);
        $result = $this->decoratedConnection->exec($statement);
        usleep(5000); // FIXME
        $this->spanFactory->afterOperation($statement, [], $this->username, $result);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function lastInsertId($name = null)
    {
        return $this->decoratedConnection->lastInsertId($name);
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction()
    {
        $this->tracing->startActiveSpan('DBAL: (transaction)'); # FIXME
        $this->decoratedConnection->beginTransaction();
        return true;
    }

    /**
     * @inheritDoc
     */
    public function commit()
    {
        $this->decoratedConnection->commit();
        $this->tracing->setTagOfActiveSpan('db.transaction.end', 'commit'); # FIXME
        $this->tracing->finishActiveSpan();
        return true;
    }

    /**
     * @inheritDoc
     */
    public function rollBack()
    {
        $result = $this->decoratedConnection->rollBack();
        $this->tracing->setTagOfActiveSpan('db.transaction.end', 'rollBack'); # FIXME
        $this->tracing->finishActiveSpan();
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function errorCode()
    {
        return $this->decoratedConnection->errorCode();
    }

    /**
     * @return array<mixed>
     */
    public function errorInfo()
    {
        return $this->decoratedConnection->errorInfo();
    }
}
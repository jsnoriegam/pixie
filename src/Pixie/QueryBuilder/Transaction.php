<?php

namespace Pixie\QueryBuilder;

class Transaction extends QueryBuilderHandler
{

    protected $status = 'auto';
    /**
     * Commit the database changes
     * @throws TransactionHaltException
     */
    public function commit()
    {
        $this->pdo->commit();
        $this->status = 'commited';
    }

    /**
     * Rollback the database changes
     * @throws TransactionHaltException
     */
    public function rollback()
    {
        $this->pdo->rollBack();
        $this->status = 'rolledback';
    }

    public function getStatus()
    {
        return $this->status;
    }
}

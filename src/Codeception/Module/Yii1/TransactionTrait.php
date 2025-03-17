<?php

namespace Codeception\Module\Yii1;

use Codeception\Module\Yii1\TransactionWrapper;
use Yii;

trait TransactionTrait
{
    protected $transaction;

    protected function startTransaction(): void
    {
        $app = Yii::app();
        if ($app === null) {
            return;
        }
        $db = $app->getComponent('db');
        if ($db === null) {
            return;
        }
        $this->transaction = $db->beginTransaction();
    }

    protected function rollbackTransaction(): void
    {
        if ($this->transaction === null) {
            codecept_debug('Rollback issued while no transaction was active.');
            return;
        }
        $this->transaction->rollback();
    }
}

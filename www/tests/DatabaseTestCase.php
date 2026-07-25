<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;
use yii\console\Application;
use yii\db\Transaction;

/**
 * Base class for tests that need Yii and the database.
 *
 * Active Record derives its attributes from the live table schema, so even "unit" tests of
 * validation rules need a real connection. Each test therefore runs inside a transaction
 * that is always rolled back, which keeps them isolated and leaves not a single row behind
 * -- important, because these tests run against the same database that holds real items
 * (Constitution I: never destroy user data to make a test pass).
 */
abstract class DatabaseTestCase extends TestCase
{
    private ?Transaction $transaction = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (\Yii::$app === null) {
            $application = new Application(require \Yii::getAlias('@app') . '/config/console.php');

            // Yii installs global error/exception handlers on construction. PHPUnit
            // rightly reports leftover handlers as a risky test, and leaving them in place
            // would also swallow failures that PHPUnit needs to see.
            $application->getErrorHandler()->unregister();
        }

        $this->transaction = \Yii::$app->getDb()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->transaction !== null && $this->transaction->getIsActive()) {
            $this->transaction->rollBack();
        }

        $this->transaction = null;

        parent::tearDown();
    }
}

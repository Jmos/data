<?php

declare(strict_types=1);

namespace Atk4\Data\Tests;

use Atk4\Data\Exception;
use Atk4\Data\Model;
use Atk4\Data\Persistence\Sql\Mysql\Connection as MysqlConnection;
use Atk4\Data\Schema\TestCase;
use Doctrine\DBAL\Platforms\MySQLPlatform;

class ModelWithCteTest extends TestCase
{
    public function testWith(): void
    {
        $this->setDb([
            'user' => [
                10 => ['id' => 10, 'name' => 'John', 'salary' => 2500],
                20 => ['id' => 20, 'name' => 'Peter', 'salary' => 4000],
            ],
            'invoice' => [
                1 => ['id' => 1, 'net' => 500, 'user_id' => 10],
                ['id' => 2, 'net' => 200, 'user_id' => 20],
                ['id' => 3, 'net' => 100, 'user_id' => 20],
                ['id' => 4, 'net' => 400, 'user_id' => 20],
            ],
        ]);

        $mUser = new Model($this->db, ['table' => 'user']);
        $mUser->addField('name');
        $mUser->addField('salary', ['type' => 'integer']);

        $mInvoice = new Model($this->db, ['table' => 'invoice']);
        $mInvoice->addField('net', ['type' => 'integer']);
        $mInvoice->hasOne('user_id', ['model' => $mUser]);
        $mInvoice->addCondition('net', '>', 100);

        $m = clone $mUser;
        $m->addCteModel('i', $mInvoice);
        $jInvoice = $m->join('i.user_id');
        $jInvoice->addField('invoiced', ['type' => 'integer', 'actual' => 'net']); // add field from joined CTE

        $this->assertSameSql(
            'with `i` as (select `id`, `net`, `user_id` from `invoice` where `net` > :a)' . "\n"
                . 'select `user`.`id`, `user`.`name`, `user`.`salary`, `_i`.`net` `invoiced` from `user` inner join `i` `_i` on `_i`.`user_id` = `user`.`id`',
            $m->action('select')->render()[0]
        );

        if ($this->getDatabasePlatform() instanceof MySQLPlatform
            && !MysqlConnection::isServerMariaDb($this->getConnection())
            && MysqlConnection::getServerMinorVersion($this->getConnection()) < 600
        ) {
            self::markTestIncomplete('MySQL 5.x does not support WITH clause');
        }

        self::assertSameExportUnordered([
            ['id' => 10, 'name' => 'John', 'salary' => 2500, 'invoiced' => 500],
            ['id' => 20, 'name' => 'Peter', 'salary' => 4000, 'invoiced' => 200],
            ['id' => 20, 'name' => 'Peter', 'salary' => 4000, 'invoiced' => 400],
        ], $m->export());
    }

    public function testUniqueNameException1(): void
    {
        $m1 = new Model(null, ['table' => 't']);
        $m2 = new Model();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CTE model with given name already exist');
        $m1->addCteModel('t', $m2);
    }

    public function testUniqueNameException2(): void
    {
        $m1 = new Model(null, ['tableAlias' => 't']);
        $m2 = new Model();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CTE model with given name already exist');
        $m1->addCteModel('t', $m2);
    }

    public function testUniqueNameException3(): void
    {
        $m1 = new Model();
        $m2 = new Model();
        $m1->addCteModel('t', $m2);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('CTE model with given name already exist');
        $m1->addCteModel('t', $m2);
    }
}

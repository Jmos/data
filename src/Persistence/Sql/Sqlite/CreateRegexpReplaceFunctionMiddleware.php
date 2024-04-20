<?php

declare(strict_types=1);

namespace Atk4\Data\Persistence\Sql\Sqlite;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

class CreateRegexpReplaceFunctionMiddleware implements Middleware
{
    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            #[\Override]
            public function connect(
                #[\SensitiveParameter]
                array $params
            ): Connection {
                $connection = parent::connect($params);

                $nativeConnection = $connection->getNativeConnection();
                assert($nativeConnection instanceof \PDO);

                $nativeConnection->sqliteCreateFunction('regexp_replace', static function ($value, string $pattern, string $replacement, string $flags = ''): ?string {
                    if ($value === null) {
                        return null;
                    }

                    if (is_int($value)) {
                        $value = (string) $value;
                    } elseif (is_float($value)) {
                        $value = Expression::castFloatToString($value);
                    }

                    $isValidUtf8Value = \PHP_VERSION_ID < 80200
                        ? preg_match('~~u', $value) === 1 // much faster in PHP 8.1 and lower
                        : mb_check_encoding($value, 'UTF-8');

                    $pregPattern = '~' . preg_replace('~(?<!\\\)(?:\\\\\\\)*+\K\~~', '\\\~', $pattern) . '~'
                        . $flags . ($isValidUtf8Value ? 'u' : '');

                    return preg_replace($pregPattern, $replacement, $value);
                }, -1, \PDO::SQLITE_DETERMINISTIC);

                return $connection;
            }
        };
    }
}

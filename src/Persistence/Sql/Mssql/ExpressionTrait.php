<?php

declare(strict_types=1);

namespace Atk4\Data\Persistence\Sql\Mssql;

use Atk4\Data\Persistence;
use Atk4\Data\Persistence\Sql\ExecuteException;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\PDO\Exception as DbalDriverPdoException;
use Doctrine\DBAL\Driver\PDO\Result as DbalDriverPdoResult;
use Doctrine\DBAL\Result as DbalResult;

trait ExpressionTrait
{
    #[\Override]
    protected function escapeStringLiteral(string $value): string
    {
        $dummyPersistence = (new \ReflectionClass(Persistence\Sql::class))->newInstanceWithoutConstructor();
        if (\Closure::bind(static fn () => $dummyPersistence->explicitCastIsEncodedBinary($value), null, Persistence\Sql::class)()) {
            $value = \Closure::bind(static fn () => $dummyPersistence->explicitCastDecode($value), null, Persistence\Sql::class)();

            return 'convert(VARBINARY(MAX), \'' . bin2hex($value) . '\', 2)';
        }

        $parts = [];
        foreach (preg_split('~(\x00{1,4000})~', $value, -1, \PREG_SPLIT_DELIM_CAPTURE) as $i => $v) {
            if (($i % 2) === 1) {
                $parts[] = strlen($v) === 1
                    ? 'nchar(0)'
                    : 'replicate(nchar(0), ' . strlen($v) . ')';
            } elseif ($v !== '') {
                foreach (mb_str_split($v, 4000) as $v2) {
                    // TODO report "select N'\'':n?'" issue to https://github.com/microsoft/msphpsql
                    foreach (preg_split('~(:+[^\w]*)~', $v2, -1, \PREG_SPLIT_DELIM_CAPTURE) as $v3) {
                        if ($v3 !== '') {
                            $parts[] = '\'' . str_replace(
                                ['\'', "\\\r\n", "\\\n", "\\\r"],
                                ['\'\'', "\\\r\n\r\n", "\\\\\n\n", "\\\\\r"],
                                $v3
                            ) . '\'';
                        }
                    }
                }
            }
        }

        if ($parts === []) {
            $parts = ['\'\''];
        }

        return $this->makeNaryTree($parts, 10, static function (array $parts) {
            if (count($parts) === 1) {
                return reset($parts);
            }

            $parts[0] = 'cast(' . $parts[0] . ' as NVARCHAR(MAX))';

            return 'concat(' . implode(', ', $parts) . ')';
        });
    }

    #[\Override]
    public function render(): array
    {
        [$sql, $params] = parent::render();

        // convert all string literals to NVARCHAR, eg. 'text' to N'text'
        $sql = preg_replace_callback(
            '~(?!\')' . self::QUOTED_TOKEN_REGEX . '\K|N?' . self::QUOTED_TOKEN_REGEX . '~',
            static function ($matches) {
                if ($matches[0] === '') {
                    return '';
                }

                return (substr($matches[0], 0, 1) === 'N' ? '' : 'N') . $matches[0];
            },
            $sql
        );

        return [$sql, $params];
    }

    #[\Override]
    protected function hasNativeNamedParamSupport(): bool
    {
        return false;
    }

    #[\Override]
    protected function updateRenderBeforeExecute(array $render): array
    {
        [$sql, $params] = $render;

        $sql = preg_replace_callback(
            '~' . self::QUOTED_TOKEN_REGEX . '\K|:\w+~',
            static function ($matches) use ($params) {
                if ($matches[0] === '') {
                    return '';
                }

                $sql = $matches[0];
                $value = $params[$sql];

                // emulate bind param support for float type
                // TODO open php-src feature request
                if (is_float($value)) {
                    $sql = 'cast(' . $sql . ' as DOUBLE PRECISION)';
                }

                return $sql;
            },
            $sql
        );

        return parent::updateRenderBeforeExecute([$sql, $params]);
    }

    #[\Override]
    protected function _execute(?object $connection, bool $fromExecuteStatement)
    {
        // fix exception throwing for MSSQL TRY/CATCH SQL (for Query::$templateInsert)
        // https://github.com/microsoft/msphpsql/issues/1387
        if ($fromExecuteStatement && $connection instanceof DbalConnection) {
            // mimic https://github.com/doctrine/dbal/blob/3.7.1/src/Statement.php#L249
            $result = $this->_execute($connection, false);

            $driverResult = \Closure::bind(static fn (): DbalDriverPdoResult => $result->result, null, DbalResult::class)(); // @phpstan-ignore return.type
            $driverPdoResult = \Closure::bind(static fn () => $driverResult->statement, null, DbalDriverPdoResult::class)();
            try {
                while ($driverPdoResult->nextRowset());
            } catch (\PDOException $e) {
                $e = $connection->convertException(DbalDriverPdoException::new($e));

                $firstException = $e;
                while ($firstException->getPrevious() !== null) {
                    $firstException = $firstException->getPrevious();
                }
                $errorInfo = $firstException instanceof \PDOException ? $firstException->errorInfo : null;

                throw (new ExecuteException($e->getMessage(), $errorInfo[1] ?? $e->getCode(), $e))
                    ->addMoreInfo('query', $this->getDebugQuery());
            }

            return $result->rowCount();
        }

        return parent::_execute($connection, $fromExecuteStatement);
    }
}

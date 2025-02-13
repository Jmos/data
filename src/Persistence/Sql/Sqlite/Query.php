<?php

declare(strict_types=1);

namespace Atk4\Data\Persistence\Sql\Sqlite;

use Atk4\Data\Persistence\Sql\Query as BaseQuery;

class Query extends BaseQuery
{
    use ExpressionTrait;

    protected string $identifierEscapeChar = '`';
    protected string $expressionClass = Expression::class;

    protected string $templateTruncate = 'delete [from] [tableNoalias]';

    #[\Override]
    protected function _renderConditionBinaryReuse(
        string $sqlLeft,
        string $sqlRight,
        \Closure $makeSqlFx,
        bool $allowReuseLeft = true,
        bool $allowReuseRight = true,
        string $internalIdentifier = 'reuse'
    ): string {
        // https://sqlite.org/forum/info/c9970a37edf11cd1
        if (version_compare(Connection::getDriverVersion(), '3.45') < 0) {
            $allowReuseLeft = false;
            $allowReuseRight = false;
        }

        return parent::_renderConditionBinaryReuse(
            $sqlLeft,
            $sqlRight,
            $makeSqlFx,
            $allowReuseLeft,
            $allowReuseRight,
            $internalIdentifier
        );
    }

    private function _renderConditionBinaryCheckNumericSql(string $sql): string
    {
        return 'typeof(' . $sql . ') in (' . $this->escapeStringLiteral('integer')
            . ', ' . $this->escapeStringLiteral('real') . ')';
    }

    /**
     * https://dba.stackexchange.com/questions/332585/sqlite-comparison-of-the-same-operand-types-behaves-differently
     * https://sqlite.org/forum/info/5f1135146fbc37ab .
     */
    #[\Override] // @phpstan-ignore method.childParameterType (https://github.com/phpstan/phpstan/issues/10942)
    protected function _renderConditionBinary(string $operator, string $sqlLeft, $sqlRight): string
    {
        if (in_array($operator, ['in', 'not in'], true)) {
            if (is_array($sqlRight)) {
                return ($operator === 'not in' ? ' not' : '') . '('
                    . implode(' or ', array_map(fn ($v) => $this->_renderConditionBinary('=', $sqlLeft, $v), $sqlRight))
                    . ')';
            }

            $allowCastRight = false;
        } else {
            $allowCastRight = true;
        }

        return $this->_renderConditionBinaryReuse(
            $sqlLeft,
            $sqlRight,
            function ($sqlLeft, $sqlRight) use ($operator, $allowCastRight) {
                $res = 'case when ' . $this->_renderConditionBinaryCheckNumericSql($sqlLeft)
                    . ' then ' . parent::_renderConditionBinary($operator, 'cast(' . $sqlLeft . ' as numeric)', $sqlRight)
                    . ' else ';
                if ($allowCastRight) {
                    $res .= 'case when ' . $this->_renderConditionBinaryCheckNumericSql($sqlRight)
                        . ' then ' . parent::_renderConditionBinary($operator, $sqlLeft, 'cast(' . $sqlRight . ' as numeric)')
                        . ' else ';
                }
                $res .= parent::_renderConditionBinary($operator, $sqlLeft, $sqlRight);
                if ($allowCastRight) {
                    $res .= ' end';
                }
                $res .= ' end';

                return $res;
            },
            true,
            $allowCastRight,
            'affinity'
        );
    }

    private function _renderConditionIsCaseInsensitive(string $sql): string
    {
        return '(select __atk4_case_v__ = ' . $this->escapeStringLiteral('a')
            . ' from (select ' . $sql . ' __atk4_case_v__ where 0 union all select '
            . $this->escapeStringLiteral('A') . ') __atk4_case_tmp__)';
    }

    #[\Override]
    protected function _renderConditionLikeOperator(bool $negated, string $sqlLeft, string $sqlRight): string
    {
        return ($negated ? 'not ' : '') . $this->_renderConditionBinaryReuse(
            $sqlLeft,
            $sqlRight,
            function ($sqlLeft, $sqlRight) {
                $regexReplaceSqlFx = function (string $sql, string $search, string $replacement) {
                    return 'regexp_replace(' . $sql . ', ' . $this->escapeStringLiteral($search) . ', ' . $this->escapeStringLiteral($replacement) . ')';
                };

                return 'case '
                    // workaround "_" matching more than one byte in BLOB - https://dbfiddle.uk/Dnq8BXGy
                    . 'case when instr(' . $sqlRight . ', ' . $this->escapeStringLiteral('_') . ') != 0 then 1 else '
                    . parent::_renderConditionLikeOperator(
                        false,
                        $sqlLeft,
                        $sqlRight
                    ) . ' end when 1 then ' . $this->_renderConditionRegexpOperator(
                        false,
                        $sqlLeft,
                        'concat(' . $this->escapeStringLiteral('^') . ',' . $regexReplaceSqlFx(
                            $regexReplaceSqlFx(
                                $regexReplaceSqlFx(
                                    $regexReplaceSqlFx($sqlRight, '\\\(?:(?=[_%])|\K\\\)|(?=[.\\\+*?[^\]$(){}|])', '\\\\'),
                                    '(?<!\\\)(\\\\\\\)*\K_',
                                    '.'
                                ),
                                '(?<!\\\)(\\\\\\\)*\K%',
                                '.*'
                            ),
                            '(?<!\\\)(\\\\\\\)*\K\\\(?=[_%])',
                            ''
                        ) . ', ' . $this->escapeStringLiteral('$') . ')'
                    ) . ' when 0 then 0 end';
            }
        );
    }

    #[\Override]
    protected function _renderConditionRegexpOperator(bool $negated, string $sqlLeft, string $sqlRight, bool $binary = false): string
    {
        return ($negated ? 'not ' : '') . $this->_renderConditionBinaryReuse(
            $sqlLeft,
            $sqlRight,
            function ($sqlLeft, $sqlRight) {
                return 'regexp_like(' . $sqlLeft . ', ' . $sqlRight
                    . ', case when ' . $this->_renderConditionIsCaseInsensitive($sqlLeft)
                    . ' then ' . $this->escapeStringLiteral('is')
                    . ' else ' . $this->escapeStringLiteral('-us')
                    . ' end)';
            },
            true,
            false
        );
    }

    #[\Override]
    public function groupConcat($field, string $separator = ',')
    {
        return $this->expr('group_concat({}, [])', [$field, $separator]);
    }
}

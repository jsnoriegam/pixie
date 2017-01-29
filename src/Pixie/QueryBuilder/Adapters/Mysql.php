<?php namespace Pixie\QueryBuilder\Adapters;

class Mysql extends BaseAdapter
{
    /**
     * @var string
     */
    const SANITIZER = '`';

    /**
     * Build delete query
     *
     * @param $statements
     *
     * @return array
     * @throws Exception
     */
    public function delete($statements)
    {
        $table = end($statements['tables']);

        // Wheres
        list($whereCriteria, $whereBindings) = $this->buildCriteriaWithType($statements, 'wheres', 'WHERE');

        // Limit
        $limit = isset($statements['limit']) ? 'LIMIT ' . $statements['limit'] : '';

        $sqlArray = array(
            'DELETE FROM',
            $this->wrapSanitizer($table),
            $whereCriteria,
            $limit
        );

        $sql = $this->concatenateQuery($sqlArray);

        $bindings = $whereBindings;
        return compact('sql', 'bindings');
    }
}

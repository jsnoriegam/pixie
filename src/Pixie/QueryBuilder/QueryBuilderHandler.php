<?php namespace Pixie\QueryBuilder;

use PDO;
use Pixie\Connection;
use Pixie\Exception;

class QueryBuilderHandler
{

    /**
     * @var \Viocon\Container
     */
    protected $container;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var array
     */
    protected $statements = [];

    /**
     * @var \PDO
     */
    protected $pdo;

    /**
     * @var null|\PDOStatement
     */
    protected $pdoStatement;

    /**
     * @var null|string
     */
    protected $tablePrefix;

    /**
     * @var \Pixie\QueryBuilder\Adapters\BaseAdapter
     */
    protected $adapterInstance;

    /**
     * The PDO fetch parameters to use
     *
     * @var array
     */
    protected $fetchParameters = [\PDO::FETCH_OBJ];

    /**
     * @var null|string
     */
    protected $lastAction;

    /**
     * @var null|array
     */
    protected $lastData;

    /**
     * @var null|QueryObject
     */
    protected $lastQuery;
    
    /**
     * @var boolean
     */
    protected $isCount = false;

    /**
     * @param null|\Pixie\Connection $connection
     *
     * @throws \Pixie\Exception
     */
    public function __construct(Connection $connection = null)
    {
        if ($connection === null && ($connection = Connection::getStoredConnection()) === false) {
            throw new Exception('No database connection found.', 1);
        }

        $this->connection = $connection;
        $this->container = $this->connection->getContainer();
        $this->pdo = $this->connection->getPdoInstance();
        $this->adapter = $this->connection->getAdapter();
        $this->adapterConfig = $this->connection->getAdapterConfig();

        if (isset($this->adapterConfig['prefix'])) {
            $this->tablePrefix = $this->adapterConfig['prefix'];
        }

        // Query builder adapter instance
        $this->adapterInstance = $this->container->build(
            '\Pixie\QueryBuilder\Adapters\\' . ucfirst($this->adapter),
            [$this->connection]
        );

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Set the fetch mode
     *
     * @param $mode
     * @return $this
     */
    public function setFetchMode($mode)
    {
        $this->fetchParameters = func_get_args();
        return $this;
    }

    /**
     * Fetch query results as object of specified type
     *
     * @param $className
     * @param array $constructorArgs
     * @return QueryBuilderHandler
     */
    public function asObject($className, $constructorArgs = [])
    {
        return $this->setFetchMode(\PDO::FETCH_CLASS, $className, $constructorArgs);
    }

    /**
     * @param null|\Pixie\Connection $connection
     * @throws Exception
     * @return static
     */
    public function newQuery(Connection $connection = null)
    {
        if ($connection === null) {
            $connection = $this->connection;
        }

        return new static($connection);
    }

    /**
     * @param       $sql
     * @param array $bindings
     *
     * @return $this
     */
    public function query($sql, $bindings = [])
    {
        list($this->pdoStatement) = $this->statement($sql, $bindings);

        return $this;
    }

    //public function addPrefix($table, $prefix) {
    //    $this->addStatement('prefixes', [$table => strtolower($prefix)]);
    //    return $this;
    //}

    /**
     * @param       $sql
     * @param array $bindings
     *
     * @return array PDOStatement and execution time as float
     */
    public function statement($sql, $bindings = [])
    {
        $start = microtime(true);
        $pdoStatement = $this->pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $pdoStatement->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                is_int($value) || is_bool($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $pdoStatement->execute();
        return [$pdoStatement, microtime(true) - $start];
    }

    /**
     * Get all rows
     * @throws Exception
     * @return \stdClass|null
     */
    public function get()
    {
        if(!$this->isCount) {//we ignore events inside count()
            $eventResult = $this->fireEvents('before-select');
            //
            if ($eventResult !== null) {
                return $eventResult;
            }
        }

        $executionTime = 0;
        if ($this->pdoStatement === null) {
            $queryObject = $this->getQuery('select');
            list($this->pdoStatement, $executionTime) = $this->statement(
                $queryObject->getSql(),
                $queryObject->getBindings()
            );
        }

        $start = microtime(true);
        $result = call_user_func_array(array($this->pdoStatement, 'fetchAll'), $this->fetchParameters);
        $executionTime += microtime(true) - $start;
        $this->pdoStatement = null;
        if(!$this->isCount) {
            $this->fireEvents('after-select', $result, $executionTime);
        }
        return $result;
    }

    /**
     * Get first row
     * @throws Exception
     * @return \stdClass|null
     */
    public function first()
    {
        $this->limit(1);
        $result = $this->get();
        return empty($result) ? null : $result[0];
    }

    /**
     * @param        $value
     * @param string $fieldName
     * @throws Exception
     * @return null|\stdClass
     */
    public function findAll($fieldName, $value)
    {
        $this->where($fieldName, '=', $value);
        return $this->get();
    }

    /**
     * @param        $value
     * @param string $fieldName
     * @throws Exception
     * @return null|\stdClass
     */
    public function find($value, $fieldName = 'id')
    {
        $this->where($fieldName, '=', $value);
        return $this->first();
    }

    /**
     * Get count of rows
     * @throws Exception
     * @return int
     */
    public function count()
    {
        // Get the current statements
        $originalStatements = $this->statements;

        unset($this->statements['orderBys'], $this->statements['limit'], $this->statements['offset']);

        $this->isCount = true;
        $count = $this->aggregate('count');
        $this->isCount = false;
        $this->statements = $originalStatements;

        return $count;
    }

    /**
     * @param $type
     * @throws Exception
     * @return int
     */
    protected function aggregate($type)
    {
        // Get the current selects
        $mainSelects = isset($this->statements['selects']) ? $this->statements['selects'] : null;
        // Replace select with a scalar value like `count`
        $field = '__' . $type .  '__';
        $this->statements['selects'] = [$this->raw($type . '(*) as ' . $field)];
        $row = $this->get();

        // Set the select as it was
        if ($mainSelects) {
            $this->statements['selects'] = $mainSelects;
        } else {
            unset($this->statements['selects']);
        }

        if(isset($row[0])) {
            if (is_array($row[0])) {
                return (int)$row[0][$field];
            } elseif (is_object($row[0])) {
                return (int)$row[0]->$field;
            }
        }

        return 0;
    }

    /**
     * @param string $action
     * @param array|bool  $dataToBePassed
     *
     * @return mixed
     * @throws Exception
     */
    public function getQuery($action = null, $data = null)
    {
        $action = $action ? strtolower($action) : $this->lastAction;
        $data = $data ? $data : $this->lastData;

        if(!$action) {
            throw new Exception('An action is required.', 2);
        }

        $allowedActions = ['select', 'insert', 'insertignore', 'replace', 'delete', 'update', 'criteriaonly'];
        if (!in_array($action, $allowedActions)) {
            throw new Exception($action . ' is not a known type.', 2);
        }

        $queryArr = $this->adapterInstance->$action($this->statements, $data);

        $this->lastAction = $action;
        $this->lastData = in_array($action, ['select', 'delete']) ? null : $data;
        
        $this->lastQuery = $queryObject = $this->container->build(
            '\Pixie\QueryBuilder\QueryObject',
            [$queryArr['sql'], $queryArr['bindings'], $this->pdo]
        );
        return $queryObject;
    }

    /**
     * @param QueryBuilderHandler $queryBuilder
     * @param null                $alias
     * @throws Exception
     * @return Raw
     */
    public function subQuery(QueryBuilderHandler $queryBuilder, $alias = null)
    {
        $sql = '(' . $queryBuilder->getQuery('select')->getRawSql() . ')';
        if ($alias) {
            $sql = $sql . ' as ' . $alias;
        }

        return $queryBuilder->raw($sql);
    }

    /**
     * @param $data
     * @throws Exception
     * @return array|string
     */
    private function doInsert($data, $type)
    {
        $eventResult = $this->fireEvents('before-insert');
        if ($eventResult !== null) {
            return $eventResult;
        }

        // If first value is not an array
        // Its not a batch insert
        if (!is_array(current($data))) {
            $queryObject = $this->getQuery($type, $data);

            list($result, $executionTime) = $this->statement($queryObject->getSql(), $queryObject->getBindings());

            $return = $result->rowCount() === 1 ? $this->pdo->lastInsertId() : null;
        } else {
            // Its a batch insert
            $return = [];
            $executionTime = 0;
            foreach ($data as $subData) {
                $queryObject = $this->getQuery($type, $subData);

                list($result, $time) = $this->statement($queryObject->getSql(), $queryObject->getBindings());
                $executionTime += $time;

                if ($result->rowCount() === 1) {
                    $return[] = $this->pdo->lastInsertId();
                }
            }
        }

        $this->fireEvents('after-insert', $return, $executionTime);

        return $return;
    }

    /**
     * @param $data
     * @throws Exception
     * @return array|string
     */
    public function insert($data)
    {
        return $this->doInsert($data, 'insert');
    }

    /**
     * @param $data
     * @throws Exception
     * @return array|string
     */
    public function insertIgnore($data)
    {
        return $this->doInsert($data, 'insertignore');
    }

    /**
     * @param $data
     * @throws Exception
     * @return array|string
     */
    public function replace($data)
    {
        return $this->doInsert($data, 'replace');
    }

    /**
     * @param $data
     * @throws Exception
     * @return $this
     */
    public function update($data)
    {
        $eventResult = $this->fireEvents('before-update');
        if ($eventResult !== null) {
            return $eventResult;
        }

        $queryObject = $this->getQuery('update', $data);

        list($response, $executionTime) = $this->statement($queryObject->getSql(), $queryObject->getBindings());
        $this->fireEvents('after-update', $queryObject, $executionTime);

        return $response;
    }

    /**
     * @param $data
     * @throws Exception
     * @return array|string
     */
    public function updateOrInsert($data)
    {
        if ($this->first()) {
            return $this->update($data);
        } else {
            return $this->insert($data);
        }
    }

    /**
     * @param $data
     *
     * @return $this
     */
    public function onDuplicateKeyUpdate($data)
    {
        $this->addStatement('onduplicate', $data);
        return $this;
    }

    /**
     * @throws Exception
     */
    public function delete()
    {
        $eventResult = $this->fireEvents('before-delete');
        if ($eventResult !== null) {
            return $eventResult;
        }

        $queryObject = $this->getQuery('delete');

        list($response, $executionTime) = $this->statement($queryObject->getSql(), $queryObject->getBindings());
        $this->fireEvents('after-delete', $queryObject, $executionTime);

        return $response;
    }

    /**
     * @param $tables array|string Single table or multiple tables as an array or as multiple parameters
     * @throws Exception
     * @return static
     */
    public function table($tables)
    {
        if (!is_array($tables)) {
            // because a single table is converted to an array anyways,
            // this makes sense.
            $tables = func_get_args();
        }

        $instance = new static($this->connection);
        $tables = $this->addTablePrefix($tables);
        $instance->addStatement('tables', $tables);
        return $instance;
    }

    /**
     * @param $tables
     *
     * @return $this
     */
    public function from($tables)
    {
        if (!is_array($tables)) {
            $tables = func_get_args();
        }

        $tables = $this->addTablePrefix($tables);
        $this->addStatement('tables', $tables);
        return $this;
    }

    /**
     * @param $fields
     *
     * @return $this
     */
    public function select($fields)
    {
        if (!is_array($fields)) {
            $fields = func_get_args();
        }

        $fields = $this->addFieldPrefix($fields);
        $this->addStatement('selects', $fields);
        return $this;
    }

    /**
     * @param $fields
     *
     * @return $this
     */
    public function selectDistinct($fields)
    {
        $this->select($fields);
        $this->addStatement('distinct', true);
        return $this;
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function groupBy($field)
    {
        $field = $this->addFieldPrefix($field);
        $this->addStatement('groupBys', $field);
        return $this;
    }

    /**
     * @param        $fields
     * @param string $defaultDirection
     *
     * @return $this
     */
    public function orderBy($fields, $defaultDirection = 'ASC')
    {
        if (!is_array($fields)) {
            $fields = [$fields];
        }

        foreach ($fields as $key => $value) {
            $field = $key;
            $type = $value;
            if (is_int($key)) {
                $field = $value;
                $type = $defaultDirection;
            }
            if (!$field instanceof Raw) {
                $field = $this->addFieldPrefix($field);
            }
            $this->statements['orderBys'][] = compact('field', 'type');
        }

        return $this;
    }

    /**
     * @param $limit
     *
     * @return $this
     */
    public function limit($limit)
    {
        $this->statements['limit'] = $limit;
        return $this;
    }

    /**
     * @param $offset
     *
     * @return $this
     */
    public function offset($offset)
    {
        $this->statements['offset'] = $offset;
        return $this;
    }

    /**
     * @param        $key
     * @param        $operator
     * @param        $value
     * @param string $joiner
     *
     * @return $this
     */
    public function having($key, $operator, $value, $joiner = 'AND')
    {
        $key = $this->addFieldPrefix($key);
        $this->statements['havings'][] = compact('key', 'operator', 'value', 'joiner');
        return $this;
    }

    /**
     * @param        $key
     * @param        $operator
     * @param        $value
     *
     * @return $this
     */
    public function orHaving($key, $operator, $value)
    {
        return $this->having($key, $operator, $value, 'OR');
    }

    /**
     * @param $key
     * @param $operator
     * @param $value
     *
     * @return $this
     */
    public function where($key, $operator = null, $value = null)
    {
        // If two params are given then assume operator is =
        if (func_num_args() == 2) {
            $value = $operator;
            $operator = '=';
        }

        if(is_bool($value)) {
            $value = (int)$value;
        }

        return $this->whereHandler($key, $operator, $value);
    }

    /**
     * @param $key
     * @param $operator
     * @param $value
     *
     * @return $this
     */
    public function orWhere($key, $operator = null, $value = null)
    {
        // If two params are given then assume operator is =
        if (func_num_args() == 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->whereHandler($key, $operator, $value, 'OR');
    }

    /**
     * @param $key
     * @param $operator
     * @param $value
     *
     * @return $this
     */
    public function whereNot($key, $operator = null, $value = null)
    {
        // If two params are given then assume operator is =
        if (func_num_args() == 2) {
            $value = $operator;
            $operator = '=';
        }
        return $this->whereHandler($key, $operator, $value, 'AND NOT');
    }

    /**
     * @param $key
     * @param $operator
     * @param $value
     *
     * @return $this
     */
    public function orWhereNot($key, $operator = null, $value = null)
    {
        // If two params are given then assume operator is =
        if (func_num_args() == 2) {
            $value = $operator;
            $operator = '=';
        }
        return $this->whereHandler($key, $operator, $value, 'OR NOT');
    }

    /**
     * @param       $key
     * @param array $values
     *
     * @return $this
     */
    public function whereIn($key, $values)
    {
        return $this->whereHandler($key, 'IN', $values, 'AND');
    }

    /**
     * @param       $key
     * @param array $values
     *
     * @return $this
     */
    public function whereNotIn($key, $values)
    {
        return $this->whereHandler($key, 'NOT IN', $values, 'AND');
    }

    /**
     * @param       $key
     * @param array $values
     *
     * @return $this
     */
    public function orWhereIn($key, $values)
    {
        return $this->whereHandler($key, 'IN', $values, 'OR');
    }

    /**
     * @param       $key
     * @param array $values
     *
     * @return $this
     */
    public function orWhereNotIn($key, $values)
    {
        return $this->whereHandler($key, 'NOT IN', $values, 'OR');
    }

    /**
     * @param $key
     * @param $valueFrom
     * @param $valueTo
     *
     * @return $this
     */
    public function whereBetween($key, $valueFrom, $valueTo)
    {
        return $this->whereHandler($key, 'BETWEEN', [$valueFrom, $valueTo], 'AND');
    }

    /**
     * @param $key
     * @param $valueFrom
     * @param $valueTo
     *
     * @return $this
     */
    public function orWhereBetween($key, $valueFrom, $valueTo)
    {
        return $this->whereHandler($key, 'BETWEEN', [$valueFrom, $valueTo], 'OR');
    }

    /**
     * @param $key
     * @return QueryBuilderHandler
     */
    public function whereNull($key)
    {
        return $this->whereNullHandler($key);
    }

    /**
     * @param $key
     * @return QueryBuilderHandler
     */
    public function whereNotNull($key)
    {
        return $this->whereNullHandler($key, 'NOT');
    }

    /**
     * @param $key
     * @return QueryBuilderHandler
     */
    public function orWhereNull($key)
    {
        return $this->whereNullHandler($key, '', 'or');
    }

    /**
     * @param $key
     * @return QueryBuilderHandler
     */
    public function orWhereNotNull($key)
    {
        return $this->whereNullHandler($key, 'NOT', 'or');
    }

    protected function whereNullHandler($key, $prefix = '', $operator = '')
    {
        $key = $this->adapterInstance->wrapSanitizer($this->addFieldPrefix($key));
        return $this->{$operator . 'Where'}($this->raw("{$key} IS {$prefix} NULL"));
    }

    /**
     * @param        $table
     * @param        $key
     * @param        $operator
     * @param        $value
     * @param string $type
     *
     * @return $this
     */
    public function join($table, $key, $operator = null, $value = null, $type = 'inner')
    {
        if (!$key instanceof \Closure) {
            $key = function ($joinBuilder) use ($key, $operator, $value) {
                $joinBuilder->on($key, $operator, $value);
            };
        }

        // Build a new JoinBuilder class, keep it by reference so any changes made
        // in the closure should reflect here
        $joinBuilder = $this->container->build(\Pixie\QueryBuilder\JoinBuilder::class, [$this->connection]);
        $joinBuilder = &$joinBuilder;

        // Call the closure with our new joinBuilder object
        $key($joinBuilder);
        $table = $this->addTablePrefix($table);
        // Get the criteria only query from the joinBuilder object
        $this->statements['joins'][] = compact('type', 'table', 'joinBuilder');

        return $this;
    }

    /**
     * Runs a transaction
     *
     * @param $callback
     *
     * @return $this
     */
    public function transaction(\Closure $callback)
    {
        try {
            // Begin the PDO transaction
            $this->pdo->beginTransaction();

            // Get the Transaction class
            $transaction = $this->container->build(\Pixie\QueryBuilder\Transaction::class, [$this->connection]);

            // Call closure
            $result = $callback($transaction);

            if($transaction->getStatus() !== 'auto') {
                return $result;
            }

            // If no errors have been thrown or the transaction wasn't completed within
            // the closure, commit the changes
            $this->pdo->commit();

            return $result;
        } catch (\Exception $e) {
            // something happened, rollback changes
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param      $table
     * @param      $key
     * @param null $operator
     * @param null $value
     *
     * @return $this
     */
    public function leftJoin($table, $key, $operator = null, $value = null)
    {
        return $this->join($table, $key, $operator, $value, 'left');
    }

    /**
     * @param      $table
     * @param      $key
     * @param null $operator
     * @param null $value
     *
     * @return $this
     */
    public function rightJoin($table, $key, $operator = null, $value = null)
    {
        return $this->join($table, $key, $operator, $value, 'right');
    }

    /**
     * @param      $table
     * @param      $key
     * @param null $operator
     * @param null $value
     *
     * @return $this
     */
    public function innerJoin($table, $key, $operator = null, $value = null)
    {
        return $this->join($table, $key, $operator, $value, 'inner');
    }

    /**
     * Add a raw query
     *
     * @param $value
     * @param $bindings
     *
     * @return mixed
     */
    public function raw($value, $bindings = [])
    {
        return $this->container->build(\Pixie\QueryBuilder\Raw::class, [$value, $bindings]);
    }

    /**
     * Return PDO instance
     *
     * @return PDO
     */
    public function pdo()
    {
        return $this->pdo;
    }

    /**
     * @param Connection $connection
     *
     * @return $this
     */
    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * @return Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @param        $key
     * @param        $operator
     * @param        $value
     * @param string $joiner
     *
     * @return $this
     */
    protected function whereHandler($key, $operator = null, $value = null, $joiner = 'AND')
    {
        $key = $this->addFieldPrefix($key);
        $this->statements['wheres'][] = compact('key', 'operator', 'value', 'joiner');
        return $this;
    }

    /**
     * Add table prefix (if given any). for fields using table.field notation
     *
     * @param $values
     *
     * @return array|string
     */
    public function addFieldPrefix($values) {
        if ($this->tablePrefix === null) {
            return $values;
        }

        $single = false;
        if (!is_array($values)) {
            $values = [$values];
            // We had single value, so should return a single value
            $single = true;
        }

        $return = [];
        foreach ($values as $key => $value) {
            if ($value instanceof Raw || $value instanceof \Closure) {
                $return[$key] = $value;
                continue;
            }

            $target = &$value;
            if(!is_int($key)) {
                $target = &$key;
            }
            if(strpos($target, '.') !== false) {
                $target = $this->tablePrefix . $target;
            }
            $return[$key] = $value;
        }
        return $single ? end($return) : $return;
    }

    /**
     * Add table prefix (if given any).
     * Prefix is added to table aliases as well
     *
     * @param $values
     *
     * @return array|string
     */
    public function addTablePrefix($values)
    {
        if ($this->tablePrefix === null) {
            return $values;
        }

        $single = false;
        if (!is_array($values)) {
            $values = [$values];
            // We had single value, so should return a single value
            $single = true;
        }

        $return = [];
        foreach ($values as $key => $value) {
            if ($value instanceof Raw || $value instanceof \Closure) {
                $return[$key] = $value;
                continue;
            }

            if(!is_int($key)) {
                $return[$this->tablePrefix . $key] = $this->tablePrefix . $value;
            } else {
                $return[$key] = $this->tablePrefix . $value;
            }
        }
        return $single ? end($return) : $return;
    }

    /**
     * @param $key
     * @param $value
     */
    protected function addStatement($key, $value)
    {
        if (!array_key_exists($key, $this->statements)) {
            $this->statements[$key] = (array)$value;
        } else {
            $this->statements[$key] = array_merge($this->statements[$key], (array)$value);
        }
    }

    /**
     * @param $event
     * @param $table
     *
     * @return callable|null
     */
    public function getEvent($event, $table = ':any')
    {
        return $this->connection->getEventHandler()->getEvent($event, $table);
    }

    /**
     * @param          $event
     * @param string   $table
     * @param callable $action
     *
     * @return void
     */
    public function registerEvent($event, $table, \Closure $action)
    {
        $table = $table ?: ':any';

        if ($table !== ':any') {
            $table = $this->addTablePrefix($table);
        }

        $this->connection->getEventHandler()->registerEvent($event, $table, $action);
    }

    /**
     * @param          $event
     * @param string   $table
     *
     * @return void
     */
    public function removeEvent($event, $table = ':any')
    {
        if ($table !== ':any') {
            $table = $this->addTablePrefix($table);
        }

        $this->connection->getEventHandler()->removeEvent($event, $table);
    }

    /**
     * @param      $event
     * @return mixed
     */
    public function fireEvents($event)
    {
        $params = func_get_args();
        array_unshift($params, $this);
        return call_user_func_array(array($this->connection->getEventHandler(), 'fireEvents'), $params);
    }

    /**
     * @return array
     */
    public function getStatements()
    {
        return $this->statements;
    }
    
    public function getLastQuery() {
        return $this->lastQuery;
    }
}
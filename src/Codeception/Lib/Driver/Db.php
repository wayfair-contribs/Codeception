<?php
namespace Codeception\Lib\Driver;

class Db
{
    /**
     * @var \PDO
     */
    protected $dbh;

    /**
     * @var string
     */
    protected $dsn;

    protected $user;
    protected $password;

    /**
     * @var string
     */
    public $sqlToRun;

    /**
     * associative array with table name => primary-key
     *
     * @var array
     */
    protected $primaryColumns = [];

    public static function connect($dsn, $user, $password)
    {
        $dbh = new \PDO($dsn, $user, $password);
        $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $dbh;
    }

    /**
     * @static
     *
     * @param $dsn
     * @param $user
     * @param $password
     *
     * @return Db|MsSql|MySql|Oracle|PostgreSql|Sqlite
     */
    public static function create($dsn, $user, $password)
    {
        $provider = self::getProvider($dsn);

        switch ($provider) {
            case 'sqlite':
                return new Sqlite($dsn, $user, $password);
            case 'mysql':
                return new MySql($dsn, $user, $password);
            case 'pgsql':
                return new PostgreSql($dsn, $user, $password);
            case 'mssql':
                return new MsSql($dsn, $user, $password);
            case 'oracle':
                return new Oracle($dsn, $user, $password);
            case 'sqlsrv':
                return new SqlSrv($dsn, $user, $password);
            case 'oci':
                return new Oci($dsn, $user, $password);
            default:
                return new Db($dsn, $user, $password);
        }
    }

    public static function getProvider($dsn)
    {
        return substr($dsn, 0, strpos($dsn, ':'));
    }

    public function __construct($dsn, $user, $password)
    {
        $this->dbh = new \PDO($dsn, $user, $password);
        $this->dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->dsn = $dsn;
        $this->user = $user;
        $this->password = $password;
    }

    public function getDbh()
    {
        return $this->dbh;
    }

    public function getDb()
    {
        $matches = [];
        $matched = preg_match('~dbname=(.*);~s', $this->dsn, $matches);
        if (!$matched) {
            return false;
        }

        return $matches[1];
    }

    public function cleanup()
    {
    }

    public function load($sql)
    {
        $query = '';
        $delimiter = ';';
        $delimiterLength = 1;

        foreach ($sql as $sqlLine) {
            if (preg_match('/DELIMITER ([\;\$\|\\\\]+)/i', $sqlLine, $match)) {
                $delimiter = $match[1];
                $delimiterLength = strlen($delimiter);
                continue;
            }

            $parsed = $this->sqlLine($sqlLine);
            if ($parsed) {
                continue;
            }

            $query .= "\n" . rtrim($sqlLine);

            if (substr($query, -1 * $delimiterLength, $delimiterLength) == $delimiter) {
                $this->sqlToRun = substr($query, 0, -1 * $delimiterLength);
                $this->sqlQuery($this->sqlToRun);
                $query = "";
            }
        }
    }

    public function insert($tableName, array &$data)
    {
        $columns = array_map(
            [$this, 'getQuotedName'],
            array_keys($data)
        );

        return sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->getQuotedName($tableName),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($data), '?'))
        );
    }

    public function select($column, $table, array &$criteria)
    {
        $where = $this->generateWhereClause($criteria);

        $query = "select %s from %s %s";
        return sprintf($query, $column, $this->getQuotedName($table), $where);
    }

    protected function generateWhereClause(array &$criteria)
    {
        if (empty($criteria)) {
            return '';
        }

        $params = [];
        foreach ($criteria as $k => $v) {
            if ($v === null) {
                $params[] = $this->getQuotedName($k) . " IS NULL ";
                unset($criteria[$k]);
            } else {
                $params[] = $this->getQuotedName($k) . " = ? ";
            }
        }

        return 'WHERE ' . implode('AND ', $params);
    }

    public function deleteQuery($table, $id, $primaryKey = 'id')
    {
        $query = 'DELETE FROM ' . $this->getQuotedName($table) . ' WHERE ' . $this->getQuotedName($primaryKey) . ' = ?';
        $this->executeQuery($query, [$id]);
    }

    public function lastInsertId($table)
    {
        return $this->getDbh()->lastInsertId();
    }

    public function getQuotedName($name)
    {
        return '"' . str_replace('.', '"."', $name) . '"';
    }

    protected function sqlLine($sql)
    {
        $sql = trim($sql);
        return (
            $sql === ''
            || $sql === ';'
            || preg_match('~^((--.*?)|(#))~s', $sql)
        );
    }

    protected function sqlQuery($query)
    {
        $this->dbh->exec($query);
    }

    public function executeQuery($query, array $params)
    {
        $sth = $this->dbh->prepare($query);
        if (!$sth) {
            $this->fail("Query '$query' can't be prepared.");
        }

        $sth->execute($params);
        return $sth;
    }

    /**
     * @param string $tableName
     *
     * @return string
     */
    public function getPrimaryColumn($tableName)
    {
        return 'id';
    }

    /**
     * @return bool
     */
    protected function flushPrimaryColumnCache()
    {
        $this->primaryColumns = [];

        return empty($this->primaryColumns);
    }
}

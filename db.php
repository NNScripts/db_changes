<?php
require_once(WWW_DIR . "/lib/framework/cache.php");

class DB
{
    /**
     * The database connection
     * @var null|PDO
     */
    private static $instance = null;

    /**
     * The database constructor
     */
    public function __construct()
    {
        if( !(self::$instance instanceof PDO ) )
        {
            $dbconnstring = sprintf(
                "%s:host=%s;dbname=%s%s",
                DB_TYPE,
                DB_HOST,
                DB_NAME,
                ( defined('DB_PORT') ? ";port=". DB_PORT : "" )
            );
            $errmode = defined('DB_ERRORMODE') ? DB_ERRORMODE : PDO::ERRMODE_SILENT;

            try {
                self::$instance = new PDO(
                    $dbconnstring, DB_USER, DB_PASSWORD, array(
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_ERRMODE => $errmode,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
                    )
                );

                if (defined('DB_PCONNECT') && DB_PCONNECT)
                {
                    self::$instance->setAttribute(PDO::ATTR_PERSISTENT, true);
                }
            } catch(PDOException $e) {
                die("fatal error: could not connect to database! Check your config. ".$e);
            }
        }
    }

    /**
     * Get the plain PDO connection
     *
     * @return PDO
     */
    public function getPDO()
    {
        return self::$instance;
    }

    /**
     * Call functions from PDO
     *
     * @return mixed
     */
    public function __call($function, $args)
    {
        if( method_exists( self::$instance, $function ) )
        {
            return call_user_func_array( array( self::$instance, $function ), $args );
        }
        trigger_error( "Unknown PDO Method Called: $function()\n", E_USER_ERROR );
    }

    /**
     * Escape a string using PDO
     *
     * @param string $str
     * @return string
     */
    public function escapeString($str)
    {
        return self::$instance->quote($str);
    }

    /**
     * Execute a query and return the result or last inserted id
     * 
     * @param string $query
     * @param bool $returnlastid
     * @return bool|int
     */
    public function queryInsert($query, $returnlastid = true)
    {
        if($query=="")
            return false;

        if (DB_TYPE == "mysql")
        {
            //$result = $this->exec(utf8_encode($query));
            $result = self::$instance->exec($query);
            return ($returnlastid) ? self::$instance->lastInsertId() : $result;
        }
        elseif (DB_TYPE == "postgres")
        {
            $p = self::$instance->prepare($query.' RETURNING id');
            $p->execute();
            return $p->fetchColumn();
        }
    }

    /**
     * Perform a query and return the first result
     *
     * @param string $query
     * @param bool $useCache
     * @param string|int $cacheTTL
     * @return bool|array
     */
    public function queryOneRow($query, $useCache = false, $cacheTTL = '')
    {
        if($query=="")
            return false;

        $rows = $this->query($query, $useCache, $cacheTTL);
        return ($rows ? $rows[0] : false);
    }

    /**
     * Perform a single query
     *
     * @param string $query
     * @param bool $useCache
     * @param string|int $cacheTTL
     * @return bool|array
     */
    public function query($query, $useCache = false, $cacheTTL = '')
    {
        if($query=="")
            return false;

        if ($useCache) {
            $cache = new Cache();
            if ($cache->enabled && $cache->exists($query)) {
                $ret = $cache->fetch($query);
                if ($ret !== false)
                    return $ret;
            }
        }

        //$result = self::$instance->query($query)->fetchAll();
        try {
            $result = false;
            $stmt = self::$instance->prepare( $query );
            $stmt->execute();
            if( 0 == $stmt->errorCode() )
            {
                $result = $stmt->fetchAll();
            }
            else
            {
                $errorInfo = $stmt->errorInfo();
                throw new PDOException( sprintf("%s - %s", $errorInfo[1], $errorInfo[2] ) );
            }
        } catch(PDOException $e) {
            $now = new DateTime();
            $errorLogFile = WWW_DIR . DIRECTORY_SEPARATOR .'..'. DIRECTORY_SEPARATOR .'sql_errors.log';
            $fh = fopen( $errorLogFile, 'a' );
            fwrite( $fh, sprintf( "%s - %s". PHP_EOL, $now->format('r'), $e->getMessage() ) );
            fwrite( $fh, sprintf( "%s - Query: %s". PHP_EOL, $now->format('r'), preg_replace('/[\s]{2,}/', ' ', preg_replace('/[\n\r]/', ' ', $query) ) ) );
            fclose( $fh );

            // Show the error
            echo sprintf( "[Logged]: %s". PHP_EOL, $e->getMessage() );

            // If you want the script to terminate after receiving an sql error uncomment the following line
            //die();
        } 

        if ($result === false || $result === true)
            return array();

        if ($useCache)
            if ($cache->enabled)
                $cache->store($query, $result, $cacheTTL);

        return $result;
    }

    /**
     * Execute a query
     *
     * @param string $query
     * @return bool|PDOStatement
     */
    public function queryDirect($query)
    {
        if($query=="")
            return false;

        return self::$instance->query($query);
    }

    /**
     * Get the total number of rows in the result set
     *
     * @param PDOStatement $result
     * @return int
     */
    public function getNumRows(PDOStatement $result)
    {
        return $result->rowCount();
    }

    /**
     * Fetch a assoc row from a result set
     *
     * @param PDOStatement $result
     * @return array
     */
    public function getAssocArray(PDOStatement $result)
    {
        return $result->fetch();
    }

    /**
     * Optimize the database
     *
     * @return array
     */
    public function optimise($force = false)
    {
        $ret = array();
        if ($force)
            $alltables = $this->query("show table status");
        else
            $alltables = $this->query("show table status where Data_free != 0");

        foreach ($alltables as $tablename)
        {
            $ret[] = $tablename['Name'];
            if (strtolower($tablename['Engine']) == "myisam")
                $this->queryDirect("REPAIR TABLE `" . $tablename['Name'] . "` USE_FRM");

            $this->queryDirect("OPTIMIZE TABLE `" . $tablename['Name'] . "`");
            $this->queryDirect("ANALYZE TABLE `" . $tablename['Name'] . "`");
        }

        $nulltables = $this->query("select table_name from information_schema.TABLES where table_schema = '".DB_NAME."' and engine is null");
        foreach ($nulltables as $tablename)
        {
            $ret[] = $tablename['table_name'];
            $this->queryDirect("REPAIR TABLE `" . $tablename['table_name'] . "` USE_FRM");
        }

        return $ret;
    }
}

<?php
class DB_Manager extends PDO {
	private $error;
	private $sql;
	private $bind;
	private $errorCallbackFunction;
	private $errorMsgFormat;
    private static $_instance;
    
    /**
     * Ritorna l'istanza condivisa della connessione al db
     * @return DB
     */    
    public static function getInstance() {
        if ( !(self::$_instance instanceof self) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
	public function __construct($host="", $user="", $passwd="") {        
        global $_CONFIG;
        
        if($host==''){
            $host = 'mysql:host='.$_CONFIG['host'].';dbname='.$_CONFIG['dbname'].';charset=utf8';
        }
        if($user==''){
            $user = $_CONFIG['user'];
        }
        if($passwd==''){
            $passwd = $_CONFIG['pass'];
        }
        
		$options = array(
			//PDO::ATTR_PERSISTENT => true, 
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		);

		try {
			@parent::__construct($host, $user, $passwd, $options);
		} 
        catch (PDOException $e) {
			$this->error = $e->getMessage();		
			throw $e;
		}
	}

    public function lastError(){
        return $this->error;
    }
    
	private function debug() {
		if(!empty($this->errorCallbackFunction)) {
			$error = array("Error" => $this->error);
			if(!empty($this->sql))
				$error["SQL Statement"] = $this->sql;
			if(!empty($this->bind))
				$error["Bind Parameters"] = trim(print_r($this->bind, true));

			$backtrace = debug_backtrace();
			if(!empty($backtrace)) {
				foreach($backtrace as $info) {
					if($info["file"] != __FILE__)
						$error["Backtrace"] = $info["file"] . " at line " . $info["line"];	
				}		
			}

			$msg = "";
			if($this->errorMsgFormat == "html") {
				if(!empty($error["Bind Parameters"]))
					$error["Bind Parameters"] = "<pre>" . $error["Bind Parameters"] . "</pre>";
				$css = trim(file_get_contents(dirname(__FILE__) . "/error.css"));
				$msg .= '<style type="text/css">' . "\n" . $css . "\n</style>";
				$msg .= "\n" . '<div class="db-error">' . "\n\t<h3>SQL Error</h3>";
				foreach($error as $key => $val)
					$msg .= "\n\t<label>" . $key . ":</label>" . $val;
				$msg .= "\n\t</div>\n</div>";
			}
			elseif($this->errorMsgFormat == "text") {
				$msg .= "SQL Error\n" . str_repeat("-", 50);
				foreach($error as $key => $val)
					$msg .= "\n\n$key:\n$val";
			}

			$func = $this->errorCallbackFunction;
			$func($msg);
		}
	}

	public function delete($table, $where, $bind="") {
		$sql = "DELETE FROM " . $table . " WHERE " . $where . ";";
		$this->run($sql, $bind);
	}

	private function filter($table, $info) {
		$driver = $this->getAttribute(PDO::ATTR_DRIVER_NAME);
		if($driver == 'sqlite') {
			$sql = "PRAGMA table_info('" . $table . "');";
			$key = "name";
		}
		elseif($driver == 'mysql') {
			$sql = "DESCRIBE " . $table . ";";
			$key = "Field";
		}
		else {	
			$sql = "SELECT column_name FROM information_schema.columns WHERE table_name = '" . $table . "';";
			$key = "column_name";
		}	

		if(false !== ($list = $this->run($sql))) {
			$fields = array();
			foreach($list as $record)
				$fields[] = $record[$key];
			return array_values(array_intersect($fields, array_keys($info)));
		}
		return array();
	}

	private function cleanup($bind) {
		if(!is_array($bind)) {
			if(!empty($bind))
				$bind = array($bind);
			else
				$bind = array();
		}
		return $bind;
	}

	public function insert($table, $info) {
		$fields = $this->filter($table, $info);
		$sql = "INSERT INTO " . $table . " (" . implode($fields, ", ") . ") VALUES (:" . implode($fields, ", :") . ");";
		$bind = array();
		foreach($fields as $field)
			$bind[":$field"] = $info[$field];
		return $this->run($sql, $bind);
	}

    /**
     * Run you query return all results, on query error thows a PDOException
     * @param string $sql
     * @param array $bind
     * @return array An associative array of the results
     */
    public function run($sql, $bind="") {
		$this->sql = trim($sql);
		$this->bind = $this->cleanup($bind);
		$this->error = "";

		try {
			$pdostmt = $this->prepare($this->sql);
			if($pdostmt->execute($this->bind)) {
                if($pdostmt->rowCount()){
                    if(preg_match("/^(" . implode("|", array("select", "describe", "pragma", "show")) . ") /i", $this->sql))
                        return $pdostmt->fetchAll(PDO::FETCH_ASSOC);
                    elseif(preg_match("/^(" . implode("|", array("delete", "insert", "update")) . ") /i", $this->sql))
                        return $pdostmt->rowCount();                    
                }
                else {
                    return null;
                }
			}
            else{
                throw new Exception('Errore nella query: '.$this->sql);
            }
        } catch (Exception $e) {
			$this->error = $e->getMessage();	
			$this->debug();
			throw $e;
		}
	}

	/**
	 * Esegue una select
	 * @param string $table
	 * @param string $where (optional) 
	 * @param array $bind (optional) 
	 * @param string $fields (optional) default = "*"
	 * @return array the recordset 
	 */
	public function select($table, $where="", $bind="", $fields="*") {
		$sql = "SELECT " . $fields . " FROM " . $table;
		if(!empty($where))
			$sql .= " WHERE " . $where;
		$sql .= ";";
		return $this->run($sql, $bind);
	}
	
	/**
	 * Do a select statement and return the results in a associative array with ids of the first table as
	 * @param type $table
	 * @param type $where
	 * @param type $bind
	 * @param type $fields
	 * @return type 
	 */
	public function keyValSelect($table, $where="", $bind="", $fields="*") {		
		$data = $this->select($table, $where, $bind, $fields);
		
		if(empty($data)){
			return $data;
		}
		
		
		
		// search for the id column
		$columnName = 'id_'.preg_replace('/^([a-zA-Z_]+)(.*)/', '\1', $table);
		$keys = array_keys(current($data));
		$keyId = array_search($columnName, $keys);		
		
		if($keyId===FALSE){
			throw new Exception($columnName.' is required in data set');
		}
		
		//use it to build associative data array
		$assData = array();
		foreach ($data as &$item) {
			$assData[$item[$columnName]] = $item;
		}
		
		return $assData;
	}

	public function setErrorCallbackFunction($errorCallbackFunction, $errorMsgFormat="html") {
		//Variable functions for won't work with language constructs such as echo and print, so these are replaced with print_r.
		if(in_array(strtolower($errorCallbackFunction), array("echo", "print")))
			$errorCallbackFunction = "print_r";

		if(function_exists($errorCallbackFunction)) {
			$this->errorCallbackFunction = $errorCallbackFunction;	
			if(!in_array(strtolower($errorMsgFormat), array("html", "text")))
				$errorMsgFormat = "html";
			$this->errorMsgFormat = $errorMsgFormat;	
		}	
	}

    /**
     * Esegue un update 
     * @param string $table il nome della tabella
     * @param array $info un array associativo con chiavi valori da aggiornare
     * @param string $where una stringa con l'sql da concatenare per il where
     * @param array $bind
     * @return PDOStatement lo statement della query
     * Puo sollevare eccezioni PDOException
     */
	public function update($table, $info, $where, $bind="") {
		$fields = $this->filter($table, $info);
		$fieldSize = sizeof($fields);
        
		$sql = "UPDATE " . $table . " SET ";
		for($f = 0; $f < $fieldSize; ++$f) {
			if($f > 0)
				$sql .= ", ";
			$sql .= $fields[$f] . " = :update_" . $fields[$f]; 
		}
		$sql .= " WHERE " . $where . ";";

		$bind = $this->cleanup($bind);
		foreach($fields as $field)
			$bind[":update_$field"] = $info[$field];
		
		return $this->run($sql, $bind);
	}
    
    public function lastInsertId($name = null) {
        return parent::lastInsertId($name);
    }
    
    
    public function count($table, $where="", $bind="") {
		$sql = "SELECT COUNT(*) AS count " . $fields . " FROM " . $table;
		if(!empty($where))
			$sql .= " WHERE " . $where;
		$sql .= ";";
		$row = array_pop($this->run($sql, $bind));
        return $row['count'];
    }
}	
?>

<?php
namespace Restful;

use Mysqli;
use Exception;

class SQLdb {
    
    private $conn;
    private $stmt;
    private $res;
    
    private $bagS = array();
    private $bagI = array();
    private $bagD = array();
    private $i = 0;
    
    private $lastsql = "";
    private $lastparams = array();
    private $debug_mode = false;
    
    public $last_insert_id;
    private array $config;

    function __construct(?string $iniPath = null){

        if (!$iniPath) {
            $iniPath = $this->findDefaultConfigPath();
        }
        $parsed = parse_ini_file($iniPath, true);
        if (!isset($parsed['database'])) {
            throw new Exception("Invalid config format: missing [database] section in $iniPath");
        }

        $this->config = $parsed['database'];

        // Validate required keys
        foreach (['host', 'username', 'password', 'database'] as $key) {
            if (empty($this->config[$key])) {
                throw new Exception("Missing `$key` in database config.");
            }
        }
        
        $this->conn = new Mysqli(
            $this->config['host'],
            $this->config['username'],
            $this->config['password'],
            $this->config['database']
        );
        if ($this->conn->connect_errno) {
            error_log("Failed to connect to MySQL: (" . $this->conn->connect_errno . ") " . $this->conn->connect_error,0);
        }
    }
    private function findDefaultConfigPath(): string {
        $projectRoot = $this->findProjectRoot();
        return $projectRoot . '/.SQLdb/dbconfig.ini';
    }

    private function findProjectRoot(): string {
        // This file is always present when Composer is in use
        $autoloadPath = __DIR__ . '/../../../autoload.php';

        if (!file_exists($autoloadPath)) {
            throw new \Exception("Could not locate Composer autoloader.");
        }
        //error_log("the authloadPath is $autoloadPath" . PHP_EOL);
        //error_log("the return authloadPath is " . dirname(dirname(realpath($autoloadPath))) . PHP_EOL);
        return dirname(dirname(realpath($autoloadPath)));
    }

    function debugmode(){
        $this->debug_mode = true;
    }
    function query($sql){
        $this->lastsql = $sql;
        $this->res = null;
        $this->stmt = null;
        if (isset($this->conn)){
            try {
                $this->stmt = $this->conn->prepare($sql);
                if (!$this->stmt) {
                    throw new \mysqli_sql_exception("Prepare failed: " . $this->conn->error);
                }

                $this->bind();
                
                if (!isset($this->stmt)){
                    error_log("SQL ERROR: " . $this->last() ,0);
                }
                else{
                    if (!$this->stmt->execute()) {
                        throw new \mysqli_sql_exception("Execute failed: " . $this->stmt->error);
                    }
                    else{
                        if ($this->is_insert($sql)){
                            $this->last_insert_id = $this->conn->insert_id;
                        }
                    }
                }
                
                $this->res = $this->stmt->get_result();

                if ($this->debug_mode) {
                    error_log($this->conn->info, 0);
                }
            } catch (\Throwable $e) {
                error_log("❌ SQL Exception:\n" . $e->getMessage(), 0);
                error_log("🧠 Problematic SQL:\n" . $this->last(), 0);
                error_log("📍 Stack Trace:\n" . $e->getTraceAsString(), 0);
                throw $e; // Optional: rethrow or suppress
            }
        }
    }
    function quickquery($sql){
        $this->query($sql);
        $return = $this->getnextrow();
        if (isset($return) && is_array($return) && count($return)>0) {
            return current($return);
        }
        else return null;
    }
    function queryrow($sql){
        $this->query($sql);
        return $this->getnextrow();
    }
    function getnextrow(){
        if ($this->res && $this->res->num_rows > 0) {
            return $this->res->fetch_assoc();
        }
        return null;
    }
    function rows(){
        return $this->res->num_rows;
    }
    function is_insert($sql){
        $found = strpos(strtoupper($sql),"INSERT INTO");
        if ($found !==false){
            return true;
        }
        else{
            return false;
        }
    }
    function last(){
        try{
            ksort($this->lastparams);
            $SQL = $this->lastsql;
            foreach($this->lastparams as $value) {
                $SQL = preg_replace ( '[\?]' , "'" . $value . "'" , $SQL, 1 );
            }
        }
        catch(Exception $e){
            error_log($e->getMessage(),0);
        }
        return $SQL;
    }
    function put(){
        $args = func_get_args();
        foreach ($args as $arg){
            if (is_string($arg)) $this->bagS[$this->i++] = $arg;
            elseif (is_int($arg)) $this->bagI[$this->i++] = $arg;
            elseif (is_double($arg)) $this->bagD[$this->i++] = $arg;
            elseif (gettype($arg)=="NULL") $this->bagS[$this->i++] = "";
            else {
                $e = new Exception;
                error_log("SQLdbMain gettype `" . gettype($arg) . "` of `" . $arg . "` is not supported - called by " . $e->getTraceAsString(),0);
            }
        }
    }
    private function bind(){
        if ($this->i>0){
            $push = "";
            for ($x=0; $x<$this->i; $x++){
                if (array_key_exists($x, $this->bagS)) $push .= "s"; 
                if (array_key_exists($x, $this->bagI)) $push .= "i";
                if (array_key_exists($x, $this->bagD)) $push .= "d";
            }
            
            $bind[] = $push;
            for ($x=0; $x<$this->i; $x++){
                if (array_key_exists($x, $this->bagS)) $bind[] = &$this->bagS[$x]; 
                if (array_key_exists($x, $this->bagI)) $bind[] = &$this->bagI[$x]; 
                if (array_key_exists($x, $this->bagD)) $bind[] = &$this->bagD[$x]; 
            }
            try{
                /***
                *	if the param count differs from the bind required it will E_WARNING so I 
                *	want that 'warning' in the error_log where it belongs
                */
                set_error_handler(function ($errno, $errstr) { 
                    error_log(PHP_EOL . "*". $this->lastsql . PHP_EOL . $errstr . PHP_EOL . "*" . PHP_EOL,0);
                    $e = new \Exception; //<-quick and dirty stack trace
                    error_log($e->getTraceAsString(),0);
                }, E_WARNING);
                call_user_func_array(array($this->stmt, 'bind_param'), $bind);
                restore_error_handler();
            }
            catch (Exception $ex){
                /****
                *	it may also just burp naturally, same issue
                */
                error_log(PHP_EOL . "*". $this->lastsql . PHP_EOL . $ex->getMessage() . PHP_EOL . "*" . PHP_EOL,0);
                $e = new \Exception; //<-quick and dirty stack trace
                error_log($e->getTraceAsString(),0);
            }
            $this->i = 0;
            $this->lastparams = $this->bagS + $this->bagI + $this->bagD;
            $this->bagS = array();
            $this->bagI = array();
            $this->bagD = array();
        }
    }
}
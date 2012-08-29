<?php
/*
<NPT, a web development framework.>
Copyright (C) <2009>  <NPT>

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
require_once('class.db.php');

class database extends dcom {
	public function __construct($d = false) {
		$this->access($d);

		$this->connect = new mysqli($this->_access['server'], $this->_access['login'], $this->_access['secret'], $this->_access['database']);
		
		if ($this->connect->connect_error) {
			exit('330');
		}
		unset($this->_access);
		
		return true;
	}
	
	public function __sleep() {
		return true;
	}
	
	public function __wakeup() {
		self::__construct();
	}
	
	public function close() {
		if (!$this->connect) {
			return false;
		}
		
		if ($this->result) {
			if (is_object($this->result)) {
				$this->result->free();
			}
			$this->result = false;
		}
		
		if (is_object($this->connect)) {
			$this->connect->close();
		}
		
		$this->connect = false;
		
		return true;
	}
	
	public function query($query = '', $transaction = false) {
		if (is_array($query)) {
			foreach ($query as $sql) {
				$this->query($sql);
			}
			
			return;
		}
		
		if ($this->result && is_object($this->result)) {
			$this->result->close();
			$this->result = false;
		}
		
		if (!empty($query)) {
			$this->queries++;
			$this->history[] = $query;
			
			if (!$this->result = $this->connect->query($query)) {
				$this->error($query);
				
				return false;
			}
			
			//$this->registry($query);
			// unset($this->row[$this->result], $this->rowset[$this->result]);
			return $this->result;
		}
		
		return false;
	}
	
	public function query_limit($query, $total, $offset = 0) {
		if (empty($query)) {
			return false;
		}
		
		// if $total is set to 0 we do not want to limit the number of rows
		if (!$total) {
			$total = -1;
		}
		
		$query .= nr() . " LIMIT " . (($offset) ? $offset . ', ' . $total : $total);
		return $this->query($query);
	}
	
	public function transaction($status = 'begin') {
		switch ($status) {
			case 'begin':
				return $this->connect->autocommit(false);
				break;
			case 'commit':
				return $this->connect->commit();
				break;
			case 'rollback':
				return $this->connect->rollback();
				break;
		}
		
		return true;
	}
	
	public function build($query, $assoc = false, $update_field = false) {
		if (!is_array($assoc)) {
			return false;
		}
		
		$fields = array();
		$values = array();
		
		switch ($query) {
			case 'INSERT':
				foreach ($assoc as $key => $var) {
					$fields[] = $key;
					
					if (is_null($var)) {
						$values[] = 'NULL';
					} elseif (is_string($var)) {
						$values[] = "'" . $this->escape($var) . "'";
					} else {
						$values[] = (is_bool($var)) ? intval($var) : $var;
					}
				}
				
				$query = ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
				break;
			case 'UPDATE':
			case 'SELECT':
				$values = array();
				
				foreach ($assoc as $key => $var) {
					if (is_null($var)) {
						$values[] = "$key = NULL";
					} elseif (is_string($var)) {
						if ($update_field && strpos($var, $key) !== false) {
							$values[] = $key . ' = ' . $this->escape($var);
						} else {
							$values[] = "$key = '" . $this->escape($var) . "'";
						}
					} else {
						$values[] = (is_bool($var)) ? "$key = " . intval($var) : "$key = $var";
					}
				}
				$query = implode(($query == 'UPDATE') ? ', ' : ' AND ', $values);
				break;
		}
		
		return $query;
	}
	
	public function num_queries() {
		return $this->queries;
	}
	
	public function numrows() {
		if ($this->result && is_object($this->result)) {
			return $this->result->num_rows;
		}
		
		return false;
	}
	
	public function affectedrows() {
		if ($this->connect && is_object($this->connect)) {
			return $this->connect->affected_rows;
		}
		
		return false;
	}
	
	public function numfields() {
		if ($this->result && is_object($this->result)) {
			return $this->result->fetch_fields();
		}
		
		return false;
	}
	
	public function fieldname($offset) {
		if ($fields = $this->numfields()) {
			foreach ($fields as $i => $row) {
				if ($offset === $i) {
					return $row->name;
					break;
				}
			}
		}
		
		return false;
	}
	
	public function fieldtype($offset) {
		if ($fields = $this->numfields()) {
			foreach ($fields as $i => $row) {
				if ($offset === $i) {
					return $row->type;
					break;
				}
			}
		}
		
		return false;
	}
	
	public function fetchrow($result_type = MYSQL_BOTH) {
		if ($this->result && is_object($this->result)) {
			return $this->result->fetch_array($result_type);
		}
		
		return false;
	}
	
	public function fetchrowset($result_type = MYSQL_BOTH) {
		if ($this->result && is_object($this->result)) {
			$result = array();
			while ($row = $this->result->fetch_array($result_type)) {
				$result[] = $row;
			}
			return $result;
		}
		
		return false;
	}
	
	public function fetchfield($field) {
		if ($this->result && is_object($this->result)) {
			if ($data = $this->fetchrow()) {
				return (isset($data[$field])) ? $data[$field] : false;
			}
		}
		
		return false;
	}
	
	public function rowseek($rownum) {
		if ($this->result && is_object($this->result)) {
			return $this->result->data_seek($rownum);
		}
		
		return false;
	}
	
	public function nextid() {
		if ($this->connect && is_object($this->connect) && $this->connect->insert_id) {
			return $this->connect->insert_id;
		}
		
		return false;
	}
	
	public function freeresult() {
		if ($this->result && is_object($this->result)) {
			$this->result->close();
		}
		
		$this->result = false;
		return true;
	}
	
	public function escape($str) {
		if ($this->connect) {
			return $this->connect->escape_string($str);
		}
		
		return false;
	}
	
	public function cache($a_sql, $sid = '', $private = true) {
		global $user;
		
		$filter_values = array($sid);
		
		$sql = 'SELECT cache_query
			FROM _search_cache
			WHERE cache_sid = ?';
		
		if ($private) {
			$sql .= ' AND cache_uid = ?';
			$filter_values[] = $user->d('user_id');
		}
		
		$query = sql_field(sql_filter($sql, $filter_values), 'cache_query', '');
		
		if (!empty($sid) && empty($query)) {
			_fatal();
		}
		
		if (empty($query) && !empty($a_sql)) {
			$sid = md5(unique_id());
			
			$insert = array(
				'cache_sid' => $sid,
				'cache_query' => $a_sql,
				'cache_uid' => $user->d('user_id'),
				'cache_time' => time()
			);
			$sql = 'INSERT INTO _search_cache' . $this->build('INSERT', $insert);
			$this->query($sql);
			
			$query = $a_sql;
		}
		
		$all_rows = 0;
		if (!empty($query)) {
			$result = $this->query($query);
			
			$all_rows = $this->numrows($result);
			$this->freeresult($result);
		}
		
		$has_limit = false;
		if (preg_match('#LIMIT (\d+)(\, (\d+))?#is', $query, $limits)) {
			$has_limit = $limits[1];
		}
		
		return array('sid' => $sid, 'query' => $query, 'limit' => $has_limit, 'total' => $all_rows);
	}
	
	public function cache_limit(&$arr, $start, $end = 0) {
		if ($arr['limit'] !== false) {
			$arr['query'] = preg_replace('#(LIMIT) ' . $arr['limit'] . '#is', '\\1 ' . $start, $arr['query']);
		} else {
			$arr['query'] .= ' LIMIT ' . $start . (($end) ? ', ' . $end : '');
		}
		
		return;
	}
	
	public function history() {
		return $this->history;
	}
	
	public function registry($action, $uid = false) {
		$method = preg_replace('#^(INSERT|UPDATE|DELETE) (.*?)$#is', '\1', $action);
		$method = strtolower($method);
		
		if (!in_array($method, w('insert update delete'))) {
			return;
		}
		
		if (!$whitelist = get_file(XFS.XCOR . 'store/sql_history')) {
			return;
		}
		
		if (!count($whitelist)) {
			return;
		}
		
		$action = str_replace(array(nr(), "\t", nr(true)), array('', '', ' '), $action);
		$table = preg_replace('#^(INSERT\ INTO|UPDATE|DELETE\ FROM) (\_[a-z\_]+) (.*?)$#is', '\2', $action);
		
		if (!in_array($table, $whitelist)) {
			return;
		}
		
		$actions = '';
		switch ($method) {
			case 'insert':
				if (!preg_match('#^INSERT INTO (\_[a-z\_]+) \((.*?)\) VALUES \((.*?)\)$#is', $action, $s_action)) {
					return;
				}
				
				$keys = array_map('trim', explode(',', $s_action[2]));
				$values = array_map('trim', explode(',', $s_action[3]));
				
				foreach ($values as $i => $row) {
					$values[$i] = preg_replace('#^\'(.*?)\'$#i', '\1', $row);
				}
				
				if (count($keys) != count($values)) {
					return;
				}
				
				$query = array(
					'table' => $s_action[1],
					'query' => array_combine($keys, $values)
				);
				break;
			case 'update':
				if (!preg_match('#^UPDATE (\_[a-z\_]+) SET (.*?) WHERE (.*?)$#is', $action, $s_action)) {
					return;
				}
				
				$all = array(
					'set' => array_map('trim', explode(',', $s_action[2])),
					'where' => array_map('trim', explode('AND', $s_action[3]))
				);
				
				foreach ($all as $j => $v) {
					foreach ($v as $i => $row) {
						$v_row = array_map('trim', explode('=', $row));
						
						$all[$j][$v_row[0]] = preg_replace('#^\'(.*?)\'$#i', '\1', $v_row[1]);
						unset($all[$j][$i]);
					}
				}
				
				$query = array(
					'table' => $s_action[1],
					'set' => $all['set'],
					'where' => $all['where']
				);
				break;
			case 'delete':
				if (!preg_match('#^DELETE FROM (\_[a-z\_]+) WHERE (.*?)$#is', $action, $s_action)) {
					return;
				}
				
				$all = array('where' => array_map('trim', explode('AND', $s_action[2])));
				
				foreach ($all as $j => $v) {
					foreach ($v as $i => $row) {
						$v_row = array_map('trim', explode('=', $row));
						
						$all[$j][$v_row[0]] = preg_replace('#^\'(.*?)\'$#i', '\1', $v_row[1]);
						unset($all[$j][$i]);
					}
				}
				
				$query = array(
					'table' => $s_action[1],
					'where' => $all['where']
				);
				break;
		}
		
		global $user;
		
		$sql_insert = array(
			'time' => time(),
			'uid' => $user->d('user_id'),
			'method' => $method,
			'actions' => json_encode($query)
		);
		$sql = 'INSERT INTO _log' . $this->build('INSERT', prefix('log', $sql_insert));
		$this->query($sql);
		
		return;
	}
	
	public function set_error($error = -1) {
		if ($error !== -1)
		{
			$this->noerror = $error;
		}
		
		return $this->noerror;
	}
	
	public function error($sql = '') {
		$sql_error = $this->connect->error;
		$sql_errno = $this->connect->errno;

		$error = array('sql' => $sql, 'message' => $sql_error, 'code' => $sql_errno);
		
		if (!$this->noerror) {
			$xml = xml($error);
			error_log($xml);

			echo $xml;
			exit;
		}

		return $error;
	}
}

?>
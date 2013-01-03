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

class database {
	private $connect;
	private $result;
	private $history;
	private $row;
	private $rowset;
	private $queries;
	private $noerror;
	private $s_transaction;
	private $last_query_text = '';
	public $message;

	public function __construct($d = false) {
		$d = ($d === false) ? decode_ht('.htda') : explode(',', decode($d));
		
		foreach (w('server login secret database') as $i => $k) {
			$d[$k] = decode($d[$i]);
		}

		$this->d = $d;

		$this->connect = @oci_connect($d['login'], $d['secret'], $d['server'] . '/' . $d['database']);
		unset($d);

		if (!$this->connect) {
			$this->message = oci_error();

			$this->sql_error();
			return false;
		}

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
			@oci_free_statement($this->result);
			$this->result = false;
		}
		
		if (is_object($this->connect)) {
			@oci_close($this->connect);
		}
		
		$this->connect = false;
		
		return true;
	}

	/**
	* Describe the structure of a table
	*/
	public function desc($table) {
		$sql = 'SELECT COLUMN_NAME
			FROM USER_TAB_COLUMNS
			WHERE table_name = ?';
		return sql_rowset(sql_filter($sql, $table), false, 'COLUMN_NAME');
	}

	/**
	* Base query method
	*
	* @param	string	$query		Contains the SQL query which shall be executed
	* @return	mixed				When casted to bool the returned value returns true on success and false on failure
	*
	* @access	public
	*/
	function query($query = '') {
		if (is_array($query)) {
			foreach ($query as $sql) {
				$this->query($sql);
			}
			
			return;
		}

		if (empty($query)) {
			return false;
		}

		if (strpos($query, 'LIMIT') !== false) {
			preg_match('#LIMIT (\d+)(, )?(\d+)?#is', $query, $limit_part);

			$offset = 0;
			$total = $limit_part[1];

			if (isset($limit_part[3])) {
				$offset = ($total > 0) ? ($total + 1) : $total;
				$total = ($total > 0) ? ($limit_part[3] - 1) : $limit_part[3];
			}

			$query = str_replace($limit_part[0], '', $query);

			return $this->query_limit($query, $total, $offset);
		}

		$this->last_query_text = $query;
		
		//$in_transaction = false;
		if (!$this->s_transaction) {
			$this->transaction('begin');
		}/* else {
			$in_transaction = true;
		}*/

		$array = array();
		$this->queries++;

		// We overcome Oracle's 4000 char limit by binding vars
		if (strlen($query) > 4000) {
			if (preg_match('/^(INSERT INTO[^(]++)\\(([^()]+)\\) VALUES[^(]++\\((.*?)\\)$/sU', $query, $regs)) {
				if (strlen($regs[3]) > 4000) {
					$cols = explode(', ', $regs[2]);

					preg_match_all('/\'(?:[^\']++|\'\')*+\'|[\d-.]+/', $regs[3], $vals, PREG_PATTERN_ORDER);

					$inserts = $vals[0];
					unset($vals);

					foreach ($inserts as $key => $value) {
						if (!empty($value) && $value[0] === "'" && strlen($value) > 4002) // check to see if this thing is greater than the max + 'x2
						{
							$inserts[$key] = ':' . strtoupper($cols[$key]);
							$array[$inserts[$key]] = str_replace("''", "'", substr($value, 1, -1));
						}
					}

					$query = $regs[1] . '(' . $regs[2] . ') VALUES (' . implode(', ', $inserts) . ')';
				}
			} else if (preg_match_all('/^(UPDATE [\\w_]++\\s+SET )([\\w_]++\\s*=\\s*(?:\'(?:[^\']++|\'\')*+\'|[\d-.]+)(?:,\\s*[\\w_]++\\s*=\\s*(?:\'(?:[^\']++|\'\')*+\'|[\d-.]+))*+)\\s+(WHERE.*)$/s', $query, $data, PREG_SET_ORDER)) {
				if (strlen($data[0][2]) > 4000) {
					$update = $data[0][1];
					$where = $data[0][3];
					preg_match_all('/([\\w_]++)\\s*=\\s*(\'(?:[^\']++|\'\')*+\'|[\d-.]++)/', $data[0][2], $temp, PREG_SET_ORDER);
					unset($data);

					$cols = array();
					foreach ($temp as $value) {
						if (!empty($value[2]) && $value[2][0] === "'" && strlen($value[2]) > 4002) // check to see if this thing is greater than the max + 'x2
						{
							$cols[] = $value[1] . '=:' . strtoupper($value[1]);
							$array[$value[1]] = str_replace("''", "'", substr($value[2], 1, -1));
						} else {
							$cols[] = $value[1] . '=' . $value[2];
						}
					}

					$query = $update . implode(', ', $cols) . ' ' . $where;
					unset($cols);
				}
			}
		}

		switch (substr($query, 0, 6)) {
			case 'DELETE':
				if (preg_match('/^(DELETE FROM [\w_]++ WHERE)((?:\s*(?:AND|OR)?\s*[\w_]+\s*(?:(?:=|<>)\s*(?>\'(?>[^\']++|\'\')*+\'|[\d-.]+)|(?:NOT )?IN\s*\((?>\'(?>[^\']++|\'\')*+\',? ?|[\d-.]+,? ?)*+\)))*+)$/', $query, $regs))
				{
					$query = $regs[1] . $this->_rewrite_where($regs[2]);
					unset($regs);
				}
				break;
			case 'UPDATE':
				if (preg_match('/^(UPDATE [\\w_]++\\s+SET [\\w_]+\s*=\s*(?:\'(?:[^\']++|\'\')*+\'|[\d-.]++|:\w++)(?:, [\\w_]+\s*=\s*(?:\'(?:[^\']++|\'\')*+\'|[\d-.]++|:\w++))*+\\s+WHERE)(.*)$/s',  $query, $regs)) {
					$query = $regs[1] . $this->_rewrite_where($regs[2]);
					unset($regs);
				}
				break;
			case 'SELECT':
				$query = preg_replace_callback('/([\w_.]++)\s*(?:(=|<>)\s*(?>\'(?>[^\']++|\'\')*+\'|[\d-.]++|([\w_.]++))|(?:NOT )?IN\s*\((?>\'(?>[^\']++|\'\')*+\',? ?|[\d-.]++,? ?)*+\))/', array($this, '_rewrite_col_compare'), $query);
				break;
		}

		$this->result = @oci_parse($this->connect, $query);

		foreach ($array as $key => $value) {
			@oci_bind_by_name($this->result, $key, $array[$key], -1);
		}

		$success = @oci_execute($this->result, OCI_DEFAULT);

		if (!$success) {
			$this->sql_error($query);
			$this->result = false;
		} else {
			if ($this->s_transaction) {
				$this->transaction('commit');
			}
		}

		if (strpos($query, 'SELECT') === 0 && $this->result) {
			$this->open_queries[(int) $this->result] = $this->result;
		}
	
		return $this->result;
	}

	/**
	* Build LIMIT query
	*/
	public function query_limit($query, $total, $offset = 0) {
		if (empty($query)) {
			return false;
		}

		$this->query_result = false;

		$query = 'SELECT * FROM (SELECT /*+ FIRST_ROWS */ rownum AS xrownum, a.* FROM (' . $query . ') a WHERE rownum <= ' . ($offset + $total) . ') WHERE xrownum >= ' . $offset;

		return $this->query($query);
	}

	/**
	* SQL Transaction
	* @access private
	*/
	function transaction($status = 'begin') {
		switch ($status) {
			case 'begin':
				$this->s_transaction = true;

				return true;
				break;
			case 'commit':
				$this->s_transaction = false;

				return @oci_commit($this->connect);
				break;
			case 'rollback':
				$this->s_transaction = false;

				return @oci_rollback($this->connect);
				break;
		}

		return true;
	}

	public function build($query, $assoc = false, $update_field = false) {
		if (!is_array($assoc)) {
			return false;
		}
		
		$fields = $values = array();
		
		switch ($query) {
			case 'INSERT':
				$column_name = '';
				if ($update_field !== false) {
					$first_assoc = array_shiftname($assoc);

					if (strpos($first_assoc, '_id') !== false) {
						$column_name = $first_assoc;
						$assoc[$first_assoc] = strtoupper($update_field . '_' . $first_assoc . '_seq') . '.nextval';
					} elseif (strpos($first_assoc, '_') !== false) {
						$first_part = explode('_', $first_assoc);
						$column_name = $first_part[0] . '_id';
						$sequence = strtoupper($update_field . '_' . $column_name . '_seq');

						array_unshift_assoc($assoc, $column_name, $sequence . '.nextval');

						/*
						Get primary key name and add before $assoc first field
						*/
						/*$u_update_field = strtoupper($update_field);

						$sql = 'SELECT cols.column_name
							FROM all_constraints cons, all_cons_columns cols
							WHERE cols.table_name = ?
								AND cons.constraint_type = ?
								AND cons.constraint_name = cols.constraint_name
								AND cons.owner = cols.owner
							ORDER BY cols.table_name, cols.position';
						$column_name = strtolower(sql_field(sql_filter($sql, $u_update_field, 'P'), 'column_name', ''));

						$sql = 'SELECT sequence_name
							FROM USER_SEQUENCES
							WHERE SEQUENCE_NAME LIKE ?';
						$sequence = sql_field(sql_filter($sql, '%' . $u_update_field . '%'), 'sequence_name', '');

						array_unshift_assoc($assoc, $column_name, $sequence . '.nextval');*/
					}
				}
				
				foreach ($assoc as $key => $var) {
					$fields[] = $key;
					
					if (is_null($var)) {
						$values[] = 'NULL';
					} elseif ($key == $column_name) {
						$values[] = $this->escape($var);
					} elseif (is_string($var)) {
						$values[] = "'" . $this->escape($var) . "'";
					} else {
						$values[] = (is_bool($var)) ? intval($var) : $var;
					}
				}

				$query = '';
				if ($update_field !== false) {
					$query .= 'INSERT INTO ' . $update_field;
				}
				
				$query .= ' /***/ (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
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
				$query = '/***/' . implode(($query == 'UPDATE') ? ', ' : ' AND ', $values);
				break;
		}
		
		return $query;
	}

	public function num_queries() {
		return $this->queries;
	}

	public function numrows() {
		if ($this->result) {
			return @oci_num_rows($this->result);
		}
		
		return false;
	}

	public function affectedrows() {
		if ($this->result) {
			return @oci_num_rows($this->result);
		}
		
		return false;
	}

	public function numfields() {
		return false;
	}

	public function fieldname($offset) {
		return false;
	}
	
	public function fieldtype($offset) {
		return false;
	}

	/**
	* Fetch current row
	*/
	/*function fetchrow() {
		if ($this->result) {
			$row = array();
			$result = @ocifetchinto($query_id, $row, OCI_ASSOC + OCI_RETURN_NULLS);

			if (!$result || !$row) {
				return false;
			}

			$result_row = array();
			foreach ($row as $key => $value) {
				// Oracle treats empty strings as null
				if (is_null($value)) {
					$value = '';
				}

				// OCI->CLOB?
				if (is_object($value)) {
					$value = $value->load();
				}

				$result_row[strtolower($key)] = $value;
			}

			return $result_row;
		}

		return false;
	}*/

	public function fetchrow($result_type = OCI_BOTH) {
		if ($this->result) {
			return @oci_fetch_array($this->result, $result_type + OCI_RETURN_NULLS);
		}
		
		return false;
	}
	
	public function fetchrowset($result_type = OCI_BOTH) {
		if ($this->result) {
			$result = array();

			oci_fetch_all($this->result, $result, 0, -1, OCI_FETCHSTATEMENT_BY_ROW);

			/*while ($row = @oci_fetch_array($this->result, $result_type)) {
				$result[] = $row;
			}*/
			return $result;
		}
		
		return false;
	}
	
	public function fetchfield($field) {
		if ($this->result && $data = $this->fetchrow()) {
			$data = array_change_key_case($data, CASE_LOWER);

			return (isset($data[$field])) ? $data[$field] : false;
		}
		
		return false;
	}

	/**
	* Seek to given row number
	* rownum is zero-based
	*/
	public function rowseek($rownum) {
		if ($this->result === false) {
			return false;
		}

		// Reset internal pointer
		@oci_execute($this->result, OCI_DEFAULT);

		// We do not fetch the row for rownum == 0 because then the next resultset would be the second row
		for ($i = 0; $i < $rownum; $i++) {
			if (!$this->fetchrow()) {
				return false;
			}
		}

		return false;
	}

	/**
	* Get last inserted id after insert statement
	*/
	function nextid() {
		if ($this->result !== false && $this->last_query_text != '') {
			if (preg_match('#^INSERT[\t\n ]+INTO[\t\n ]+([a-z0-9\_\-]+)#is', $this->last_query_text, $tablename)) {
				$query = 'SELECT ' . $tablename[1] . '_seq.currval FROM DUAL';
				$stmt = @oci_parse($this->connect, $query);
				@oci_execute($stmt, OCI_DEFAULT);

				$temp_result = @ocifetchinto($stmt, $temp_array, OCI_ASSOC + OCI_RETURN_NULLS);
				@oci_free_statement($stmt);

				if ($temp_result) {
					return $temp_array['CURRVAL'];
				}
			}
		}

		return false;
	}

	/**
	* Free sql result
	*/
	function freeresult() {
		if (isset($this->open_queries[(int) $this->result])) {
			unset($this->open_queries[(int) $this->result]);
			return @oci_free_statement($this->result);
		}

		return false;
	}

	//-----------------------------------------------------------------------------------

	/**
	* Version information about used database
	* @param bool $raw if true, only return the fetched sql_server_version
	* @return string sql server version
	*/
	function sql_server_info($raw = false) {
		$this->sql_server_version = @oci_server_version($this->connect);

		return $this->sql_server_version;
	}

	/**
	* Oracle specific code to handle the fact that it does not compare columns properly
	* @access private
	*/
	function _rewrite_col_compare($args) {
		if (count($args) == 4) {
			if ($args[2] == '=') {
				return '(' . $args[0] . ' OR (' . $args[1] . ' is NULL AND ' . $args[3] . ' is NULL))';
			} else if ($args[2] == '<>') {
				// really just a fancy way of saying foo <> bar or (foo is NULL XOR bar is NULL) but SQL has no XOR :P
				return '(' . $args[0] . ' OR ((' . $args[1] . ' is NULL AND ' . $args[3] . ' is NOT NULL) OR (' . $args[1] . ' is NOT NULL AND ' . $args[3] . ' is NULL)))';
			}
		} else {
			return $this->_rewrite_where($args[0]);
		}
	}

	/**
	* Oracle specific code to handle it's lack of sanity
	* @access private
	*/
	function _rewrite_where($where_clause) {
		preg_match_all('/\s*(AND|OR)?\s*([\w_.()]++)\s*(?:(=|<[=>]?|>=?|LIKE)\s*((?>\'(?>[^\']++|\'\')*+\'|[\d-.()]+))|((NOT )?IN\s*\((?>\'(?>[^\']++|\'\')*+\',? ?|[\d-.]+,? ?)*+\)))/', $where_clause, $result, PREG_SET_ORDER);
		$out = '';

		foreach ($result as $val) {
			if (!isset($val[5])) {
				if ($val[4] !== "''") {
					$out .= $val[0];
				} else {
					$out .= ' ' . $val[1] . ' ' . $val[2];
					if ($val[3] == '=') {
						$out .= ' is NULL';
					} else if ($val[3] == '<>') {
						$out .= ' is NOT NULL';
					}
				}
			} else {
				$in_clause = array();
				$sub_exp = substr($val[5], strpos($val[5], '(') + 1, -1);
				$extra = false;
				preg_match_all('/\'(?>[^\']++|\'\')*+\'|[\d-.]++/', $sub_exp, $sub_vals, PREG_PATTERN_ORDER);
				$i = 0;

				foreach ($sub_vals[0] as $sub_val) {
					// two things:
					// 1) This determines if an empty string was in the IN clausing, making us turn it into a NULL comparison
					// 2) This fixes the 1000 list limit that Oracle has (ORA-01795)
					if ($sub_val !== "''") {
						$in_clause[(int) $i++/1000][] = $sub_val;
					} else {
						$extra = true;
					}
				}

				if (!$extra && $i < 1000)
				{
					$out .= $val[0];
				} else {
					$out .= ' ' . $val[1] . '(';
					$in_array = array();

					// constuct each IN() clause
					foreach ($in_clause as $in_values) {
						$in_array[] = $val[2] . ' ' . (isset($val[6]) ? $val[6] : '') . 'IN(' . implode(', ', $in_values) . ')';
					}

					// Join the IN() clauses against a few ORs (IN is just a nicer OR anyway)
					$out .= implode(' OR ', $in_array);

					// handle the empty string case
					if ($extra) {
						$out .= ' OR ' . $val[2] . ' is ' . (isset($val[6]) ? $val[6] : '') . 'NULL';
					}
					$out .= ')';

					unset($in_array, $in_clause);
				}
			}
		}

		return $out;
	}

	/**
	* Escape string used in sql query
	*/
	function escape($str) {
		return str_replace(array("'", "\0"), array("''", ''), $str);
	}

	/**
	* Build LIKE expression
	* @access private
	*/
	function _sql_like_expression($expression) {
		return $expression . " ESCAPE '\\'";
	}

	function _sql_custom_build($stage, $data) {
		return $data;
	}

	function _sql_bit_and($column_name, $bit, $compare = '') {
		return 'BITAND(' . $column_name . ', ' . (1 << $bit) . ')' . (($compare) ? ' ' . $compare : '');
	}

	function _sql_bit_or($column_name, $bit, $compare = '') {
		return 'BITOR(' . $column_name . ', ' . (1 << $bit) . ')' . (($compare) ? ' ' . $compare : '');
	}

	public function set_error($error = -1) {
		if ($error !== -1) {
			$this->noerror = $error;
		}
		
		return $this->noerror;
	}

	/**
	* return sql error array
	* @access private
	*/
	function sql_error($sql = '') {
		$error = @oci_error();
		$error = (!$error) ? @oci_error($this->result) : $error;
		$error = (!$error) ? @oci_error($this->connect) : $error;

		if ($error) {
			$this->last_error_result = $error;
		} else {
			$error = (isset($this->last_error_result) && $this->last_error_result) ? $this->last_error_result : array();
		}

		if (!empty($this->message)) {
			$error = $this->message;
		}

		$error = array(
			'type' => 'oracle',
			'message' => $error
		);
		
		if (!$this->noerror) {
			$xml = xml($error);
			error_log($xml);

			$this->message = $error;
		}

		return $error;
	}
}

?>
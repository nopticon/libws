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
function decode_ht($path) {
	$da_path = './' . $path;
	
	if (!@file_exists($da_path) || !$a = @file($da_path)) exit;
	
	return explode(',', decode($a[0]));
}

function hook($name, $args = array(), $arr = false) {
	switch ($name) {
		case 'isset':
			eval('$a = ' . $name . '($args' . ((is_array($args)) ? '[0]' . $args[1] : '') . ');');
			return $a;
			break;
		case 'in_array':
			if (is_array($args[1])) {
				if (hook('isset', array($args[1][0], $args[1][1]))) {
					eval('$a = ' . $name . '($args[0], $args[1][0]' . $args[1][1] . ');');
				}
			} else {
				eval('$a = ' . $name . '($args[0], $args[1]);');
			}
			
			return (isset($a)) ? $a : false;
			break;
	}
	
	$f = 'call_user_func' . ((!$arr) ? '_array' : '');
	return $f($name, $args);
}

function prefix($prefix, $arr) {
	$prefix = ($prefix != '') ? $prefix . '_' : '';
	
	$a = w();
	foreach ($arr as $k => $v) {
		$a[$prefix . $k] = $v;
	}
	return $a;
}

function array_unshift_assoc(&$arr, $key, $val) {
   $arr = array_reverse($arr, true);
   $arr[$key] = $val;
   $arr = array_reverse($arr, true);
   return $arr;
}

function array_shiftname(&$arr, $unset = false) {
	list($k) = array_keys($arr);
	
	return $k;
}

// Database filter layer
// Idea from http://us.php.net/manual/en/function.sprintf.php#93156
if (!function_exists('npi_sql_filter')) {
	function npi_sql_filter() {
		if (!$args = func_get_args()) {
			return false;
		}
		
		$sql = array_shift($args);
		
		if (is_array($sql)) {
			$sql_ary = w();
			foreach ($sql as $row) {
				$sql_ary[] = npi_sql_filter($row, $args);
			}
			
			return $sql_ary;
		}
		
		$count_args = count($args);
		$sql = str_replace('%', '[!]', $sql);
		
		if (!$count_args || $count_args < 1) {
			return str_replace('[!]', '%', $sql);
		}
		
		if ($count_args == 1 && is_array($args[0])) {
			$args = $args[0];
		}
		
		foreach ($args as $i => $arg) {
			$args[$i] = (strpos($arg, '/***/') !== false) ? $arg : npi_sql_escape($arg);
		}
		
		foreach ($args as $i => $row) {
			if (strpos($row, 'addquotes') !== false) {
				$e_row = explode(',', $row);
				array_shift($e_row);
				
				foreach ($e_row as $j => $jr) {
					$e_row[$j] = "'" . $jr . "'";
				}
				
				$args[$i] = implode(',', $e_row);
			}
		}
		
		array_unshift($args, str_replace(w('?? ?'), w("%s '%s'"), $sql));
		
		// Conditional deletion of lines if input is zero
		if (strpos($args[0], '-- ') !== false) {
			$e_sql = explode(nr(), $args[0]);
			
			$matches = 0;
			foreach ($e_sql as $i => $row) {
				$e_sql[$i] = str_replace('-- ', '', $row);
				if (strpos($row, '%s')) {
					$matches++;
				}
				
				if (strpos($row, '-- ') !== false && !$args[$matches]) {
					unset($e_sql[$i], $args[$matches]);
				}
			}
			
			$args[0] = implode($e_sql);
		}
		
		return str_replace('[!]', '%', hook('sprintf', $args));
	}
}

function npi_sql_insert($table, $insert) {
	$sql = 'INSERT INTO _' . $table . npi_sql_build('INSERT', $insert);
	return npi_sql_query_nextid($sql);
}

function npi_sql_query($sql) {
	global $npi_db;

	return $npi_db->query($sql);
}

function npi_sql_transaction($status = 'begin') {
	global $npi_db;
	
	return $npi_db->transaction($status);
}

function npi_sql_desc($table) {
	global $npi_db;

	return $npi_db->desc($table);
}

function npi_sql_field($sql, $field, $def = false) {
	global $npi_db;
	
	$npi_db->query($sql);
	$response = $npi_db->fetchfield($field);
	$npi_db->freeresult();
	
	if ($response === false) {
		$response = $def;
	}
	
	return $response;
}

function npi_sql_fieldrow($sql, $result_type = MYSQL_ASSOC) {
	global $npi_db;
	
	$npi_db->query($sql);
	
	$response = false;
	if ($row = $npi_db->fetchrow($result_type)) {
		$row['_numrows'] = $npi_db->numrows();
		$response = array_change_key_case($row, CASE_LOWER);
	}
	$npi_db->freeresult();
	
	return $response;
}

function npi_sql_rowset($sql, $a = false, $b = false, $global = false, $type = MYSQL_ASSOC) {
	global $npi_db;
	
	$npi_db->query($sql);

	if (!empty($npi_db->message)) {
		return $npi_db->message;
	}

	if (!$data = $npi_db->fetchrowset($type)) {
		return false;
	}
	
	$arr = w();
	foreach ($data as $row) {
		$row = array_change_key_case($row, CASE_LOWER);
		$data = ($b === false) ? $row : $row[$b];
		
		if ($a === false) {
			$arr[] = $data;
		} else {
			if ($global) {
				$arr[$row[$a]][] = $data;
			} else {
				$arr[$row[$a]] = $data;
			}
		}
	}
	$npi_db->freeresult();
	
	return $arr;
}

function npi_sql_truncate($table) {
	$sql = 'TRUNCATE TABLE ??';
	
	return npi_sql_query(npi_sql_filter($sql, $table));
}

function npi_sql_total($table) {
	return npi_sql_field("SHOW TABLE STATUS LIKE '" . $table . "'", 'Auto_increment', 0);
}

function npi_sql_close() {
	global $npi_db;
	
	if ($npi_db->close()) {
		return true;
	}
	
	return false;
}

function npi_sql_queries() {
	global $npi_db;
	
	return $npi_db->num_queries();
}

function npi_sql_query_nextid($sql) {
	global $npi_db;
	
	$npi_db->query($sql);

	return $npi_db->nextid();
}

function npi_sql_nextid() {
	global $npi_db;
	
	return $npi_db->nextid();
}

function npi_sql_affected($sql) {
	global $npi_db;
	
	$npi_db->query($sql);
	
	return $npi_db->affectedrows();
}

function npi_sql_affectedrows() {
	global $npi_db;
	
	return $npi_db->affectedrows();
}

function npi_sql_escape($sql) {
	global $npi_db;
	
	return $npi_db->escape($sql);
}

function npi_sql_build($cmd, $a, $b = false) {
	global $npi_db;
	
	if (is_object($a)) {
		$_a = w();
		foreach ($a as $a_k => $a_v) {
			$_a[$a_k] = $a_v;
		}
		
		$a = $_a;
	}
	
	return $npi_db->build($cmd, $a, $b);
}

function npi_sql_cache($sql, $sid = '', $private = true) {
	global $npi_db;
	
	return $npi_db->cache($sql, $sid, $private);
}

function npi_sql_cache_limit(&$arr, $start, $end = 0) {
	global $npi_db;
	
	return $npi_db->cache_limit($arr, $start, $end);
}

function npi_sql_numrows(&$a) {
	$response = $a['_numrows'];
	unset($a['_numrows']);
	
	return $response;
}

function npi_sql_history() {
	global $npi_db;
	
	return $npi_db->history();
}
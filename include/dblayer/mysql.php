<?php

/**
 * Copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * MySQL driver on top of PDO. Schema SQL matches the legacy mysqli
 * driver so both produce identical databases.
 */

require_once PUN_ROOT.'include/dblayer/pdo_common.php';

if (!in_array('mysql', PDO::getAvailableDrivers()))
	exit('This PHP environment doesn\'t have the PDO MySQL driver (pdo_mysql) built in. pdo_mysql is required if you want to use the MySQL (PDO) database type to run this forum. Consult the PHP documentation for further assistance.');


class MysqlPdoDBLayer extends PdoDBLayer
{
	var $datatype_transformations = array(
		'%^SERIAL$%'	=>	'INT(10) UNSIGNED AUTO_INCREMENT'
	);


	function __construct($db_host, $db_username, $db_password, $db_name, $db_prefix, $p_connect)
	{
		$this->prefix = $db_prefix;

		$dsn = 'mysql:dbname='.$db_name;

		// Was a custom port supplied with $db_host?
		if (strpos($db_host, ':') !== false)
		{
			list($db_host, $db_port) = explode(':', $db_host);
			$dsn .= ';host='.$db_host.';port='.$db_port;
		}
		else
			$dsn .= ';host='.$db_host;

		$options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
		if ($p_connect)
			$options[PDO::ATTR_PERSISTENT] = true;

		try
		{
			$this->pdo = new PDO($dsn, $db_username, $db_password, $options);
		}
		catch (PDOException $e)
		{
			error('Unable to connect to MySQL and select database. MySQL reported: '.$e->getMessage(), __FILE__, __LINE__);
		}

		$this->init_connection();

		// Setup the client-server character set (UTF-8)
		if (!defined('FORUM_NO_SET_NAMES'))
			$this->set_names('utf8');
	}


	function get_names()
	{
		$result = $this->query('SHOW VARIABLES LIKE \'character_set_connection\'');
		return $this->result($result, 0, 1);
	}


	function set_names($names)
	{
		try { $this->pdo->exec('SET NAMES \''.$this->escape($names).'\''); return true; }
		catch (PDOException $e) { return false; }
	}


	function get_version()
	{
		$result = $this->query('SELECT VERSION()');

		return array(
			'name'		=> 'MySQL (PDO)',
			'version'	=> preg_replace('%^([^-]+).*$%', '\\1', $this->result($result))
		);
	}


	function table_exists($table_name, $no_prefix = false)
	{
		$result = $this->query('SHOW TABLES LIKE \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\'');
		return $this->has_rows($result);
	}


	function field_exists($table_name, $field_name, $no_prefix = false)
	{
		$result = $this->query('SHOW COLUMNS FROM '.($no_prefix ? '' : $this->prefix).$table_name.' LIKE \''.$this->escape($field_name).'\'');
		return $this->has_rows($result);
	}


	function index_exists($table_name, $index_name, $no_prefix = false)
	{
		$exists = false;

		$result = $this->query('SHOW INDEX FROM '.($no_prefix ? '' : $this->prefix).$table_name);
		while ($cur_index = $this->fetch_assoc($result))
		{
			if (strtolower($cur_index['Key_name']) == strtolower(($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name))
			{
				$exists = true;
				break;
			}
		}

		return $exists;
	}


	function create_table($table_name, $schema, $no_prefix = false)
	{
		if ($this->table_exists($table_name, $no_prefix))
			return true;

		$query = 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$table_name." (\n";

		// Go through every schema element and add it to the query
		foreach ($schema['FIELDS'] as $field_name => $field_data)
		{
			$field_data['datatype'] = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_data['datatype']);

			$query .= $field_name.' '.$field_data['datatype'];

			if (isset($field_data['collation']))
				$query .= 'CHARACTER SET utf8 COLLATE utf8_'.$field_data['collation'];

			if (!$field_data['allow_null'])
				$query .= ' NOT NULL';

			if (isset($field_data['default']))
				$query .= ' DEFAULT '.$field_data['default'];

			$query .= ",\n";
		}

		// If we have a primary key, add it
		if (isset($schema['PRIMARY KEY']))
			$query .= 'PRIMARY KEY ('.implode(',', $schema['PRIMARY KEY']).'),'."\n";

		// Add unique keys
		if (isset($schema['UNIQUE KEYS']))
		{
			foreach ($schema['UNIQUE KEYS'] as $key_name => $key_fields)
				$query .= 'UNIQUE KEY '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$key_name.'('.implode(',', $key_fields).'),'."\n";
		}

		// Add indexes
		if (isset($schema['INDEXES']))
		{
			foreach ($schema['INDEXES'] as $index_name => $index_fields)
				$query .= 'KEY '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name.'('.implode(',', $index_fields).'),'."\n";
		}

		// We remove the last two characters (a newline and a comma) and add on the ending
		$query = substr($query, 0, strlen($query) - 2)."\n".') ENGINE = '.(isset($schema['ENGINE']) ? $schema['ENGINE'] : 'MyISAM').' CHARACTER SET utf8';

		return $this->query($query) ? true : false;
	}


	function drop_table($table_name, $no_prefix = false)
	{
		if (!$this->table_exists($table_name, $no_prefix))
			return true;

		return $this->query('DROP TABLE '.($no_prefix ? '' : $this->prefix).$table_name) ? true : false;
	}


	function rename_table($old_table, $new_table, $no_prefix = false)
	{
		// If the new table exists and the old one doesn't, then we're happy
		if ($this->table_exists($new_table, $no_prefix) && !$this->table_exists($old_table, $no_prefix))
			return true;

		return $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$old_table.' RENAME TO '.($no_prefix ? '' : $this->prefix).$new_table) ? true : false;
	}


	function add_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null, $no_prefix = false)
	{
		if ($this->field_exists($table_name, $field_name, $no_prefix))
			return true;

		$field_type = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_type);

		if (!is_null($default_value) && !is_int($default_value) && !is_float($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		return $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' ADD '.$field_name.' '.$field_type.($allow_null ? '' : ' NOT NULL').(!is_null($default_value) ? ' DEFAULT '.$default_value : '').(!is_null($after_field) ? ' AFTER '.$after_field : '')) ? true : false;
	}


	function alter_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return true;

		$field_type = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_type);

		if (!is_null($default_value) && !is_int($default_value) && !is_float($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		return $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' MODIFY '.$field_name.' '.$field_type.($allow_null ? '' : ' NOT NULL').(!is_null($default_value) ? ' DEFAULT '.$default_value : '').(!is_null($after_field) ? ' AFTER '.$after_field : '')) ? true : false;
	}


	function drop_field($table_name, $field_name, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return true;

		return $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' DROP '.$field_name) ? true : false;
	}


	function add_index($table_name, $index_name, $index_fields, $unique = false, $no_prefix = false)
	{
		if ($this->index_exists($table_name, $index_name, $no_prefix))
			return true;

		return $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' ADD '.($unique ? 'UNIQUE ' : '').'INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name.' ('.implode(',', $index_fields).')') ? true : false;
	}


	function drop_index($table_name, $index_name, $no_prefix = false)
	{
		if (!$this->index_exists($table_name, $index_name, $no_prefix))
			return true;

		return $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' DROP INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name) ? true : false;
	}


	function truncate_table($table_name, $no_prefix = false)
	{
		return $this->query('TRUNCATE TABLE '.($no_prefix ? '' : $this->prefix).$table_name) ? true : false;
	}
}

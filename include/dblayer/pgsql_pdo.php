<?php

/**
 * Copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * PostgreSQL driver on top of PDO. Schema SQL matches the legacy pgsql
 * driver so both produce identical databases.
 */

require_once PUN_ROOT.'include/dblayer/pdo_common.php';

if (!in_array('pgsql', PDO::getAvailableDrivers()))
	exit('This PHP environment doesn\'t have the PDO PostgreSQL driver (pdo_pgsql) built in. pdo_pgsql is required if you want to use the PostgreSQL (PDO) database type to run this forum. Consult the PHP documentation for further assistance.');


class PgsqlPdoDBLayer extends PdoDBLayer
{
	var $datatype_transformations = array(
		'%^(TINY|SMALL)INT( )?(\\([0-9]+\\))?( )?(UNSIGNED)?$%i'			=>	'SMALLINT',
		'%^(MEDIUM)?INT( )?(\\([0-9]+\\))?( )?(UNSIGNED)?$%i'				=>	'INTEGER',
		'%^BIGINT( )?(\\([0-9]+\\))?( )?(UNSIGNED)?$%i'						=>	'BIGINT',
		'%^(TINY|MEDIUM|LONG)?TEXT$%i'										=>	'TEXT',
		'%^DOUBLE( )?(\\([0-9,]+\\))?( )?(UNSIGNED)?$%i'					=>	'DOUBLE PRECISION',
		'%^FLOAT( )?(\\([0-9]+\\))?( )?(UNSIGNED)?$%i'						=>	'REAL'
	);


	function __construct($db_host, $db_username, $db_password, $db_name, $db_prefix, $p_connect)
	{
		$this->prefix = $db_prefix;

		$dsn_parts = array();

		if ($db_host)
		{
			if (strpos($db_host, ':') !== false)
			{
				list($db_host, $db_port) = explode(':', $db_host);
				$dsn_parts[] = 'host='.$db_host;
				$dsn_parts[] = 'port='.$db_port;
			}
			else
				$dsn_parts[] = 'host='.$db_host;
		}

		if ($db_name)
			$dsn_parts[] = 'dbname='.$db_name;

		$options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
		if ($p_connect)
			$options[PDO::ATTR_PERSISTENT] = true;

		try
		{
			$this->pdo = new PDO('pgsql:'.implode(';', $dsn_parts), $db_username, $db_password, $options);
		}
		catch (PDOException $e)
		{
			error('Unable to connect to PostgreSQL server. PostgreSQL reported: '.$e->getMessage(), __FILE__, __LINE__);
		}

		$this->init_connection();

		// Setup the client-server character set (UTF-8)
		if (!defined('FORUM_NO_SET_NAMES'))
			$this->set_names('utf8');
	}


	function start_transaction()
	{
		++$this->in_transaction;

		try { $this->pdo->exec('BEGIN'); return true; }
		catch (PDOException $e) { return false; }
	}


	function end_transaction()
	{
		--$this->in_transaction;

		try { $this->pdo->exec('COMMIT'); return true; }
		catch (PDOException $e)
		{
			try { $this->pdo->exec('ROLLBACK'); } catch (PDOException $ignored) {}
			return false;
		}
	}


	function query($sql, $unbuffered = false)
	{
		// MySQL-style "LIMIT offset,count" must become
		// "LIMIT count OFFSET offset" (same rewrite as the pgsql driver)
		if (strrpos($sql, 'LIMIT') !== false)
			$sql = preg_replace('%LIMIT ([0-9]+),([ 0-9]+)%', 'LIMIT \\2 OFFSET \\1', $sql);

		return parent::query($sql, $unbuffered);
	}


	protected function fetch_insert_id()
	{
		// currval of the table's sequence, mirroring the pgsql driver
		if (preg_match('%^\s*INSERT INTO ([a-z0-9\_\-]+)%is', $this->last_query_text, $table_name))
		{
			// Hack (don't ask) — pg sequence for *groups tables has _g suffix
			if (substr($table_name[1], -6) == 'groups')
				$table_name[1] .= '_g';

			try
			{
				$statement = $this->pdo->query('SELECT currval(\''.$table_name[1].'_id_seq\')');
				$row = $statement->fetch(PDO::FETCH_NUM);
				$statement->closeCursor();
				if ($row !== false && $row !== null)
					return (int) $row[0];
			}
			catch (PDOException $e)
			{
				// Table has no sequence, or nextval was never called
			}
		}

		return 0;
	}


	function get_names()
	{
		$result = $this->query('SHOW client_encoding');
		return strtolower($this->result($result)); // MySQL returns lowercase so lets be consistent
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
			'name'		=> 'PostgreSQL (PDO)',
			'version'	=> preg_replace('%^[^0-9]+([^\s,-]+).*$%', '\\1', $this->result($result))
		);
	}


	function table_exists($table_name, $no_prefix = false)
	{
		$result = $this->query('SELECT 1 FROM pg_class WHERE relname = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\'');
		return $this->has_rows($result);
	}


	function field_exists($table_name, $field_name, $no_prefix = false)
	{
		$result = $this->query('SELECT 1 FROM pg_class c INNER JOIN pg_attribute a ON a.attrelid = c.oid WHERE c.relname = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND a.attname = \''.$this->escape($field_name).'\'');
		return $this->has_rows($result);
	}


	function index_exists($table_name, $index_name, $no_prefix = false)
	{
		$result = $this->query('SELECT 1 FROM pg_index i INNER JOIN pg_class c1 ON c1.oid = i.indrelid INNER JOIN pg_class c2 ON c2.oid = i.indexrelid WHERE c1.relname = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND c2.relname = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_'.$this->escape($index_name).'\'');
		return $this->has_rows($result);
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

			// The SERIAL datatype is a special case where we don't need to say not null
			if (!$field_data['allow_null'] && $field_data['datatype'] != 'SERIAL')
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
				$query .= 'UNIQUE ('.implode(',', $key_fields).'),'."\n";
		}

		// We remove the last two characters (a newline and a comma) and add on the ending
		$query = substr($query, 0, strlen($query) - 2)."\n".')';

		$result = $this->query($query) ? true : false;

		// Add indexes
		if (isset($schema['INDEXES']))
		{
			foreach ($schema['INDEXES'] as $index_name => $index_fields)
				$result &= $this->add_index($table_name, $index_name, $index_fields, false, $no_prefix);
		}

		return (bool) $result;
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

		$result = $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' ADD '.$field_name.' '.$field_type) ? true : false;

		if (!is_null($default_value))
		{
			if (!is_int($default_value) && !is_float($default_value))
				$default_value = '\''.$this->escape($default_value).'\'';

			$result &= $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' ALTER '.$field_name.' SET DEFAULT '.$default_value) ? true : false;
			$result &= $this->query('UPDATE '.($no_prefix ? '' : $this->prefix).$table_name.' SET '.$field_name.'='.$default_value) ? true : false;
		}

		if (!$allow_null)
			$result &= $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' ALTER '.$field_name.' SET NOT NULL') ? true : false;

		return (bool) $result;
	}


	function alter_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return true;

		$field_type = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_type);

		$result = $this->add_field($table_name, 'tmp_'.$field_name, $field_type, $allow_null, $default_value, $after_field, $no_prefix);
		$result &= $this->query('UPDATE '.($no_prefix ? '' : $this->prefix).$table_name.' SET tmp_'.$field_name.' = '.$field_name) ? true : false;
		$result &= $this->drop_field($table_name, $field_name, $no_prefix);
		$result &= $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' RENAME COLUMN tmp_'.$field_name.' TO '.$field_name) ? true : false;

		return (bool) $result;
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

		return $this->query('CREATE '.($unique ? 'UNIQUE ' : '').'INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name.' ON '.($no_prefix ? '' : $this->prefix).$table_name.'('.implode(',', $index_fields).')') ? true : false;
	}


	function drop_index($table_name, $index_name, $no_prefix = false)
	{
		if (!$this->index_exists($table_name, $index_name, $no_prefix))
			return true;

		return $this->query('DROP INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name) ? true : false;
	}


	function truncate_table($table_name, $no_prefix = false)
	{
		return $this->query('TRUNCATE TABLE '.($no_prefix ? '' : $this->prefix).$table_name) ? true : false;
	}
}

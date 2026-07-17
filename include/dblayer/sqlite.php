<?php

/**
 * Copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 *
 * SQLite3 driver on top of PDO. Replaces the historical SQLite2 driver
 * (the sqlite extension was removed from PHP in 5.4). Keeps the same
 * db_type name ('sqlite') so the existing dialect branches in
 * install.php, db_update.php and functions.php apply unchanged.
 *
 * Requires SQLite 3.25+ (RENAME COLUMN) and, ideally, 3.35+
 * (DROP COLUMN). $db_name is the path to the database file.
 */

require_once PUN_ROOT.'include/dblayer/pdo_common.php';

if (!in_array('sqlite', PDO::getAvailableDrivers()))
	exit('This PHP environment doesn\'t have the PDO SQLite driver (pdo_sqlite) built in. pdo_sqlite is required if you want to use an SQLite database to run this forum. Consult the PHP documentation for further assistance.');


class SqlitePdoDBLayer extends PdoDBLayer
{
	var $datatype_transformations = array(
		'%^SERIAL$%'															=>	'INTEGER',
		'%^(TINY|SMALL|MEDIUM|BIG)?INT( )?(\\([0-9]+\\))?( )?(UNSIGNED)?$%i'	=>	'INTEGER',
		'%^(TINY|MEDIUM|LONG)?TEXT$%i'											=>	'TEXT'
	);


	function __construct($db_name, $db_prefix, $p_connect)
	{
		$this->prefix = $db_prefix;

		if ($db_name == '')
			error('You have not specified a database name', __FILE__, __LINE__);

		try
		{
			$this->pdo = new PDO('sqlite:'.$db_name);
		}
		catch (PDOException $e)
		{
			error('Unable to open database \''.$db_name.'\'. SQLite reported: '.$e->getMessage(), __FILE__, __LINE__);
		}

		$this->init_connection();

		// Wait rather than fail when another request holds the write lock
		$this->pdo->exec('PRAGMA busy_timeout = 10000');
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


	function get_names()
	{
		return '';
	}


	function set_names($names)
	{
		return true;
	}


	function get_version()
	{
		$result = $this->query('SELECT sqlite_version()');

		return array(
			'name'		=> 'SQLite3 (PDO)',
			'version'	=> $this->result($result)
		);
	}


	function table_exists($table_name, $no_prefix = false)
	{
		$result = $this->query('SELECT 1 FROM sqlite_master WHERE name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND type=\'table\'');
		return $this->has_rows($result);
	}


	function field_exists($table_name, $field_name, $no_prefix = false)
	{
		$result = $this->query('SELECT 1 FROM pragma_table_info(\''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\') WHERE name = \''.$this->escape($field_name).'\'');
		return $this->has_rows($result);
	}


	function index_exists($table_name, $index_name, $no_prefix = false)
	{
		$result = $this->query('SELECT 1 FROM sqlite_master WHERE tbl_name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_'.$this->escape($index_name).'\' AND type=\'index\'');
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

		if (!is_null($default_value) && !is_int($default_value) && !is_float($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		// SQLite cannot add a NOT NULL column without a default; fall
		// back to a NULL-able column in that (rare, migration-only) case
		if (!$allow_null && is_null($default_value))
			$allow_null = true;

		// $after_field is ignored: SQLite always appends (column order
		// is cosmetic; nothing in the codebase relies on it)
		return $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' ADD COLUMN '.$field_name.' '.$field_type.($allow_null ? '' : ' NOT NULL').(!is_null($default_value) ? ' DEFAULT '.$default_value : '')) ? true : false;
	}


	function alter_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null, $no_prefix = false)
	{
		// Unneeded for SQLite: column types are advisory (dynamic typing)
		return true;
	}


	function drop_field($table_name, $field_name, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return true;

		// Requires SQLite 3.35+
		return $this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' DROP COLUMN '.$field_name) ? true : false;
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
		return $this->query('DELETE FROM '.($no_prefix ? '' : $this->prefix).$table_name) ? true : false;
	}
}

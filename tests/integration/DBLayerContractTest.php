<?php

use PHPUnit\Framework\TestCase;

/**
 * Driver-agnostic contract test for the DBLayer interface.
 *
 * Runs the full interface against a live database and pins the exact
 * behavior the rest of the codebase relies on (return types, false vs
 * null conventions, escaping, schema helpers). The point: the mysqli
 * driver defines the baseline, and any new driver (PDO) must pass this
 * suite identically.
 *
 * Select the driver with environment variables:
 *   DB_TYPE=mysqli|mysql|sqlite|pgsql  (default mysqli)
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS (defaults for local testing)
 * For sqlite, DB_NAME is the database file path.
 */
class DBLayerContractTest extends TestCase
{
	protected static $db = null;

	protected static function db()
	{
		if (self::$db !== null)
			return self::$db;

		if (!defined('PUN'))
			define('PUN', 1);
		if (!defined('PUN_ROOT'))
			define('PUN_ROOT', dirname(dirname(__DIR__)).'/');

		// error() is normally defined by common.php
		if (!function_exists('error'))
		{
			function error($message, $file = null, $line = null, $db_error = false)
			{
				$detail = is_array($db_error) ? ' ['.$db_error['error_msg'].']' : '';
				throw new RuntimeException('EVEBB error(): '.$message.$detail);
			}
		}

		$db_type = getenv('DB_TYPE') ?: 'mysqli';
		$db_host = getenv('DB_HOST') ?: 'localhost';
		$db_name = getenv('DB_NAME') ?: 'evebb_test';
		$db_username = getenv('DB_USER') ?: 'flux';
		$db_password = getenv('DB_PASS') ?: 'fluxpass';
		$db_prefix = 'test_';
		$p_connect = false;

		require PUN_ROOT.'include/dblayer/common_db.php';

		self::$db = $db;
		return self::$db;
	}

	public static function setUpBeforeClass(): void
	{
		$db = self::db();
		$db->drop_table('unit');
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$db)
		{
			self::$db->drop_table('unit');
			self::$db->close();
			self::$db = null;
		}
	}

	// ---- schema helpers ----

	public function testCreateTableAndExistence()
	{
		$db = self::db();

		$schema = array(
			'FIELDS' => array(
				'id' => array(
					'datatype'		=> 'SERIAL',
					'allow_null'	=> false,
				),
				'name' => array(
					'datatype'		=> 'VARCHAR(200)',
					'allow_null'	=> false,
					'default'		=> '\'\'',
				),
				'score' => array(
					'datatype'		=> 'INT(10)',
					'allow_null'	=> true,
				),
			),
			'PRIMARY KEY' => array('id'),
			'INDEXES' => array(
				'name_idx' => array('name'),
			),
		);

		$this->assertTrue($db->create_table('unit', $schema));
		$this->assertTrue($db->table_exists('unit'));
		$this->assertFalse($db->table_exists('unit_nope'));

		// create_table on an existing table is a no-op returning true
		$this->assertTrue($db->create_table('unit', $schema));
	}

	public function testFieldAndIndexExistence()
	{
		$db = self::db();

		$this->assertTrue($db->field_exists('unit', 'name'));
		$this->assertFalse($db->field_exists('unit', 'nope'));

		$this->assertTrue($db->index_exists('unit', 'name_idx'));
		$this->assertFalse($db->index_exists('unit', 'nope_idx'));
	}

	public function testAddAlterDropField()
	{
		$db = self::db();

		$this->assertTrue($db->add_field('unit', 'extra', 'VARCHAR(50)', true));
		$this->assertTrue($db->field_exists('unit', 'extra'));

		// add_field on existing field is a no-op returning true
		$this->assertTrue($db->add_field('unit', 'extra', 'VARCHAR(50)', true));

		$this->assertTrue($db->alter_field('unit', 'extra', 'VARCHAR(80)', true));

		$this->assertTrue($db->drop_field('unit', 'extra'));
		$this->assertFalse($db->field_exists('unit', 'extra'));

		// drop_field on missing field is a no-op returning true
		$this->assertTrue($db->drop_field('unit', 'extra'));
	}

	public function testAddDropIndex()
	{
		$db = self::db();

		$this->assertTrue($db->add_index('unit', 'score_idx', array('score')));
		$this->assertTrue($db->index_exists('unit', 'score_idx'));
		$this->assertTrue($db->drop_index('unit', 'score_idx'));
		$this->assertFalse($db->index_exists('unit', 'score_idx'));
	}

	// ---- CRUD behavior ----

	public function testInsertAndInsertId()
	{
		$db = self::db();

		$this->assertNotEquals(false, $db->query('INSERT INTO test_unit (name, score) VALUES (\'alpha\', 10)'));
		$id1 = $db->insert_id();
		$this->assertNotEquals(false, $db->query('INSERT INTO test_unit (name, score) VALUES (\'beta\', 20)'));
		$id2 = $db->insert_id();

		$this->assertSame(1, (int) $id1);
		$this->assertSame(2, (int) $id2);
	}

	public function testAffectedRows()
	{
		$db = self::db();

		$db->query('UPDATE test_unit SET score = score + 1');
		$this->assertSame(2, (int) $db->affected_rows());
	}

	public function testFetchAssocReturnsStringKeys()
	{
		$db = self::db();

		$result = $db->query('SELECT id, name, score FROM test_unit ORDER BY id');
		$row = $db->fetch_assoc($result);

		$this->assertIsArray($row);
		$this->assertSame('alpha', $row['name']);
		$this->assertArrayHasKey('id', $row);
		$this->assertArrayHasKey('score', $row);

		// second row, then exhaustion
		$row2 = $db->fetch_assoc($result);
		$this->assertSame('beta', $row2['name']);
		$exhausted = $db->fetch_assoc($result);
		$this->assertTrue($exhausted === false || $exhausted === null, 'exhausted fetch_assoc must be false/null, got '.var_export($exhausted, true));

		$db->free_result($result);
	}

	public function testFetchRowReturnsNumericKeys()
	{
		$db = self::db();

		$result = $db->query('SELECT name FROM test_unit ORDER BY id');
		$row = $db->fetch_row($result);
		$this->assertSame('alpha', $row[0]);
		$db->free_result($result);
	}

	public function testResultSeeksRowsAndColumns()
	{
		$db = self::db();

		$result = $db->query('SELECT name, score FROM test_unit ORDER BY id');

		// (row 0, col 0) implicit
		$this->assertSame('alpha', $db->result($result));

		$result = $db->query('SELECT name, score FROM test_unit ORDER BY id');
		$this->assertSame('beta', $db->result($result, 1, 0));

		$result = $db->query('SELECT name FROM test_unit WHERE 1 = 0');
		$this->assertFalse($db->result($result), 'result() on empty set must be false');
	}

	public function testHasRows()
	{
		$db = self::db();

		$this->assertTrue($db->has_rows($db->query('SELECT 1 FROM test_unit')));
		$this->assertFalse($db->has_rows($db->query('SELECT 1 FROM test_unit WHERE 1 = 0')));
	}

	public function testFailedQueryReturnsFalseAndSetsError()
	{
		$db = self::db();

		$this->assertFalse($db->query('SELECT * FROM test_nonexistent_table_xyz'));

		$error = $db->error();
		$this->assertIsArray($error);
		$this->assertArrayHasKey('error_msg', $error);
		$this->assertNotEquals('', (string) $error['error_msg']);
	}

	// ---- escaping ----

	public function testEscapePreventsQuoteBreakout()
	{
		$db = self::db();

		$evil = "x'; DROP TABLE test_unit; --";
		$db->query('INSERT INTO test_unit (name, score) VALUES (\''.$db->escape($evil).'\', 1)');

		$result = $db->query('SELECT name FROM test_unit WHERE name = \''.$db->escape($evil).'\'');
		$this->assertSame($evil, $db->result($result));
		$this->assertTrue($db->table_exists('unit'), 'table must survive the attempted injection');
	}

	public function testEscapeReturnsEmptyStringForArrays()
	{
		$db = self::db();
		$this->assertSame('', $db->escape(array('x')));
	}

	public function testUtf8RoundTrip()
	{
		$db = self::db();

		$s = 'héllo wörld 日本語 ✓';
		$db->query('INSERT INTO test_unit (name, score) VALUES (\''.$db->escape($s).'\', 5)');
		$result = $db->query('SELECT name FROM test_unit WHERE score = 5');
		$this->assertSame($s, $db->result($result));
	}

	// ---- misc plumbing ----

	public function testTransactionsAreCallable()
	{
		$db = self::db();

		$db->start_transaction();
		$db->query('INSERT INTO test_unit (name, score) VALUES (\'tx\', 99)');
		$db->end_transaction();

		$result = $db->query('SELECT COUNT(*) FROM test_unit WHERE name = \'tx\'');
		$this->assertSame(1, (int) $db->result($result));
	}

	public function testQueryCountIncrements()
	{
		$db = self::db();

		$before = $db->get_num_queries();
		$db->query('SELECT 1');
		$this->assertSame($before + 1, $db->get_num_queries());
	}

	public function testGetVersionReturnsNameAndVersion()
	{
		$db = self::db();

		$v = $db->get_version();
		$this->assertIsArray($v);
		$this->assertArrayHasKey('name', $v);
		$this->assertArrayHasKey('version', $v);
		$this->assertMatchesRegularExpression('%^[0-9]+\.%', $v['version']);
	}

	public function testTruncateRenameDrop()
	{
		$db = self::db();

		$this->assertTrue($db->truncate_table('unit'));
		$result = $db->query('SELECT COUNT(*) FROM test_unit');
		$this->assertSame(0, (int) $db->result($result));

		$this->assertTrue($db->rename_table('unit', 'unit_renamed'));
		$this->assertTrue($db->table_exists('unit_renamed'));
		$this->assertFalse($db->table_exists('unit'));
		$this->assertTrue($db->rename_table('unit_renamed', 'unit'));

		$this->assertTrue($db->drop_table('unit'));
		$this->assertFalse($db->table_exists('unit'));

		// drop_table on missing table is a no-op returning true
		$this->assertTrue($db->drop_table('unit'));
	}
}

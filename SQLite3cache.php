<?php

if (!defined('SMF'))
	die('Hacking attempt...');

if (!class_exists('SQLite3')) {
	class SQLite3 {
		var $handle;
		function SQLite3($data) {
			$this->handle = sqlite_open($data);
		}
		public function exec($data) {
			return sqlite_exec($this->handle, $data);
		}
		public function querySingle($data, $type = false) {
			if ($type == true) {$type = false;} else {$type = true;}
			return sqlite_single_query($this->handle, $data, $type);
		}
		public function escapeString($data) {
			return sqlite_escape_string($data);
		}
		public function lastErrorCode() {
			return sqlite_last_error();
		}
	}
}

function sicache_ini() {

	global $sicacheDB, $sicache_trans, $sicachePurge, $cachedir, $sicache_time, $boardurl, $sourcedir;

	$database = $cachedir.'/'.md5($boardurl . filemtime($sourcedir . '/Load.php')).'cache.db';

	$sicacheDB = new SQLite3($database);
	if (filesize($database) == 0) {
		@$sicacheDB->exec('PRAGMA synchronous=OFF;PRAGMA journal_mode=MEMORY;CREATE TABLE cache (key text unique, value blob, ttl int);CREATE INDEX ttls ON cache(ttl);');
		$sicachePurge = true;
	}
	@$sicacheDB->exec('PRAGMA synchronous=OFF;PRAGMA journal_mode=MEMORY;BEGIN;');
	if (!isset($sicache_trans)) {
		$sicache_time = time();
		$sicache_trans = 'DELETE FROM cache WHERE ttl < '.$sicache_time.';';
	}
	register_shutdown_function('sicache_trans');
}

function sicache_get($key) {

	global $sicacheDB, $sicache_time, $sicache_trans, $sicachePurge;

	if(!isset($key) || isset($sicachePurge))
		return;

	if(!isset($sicacheDB)) {
		sicache_ini();
	}

	$query = @$sicacheDB->querySingle('SELECT value FROM cache WHERE key = \''.$sicacheDB->escapeString($key).'\' AND ttl > '.$sicache_time.' LIMIT 1', false);
	if ($query != false) {
		return gzinflate(sqlite_udf_decode_binary($query));
	}
}

function sicache_put($key, $value = '', $ttl = 120) {

	global $sicacheDB, $sicache_trans, $sicache_time;

	if(!isset($key))
		return;

	if(!isset($sicacheDB)) {
		sicache_ini();
	}

	if (!$value && !isset($sicachePurge)) {
		$sicache_trans.= 'DELETE FROM cache WHERE key = \''.$sicacheDB->escapeString($key).'\';';
	} elseif ($value) {
		$sicache_trans.= 'INSERT INTO cache VALUES (\''.$sicacheDB->escapeString($key).'\', \''.sqlite_udf_encode_binary(gzdeflate($value, 9)).'\', '.($sicache_time + $ttl).');';
	}
}

function sicache_clean($key) {

	global $sicacheDB, $sicache_trans, $sicachePurge;

	if(!isset($sicacheDB)) {
		sicache_ini();
	}

	if(!isset($key) && $key != '') {
		$sicache_trans = 'DELETE * FROM cache;';
		$sicachePurge = true;
	} else {
		$sicache_trans.= 'DELETE FROM cache WHERE key LIKE \''.$sicacheDB->escapeString($key).'\';';
	}
}

function sicache_destroy() {

	global $cachedir, $boardurl, $sourcedir;

	@unlink($cachedir.'/'.md5($boardurl . filemtime($sourcedir . '/Load.php')).'cache.db');
}

function sicache_trans() {

	global $sicacheDB, $sicache_trans;

	while (@ob_end_flush());

	if (isset($sicacheDB)) {
		@$sicacheDB->exec($sicache_trans.'COMMIT;VACUUM;');
	}

	if ($sicacheDB->lastErrorCode() == 11)
		sicache_destroy();
}
?>
--TEST--
CacheXCache::response_manager()

--SKIPIF--
<?php
	if (!function_exists('xcache_set')) print 'skip XCache extension not available';
?>

--FILE--
<?php
	require_once dirname(__FILE__) . '/../cachecore.class.php';
	require_once dirname(__FILE__) . '/../cachexcache.class.php';

	function fetch_data($url)
	{
		$http = curl_init($url);
		curl_setopt($http, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($http, CURLOPT_BINARYTRANSFER, true);

		if ($output = curl_exec($http))
		{
			return $output;
		}

		return null;
	}

	$cache = new CacheXCache('test', null, 2);
	var_dump($cache->read());
	var_dump($cache->response_manager('fetch_data', 'http://cachecore.googlecode.com/svn/trunk/_tests/test_request.txt'));
	$start = $cache->timestamp();
	sleep(1);
	var_dump($cache->response_manager('fetch_data', 'http://cachecore.googlecode.com/svn/trunk/_tests/test_request.txt'));
	$end = $cache->timestamp();
	var_dump($start == $end);
	sleep(2);
	var_dump($cache->response_manager('fetch_data', 'http://cachecore.googlecode.com/svn/trunk/_tests/test_request.txt'));
	$start_again = $cache->timestamp();
	var_dump($start_again > $end);
?>

--EXPECT--
bool(false)
string(48) "abcdefghijklmnopqrstuvwxyz
0123456789
!@#$%^&*()"
string(48) "abcdefghijklmnopqrstuvwxyz
0123456789
!@#$%^&*()"
bool(true)
string(48) "abcdefghijklmnopqrstuvwxyz
0123456789
!@#$%^&*()"
bool(false)

--CLEAN--
<?php
	require_once dirname(__FILE__) . '/../cachecore.class.php';
	require_once dirname(__FILE__) . '/../cachexcache.class.php';
	$cache = new CacheXCache('test', null, 60);
	$cache->delete();
?>

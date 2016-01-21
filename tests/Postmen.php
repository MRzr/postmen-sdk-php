<?php

use Postmen\PostmenException;
use Postmen\Postmen;
use Postmen\FakePostmen;

class PostmenTest extends PHPUnit_Framework_TestCase {
	/** Checks if exception will be raised in case if
	 *  returned meta code is different than 200
	 */
	public function testRaiseException() {
		$handler = new Postmen('', '');

		$curl_response = '{"meta":{"code":200,"message":"OK","details":[]},"data":{}}';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->atLeastOnce() )->will($this->returnValue($curl_response));

		try {
			$handler->get('labels', '');
		} catch (Exception $exception) {
			$this->fail("Expected not to raise any exceptions when returned meta code is 200");
		}
	}
	/** Checks if exception will be raised in case if
	 *  returned object is not a valid JSON
	 */
	public function testNonSerializableJSON() {
		$handler = new Postmen('', '');

		$exceptionClass = get_class(new PostmenException('', 200, '', ''));
		$this->setExpectedException($exceptionClass);

		$curl_response = 'THIS STRING IS NOT A VALID JSON OBJECT';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->atLeastOnce() )->will($this->returnValue($curl_response));

		$result = $handler->get('labels', '');
	}
	/** Checks if safe mode will prevent of throwing an exception
	 *  also verifies if invalid JSON response exception will
	 *  contain correct error code, message and details
	 */
	public function testSafeModeEnabled() {
		$handler = new Postmen('', '');

		$curl_response = 'NOT VALID JSON, BUT EXCEPTION IS NOT GOING TO BE RAISED';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->atLeastOnce() )->will($this->returnValue($curl_response));

		$result = $handler->get('labels', '', array('safe' => true));
		$exception = $handler->getError();

		$this->assertNull($result);
		$this->assertEquals($exception->getCode(), 500);
		$this->assertEquals($exception->isRetryable(), false);
		$this->assertEquals($exception->getMessage(), 'Something went wrong on Postmen\'s end');
		$this->assertEquals(count($exception->getDetails()), 0);
	}
	/** Checks if meta code different than 200 raises an exception
	 *  verifies if there is a match on exception code and message
	 */
	public function testRateLimitExceeded() {
		$message = 'THIS IS ERROR MESSAGE RETURNED FROM API';
		$code = 999;
		$handler = new Postmen('', '');

		$curl_response = '{"meta":{"code":' . $code . ',"message":"' . $message . '","details":[]},"data":{}}';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->atLeastOnce())->will($this->returnValue($curl_response));

		try{
			$result = $handler->get('labels', '');
			$this->fail("Expected exception not thrown");
		} catch (Exception $exception){
			$this->assertEquals($exception->getCode(), $code);
			$this->assertEquals($exception->getMessage(), $message);
		}
	}

	/** Checks if will retry after delay
	 *  if curl failes to process the request
	 */
	public function testRetryCurl() {
		$handler = new Postmen('', '', array('retry' => true));

		$curl_response = '{"meta":{"code":200,"message":"OK","details":[]},"data":{}}';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->any())->will($this->returnValue($curl_response));

		$mock_error = new PHPUnit_Extensions_MockFunction('curl_error', $handler);
		$mock_error->expects($this->at(0))->will($this->returnValue(true));
		$mock_error->expects($this->at(1))->will($this->returnValue(true));
		$mock_error->expects($this->at(2))->will($this->returnValue(false));

		date_default_timezone_set('UTC');
		$before = date_create();
		$result = $handler->get('labels', '');
		$after = date_create();

	       	$time = date_timestamp_get($after) - date_timestamp_get($before);
		$this->assertGreaterThanOrEqual(3, $time);
	}

	/** expects not to fail if we retry four times
	 *  5 times
	 */
	public function testRetryMaxAttempts() {
		$handler = new Postmen('', '', array('retry' => true));

		$curl_response = '{"meta":{"code":200,"message":"OK","details":[]},"data":{}}';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->any())->will($this->returnValue($curl_response));

		$mock_error = new PHPUnit_Extensions_MockFunction('curl_error', $handler);
		$mock_error->expects($this->at(0))->will($this->returnValue(true));
		$mock_error->expects($this->at(1))->will($this->returnValue(true));
		$mock_error->expects($this->at(2))->will($this->returnValue(true));
		$mock_error->expects($this->at(3))->will($this->returnValue(true));
		$mock_error->expects($this->at(4))->will($this->returnValue(false));

		$mock_sleep = new PHPUnit_Extensions_MockFunction('sleep', $handler);
		$mock_sleep->expects($this->any())->will($this->returnValue(1));

		$result = $handler->get('labels', '');
	}

	/** expects not to fail if we retry four times
	 *  5 times
	 */
	public function testRetryMaxAttemptsExceeded() {
		$handler = new Postmen('', '', array('retry' => true));

		$curl_response = '{"meta":{"code":200,"message":"OK","details":[]},"data":{}}';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->any())->will($this->returnValue($curl_response));

		$mock_error = new PHPUnit_Extensions_MockFunction('curl_error', $handler);
		$mock_error->expects($this->at(0))->will($this->returnValue(true));
		$mock_error->expects($this->at(1))->will($this->returnValue(true));
		$mock_error->expects($this->at(2))->will($this->returnValue(true));
		$mock_error->expects($this->at(3))->will($this->returnValue(true));
		$mock_error->expects($this->at(4))->will($this->returnValue(true));

		$exceptionClass = get_class(new PostmenException('', 200, '', ''));
		$this->setExpectedException($exceptionClass);

		$mock_sleep = new PHPUnit_Extensions_MockFunction('sleep', $handler);
		$mock_sleep->expects($this->any())->will($this->returnValue(1));

		$result = $handler->get('labels', '');
	}

	/** checks if will retry after delay
	 *  if postmen API returns retryable error
	 */
	public function testRetryPostmen() {
		$handler = new Postmen('', '', array('retry' => true));

		$curl_response_failed = '{"meta":{"code":500,"message":"error","retryable":true,"details":[]},"data":{}}';
		$curl_response_ok = '{"meta":{"code":200,"message":"OK","details":[]},"data":{}}';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->at(0))->will($this->returnValue($curl_response_failed));
		$mock_curl->expects($this->at(1))->will($this->returnValue($curl_response_ok));

		$mock_error = new PHPUnit_Extensions_MockFunction('curl_error', $handler);
		$mock_error->expects($this->any())->will($this->returnValue(false));

		$mock_sleep = new PHPUnit_Extensions_MockFunction('sleep', $handler);
		$mock_sleep->expects($this->any())->will($this->returnValue(1));

		$result = $handler->get('labels', '');
	}

	/** checks if will not retry after delay
	 *  if postmen API returns non retryable error
	 */
	public function testNotRetryPostmen() {
		$handler = new Postmen('', '', array('retry' => true));

		$curl_response_failed = '{"meta":{"code":500,"message":"error","retryable":false,"details":[]},"data":{}}';

		$mock_curl = new PHPUnit_Extensions_MockFunction('curl_exec', $handler);
		$mock_curl->expects($this->at(0))->will($this->returnValue($curl_response_failed));

		$mock_error = new PHPUnit_Extensions_MockFunction('curl_error', $handler);
		$mock_error->expects($this->any())->will($this->returnValue(false));

		$mock_sleep = new PHPUnit_Extensions_MockFunction('sleep', $handler);
		$mock_sleep->expects($this->any())->will($this->returnValue(1));

		$exceptionClass = get_class(new PostmenException('', 200, '', ''));
		$this->setExpectedException($exceptionClass);

		$result = $handler->get('labels', '');
	}

	/** checks proxy functions (that wrap around
	 *  context-less functions)
	 */
	public function testWrappers() {
		$handler = new FakePostmen('', 'region');

		// test context methods
		$ret = $handler->get('resource');
		$this->assertEquals($ret['method'], 'GET');
		$this->assertEquals($ret['path'], '/v3/resource');
		$this->assertEquals(count($ret['parameters']), 0);

		$ret = $handler->get('resource', '1234567890');
		$this->assertEquals($ret['method'], 'GET');
		$this->assertEquals($ret['path'], '/v3/resource/1234567890');
		$this->assertEquals(count($ret['parameters']), 0);

		$payload = array(
			'something' => 'value'
		);
		$ret = $handler->create('resource', $payload);
		$this->assertEquals($ret['method'], 'POST');
		$this->assertEquals($ret['path'], '/v3/resource');
		$this->assertEquals(count($ret['parameters']), 1);
		$this->assertEquals(isset($ret['parameters']['body']), true);
		$this->assertEquals(isset($ret['parameters']['body']['something']), true);
		$this->assertEquals($ret['parameters']['body']['something'], 'value');
		$this->assertEquals(isset($ret['parameters']['body']['async']), true);
		$this->assertEquals($ret['parameters']['body']['async'], false);

		$ret = $handler->cancel('1234567890');
		$this->assertEquals($ret['method'], 'POST');
		$this->assertEquals($ret['path'], '/v3/cancel-labels');
		$this->assertEquals(count($ret['parameters']), 1);
		$this->assertEquals(isset($ret['parameters']['body']), true);
		$this->assertEquals(isset($ret['parameters']['body']['label']['id']), true);
		$this->assertEquals($ret['parameters']['body']['label']['id'], '1234567890');
		$this->assertEquals(isset($ret['parameters']['body']['async']), true);
		$this->assertEquals($ret['parameters']['body']['async'], false);

		// test context-less methods

		$parameters = array(
			'something' => 'value'
		);
		$body = 'THIS IS REQUEST BODY';

		$ret = $handler->callGET('/v3/resource');
		$this->assertEquals($ret['method'], 'GET');
		$this->assertEquals($ret['path'], '/v3/resource');
		$this->assertEquals(count($ret['parameters']), 0);

		$ret = $handler->callPOST('/v3/resource', $body);
		$this->assertEquals($ret['method'], 'POST');
		$this->assertEquals($ret['path'], '/v3/resource');
		$this->assertEquals(count($ret['parameters']), 1);
		$this->assertEquals(isset($ret['parameters']['body']), true);
		$this->assertEquals($ret['parameters']['body'], $body);

		$ret = $handler->callPUT('/v3/resource', $body);
		$this->assertEquals($ret['method'], 'PUT');
		$this->assertEquals($ret['path'], '/v3/resource');
		$this->assertEquals(count($ret['parameters']), 1);
		$this->assertEquals(isset($ret['parameters']['body']), true);
		$this->assertEquals($ret['parameters']['body'], $body);

		$ret = $handler->callDELETE('/v3/resource', $body);
		$this->assertEquals($ret['method'], 'DELETE');
		$this->assertEquals($ret['path'], '/v3/resource');
		$this->assertEquals(count($ret['parameters']), 1);
		$this->assertEquals(isset($ret['parameters']['body']), true);
		$this->assertEquals($ret['parameters']['body'], $body);
	}


	/**
	 *  test if request method is correct, body fields according
	 *  to request method and URL field
	 */ 
	public function testCurlParamsMethod() {
		$handler = new Postmen('', 'region');
		$body = 'THIS IS THE BODY';
		$path = '/path';
		$parameters = array(
			'body' => $body
		);

		$method = 'GET';
		$get = $handler->buildCurlParams($method, $path, $parameters);
		try {
			$this->assertEquals(isset($get[CURLOPT_POSTFIELDS]), false);
		} catch(Exception $e) {
			$this->fail('GET request method, CURLOPT_POSTFIELDS must be not set');
		}

		$method = 'POST';
		$post = $handler->buildCurlParams($method, $path, $parameters);
		try {
			$this->assertEquals($post[CURLOPT_POSTFIELDS], $body);
		} catch(Exception $e) {
			$this->fail('POST request method, CURLOPT_POSTFIELDS must contain request body');
		}

		$method = 'PUT';
		$put = $handler->buildCurlParams($method, $path, $parameters);
		try {
			$this->assertEquals($put[CURLOPT_POSTFIELDS], $body);
		} catch(Exception $e) {
			$this->fail('PUT request method, CURLOPT_POSTFIELDS must contain request body');
		}

		$method = 'DELETE';
		$del = $handler->buildCurlParams($method, $path, $parameters);
		try {
			$this->assertEquals($del[CURLOPT_POSTFIELDS], $body);
		} catch(Exception $e) {
			$this->fail('DELETE request method, CURLOPT_POSTFIELDS must contain request body');
		}
		try {
			$this->assertEquals($del[CURLOPT_URL], 'https://region-api.postmen.com/path');
		} catch(Exception $e) {
			$this->fail('CURLOPT_URL is not correct');
		}

	}

	/**
	 *  test proxy parameters
	 */ 
	public function testCurlParamsProxy() {
		$handler = new Postmen('', '');
		$method = 'GET';
		$path = '/path';
		$proxy_host = 'proxyserver.com';
		$proxy_user = 'person';
		$proxy_pass = 'topsecret';
		$proxy_port = 9999;
		$parameters = array(
			'proxy' => array(
				'host' => $proxy_host,
				'port' => $proxy_port,
				'username' => $proxy_user,
				'password' => $proxy_pass
			)
		);
		$params = $handler->buildCurlParams($method, $path, $parameters);
		try {
			$this->assertEquals($params[CURLOPT_PROXY], $proxy_host);
		} catch(Exception $e) {
			$this->fail('CURLOPT_PROXY must contain proxy server hostname');
		}
		try {
			$this->assertEquals($params[CURLOPT_PROXYUSERPWD], "$proxy_user:$proxy_pass");
		} catch(Exception $e) {
			$this->fail('CURLOPT_PROXYUSERPWD must contain authentication credentials in form user:password');
		}
		try {
			$this->assertEquals($params[CURLOPT_PROXYPORT], $proxy_port);
		} catch(Exception $e) {
			$this->fail('CURLOPT_PROXYPORT must contain the port number');
		}
		try {
			$this->assertEquals($params[CURLOPT_HEADER], false);
		} catch(Exception $e) {
			$this->fail('CURLOPT_HEADER must be set to false as it will cause JSON serialization issues');
		}
		try {
			$this->assertEquals($params[CURLOPT_FOLLOWLOCATION], true);
		} catch(Exception $e) {
			$this->fail('CURLOPT_FOLLOWLOCATION must be set to true as it is required for proxy to work correctly');
		}
	}

	/**
	 *  test if headers are correct
	 */ 
	public function testCurlParamsHeaders() {
		$handler = new Postmen('1234567890', 'region');
		$method = 'GET';
		$path = '/path';
		$parameters = array();
		$params = $handler->buildCurlParams($method, $path, $parameters);
		try {
			$this->assertEquals($params[CURLOPT_HTTPHEADER][0], 'content-type: application/json');
		} catch(Exception $e) {
			$this->fail('CURLOPT_HTTPHEADER has incorrect content-type field');
		}
		try {
			$this->assertEquals($params[CURLOPT_HTTPHEADER][1], 'postmen-api-key: 1234567890');
		} catch(Exception $e) {
			$this->fail('CURLOPT_HTTPHEADER has incorrect API key field');
		}
		try {
			$this->assertRegExp('/x-postmen-agent\:\sphp-sdk-[0-9]*.[0-9]*.[0-9]*/', $params[CURLOPT_HTTPHEADER][2]);
		} catch(Exception $e) {
			$this->fail('CURLOPT_HTTPHEADER has incorrect x-postmen-agent field');
		}
	}

	/**
	 *  test if headers are correct
	 */ 
	public function testCurlGetQuery() {
		$handler = new Postmen('1234567890', 'region');
		$method = 'GET';
		$path = '/path';
		$query = array(
			'a' => 'alpha',
			'b' => 'beta'
		);
		$parameters = array(
			'query' => $query
		);
		$params = $handler->buildCurlParams($method, $path, $parameters);
		try {
			$this->assertEquals($params[CURLOPT_URL], 'https://region-api.postmen.com/path?a=alpha&b=beta');
		} catch(Exception $e) {
			$this->fail('CURLOPT_URL must contain a valid HTTP get query string in its URL');
		}
	}

	/** Checks if GET query is correctly generated from PHP array object
	 *  also verifies if it will be ignored for POST query
	 */
	public function testGetQuery() {
		$handler = new Postmen('', '');
		$query = array(
			'a' => 'alpha',
			'b' => 'beta'
		);
		$expected = 'http://example.com/path?a=alpha&b=beta';
		$path = '/path';
		$base = 'http://example.com';
		$this->assertEquals($handler->generateURL($base, $path, 'GET', $query), $expected);
		$expected = 'http://example.com/path';
		$this->assertEquals($handler->generateURL($base, $path, 'POST', $query), $expected);
	}
}
?>

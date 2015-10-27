<?php

/////////////////////
//    Utilities    //
/////////////////////

function answer($message,$code = '200'){
	switch ($code) {
		case 100: $text = 'Continue'; break;
		case 101: $text = 'Switching Protocols'; break;
		case 200: $text = 'OK'; break;
		case 201: $text = 'Created'; break;
		case 202: $text = 'Accepted'; break;
		case 203: $text = 'Non-Authoritative Information'; break;
		case 204: $text = 'No Content'; break;
		case 205: $text = 'Reset Content'; break;
		case 206: $text = 'Partial Content'; break;
		case 300: $text = 'Multiple Choices'; break;
		case 301: $text = 'Moved Permanently'; break;
		case 302: $text = 'Moved Temporarily'; break;
		case 303: $text = 'See Other'; break;
		case 304: $text = 'Not Modified'; break;
		case 305: $text = 'Use Proxy'; break;
		case 400: $text = 'Bad Request'; break;
		case 401: $text = 'Unauthorized'; break;
		case 402: $text = 'Payment Required'; break;
		case 403: $text = 'Forbidden'; break;
		case 404: $text = 'Not Found'; break;
		case 405: $text = 'Method Not Allowed'; break;
		case 406: $text = 'Not Acceptable'; break;
		case 407: $text = 'Proxy Authentication Required'; break;
		case 408: $text = 'Request Time-out'; break;
		case 409: $text = 'Conflict'; break;
		case 410: $text = 'Gone'; break;
		case 411: $text = 'Length Required'; break;
		case 412: $text = 'Precondition Failed'; break;
		case 413: $text = 'Request Entity Too Large'; break;
		case 414: $text = 'Request-URI Too Large'; break;
		case 415: $text = 'Unsupported Media Type'; break;
		case 422: $text = 'Unprocessable Entity'; break;
		case 500: $text = 'Internal Server Error'; break;
		case 501: $text = 'Not Implemented'; break;
		case 502: $text = 'Bad Gateway'; break;
		case 503: $text = 'Service Unavailable'; break;
		case 504: $text = 'Gateway Time-out'; break;
		case 505: $text = 'HTTP Version not supported'; break;
		default:
			throw new Exception("Unknown HTTP status code '$code'");
		break;
	}
	$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
	header($protocol . ' ' . $code . ' ' . $text);

	$message = is_array($message) ? $message : array($message);
	header('Content-Type: application/json');
	echo json_encode($message);
	die;
}

function throw_error() {
	throw new Exception("[".basename(error_get_last()["file"])." #".error_get_last()["line"]."] ".error_get_last()["message"]);
}

function execute($command){
	exec("bash -c '".$command." 2>&1'", $output, $retcode); // Force Bash use
	if ($retcode != 0)
		throw new Exception("Error with command: $command".PHP_EOL."....".implode(PHP_EOL."....",$output));
}

function delete_dir($dir,$fullDeletion=false) {
	if (is_dir($dir)){
		// Delete dir content first
		execute("(shopt -s dotglob && rm -rf $dir/*)"); // Far simpler than recursive functions
		if ($fullDeletion && (@rmdir($dir) === false))
			throw_error();
	}
}

function parse_json($file) {
	static $errors = array( // http://php.net/manual/en/function.json-last-error-msg.php#113243
		JSON_ERROR_NONE             => null,
		JSON_ERROR_DEPTH            => 'Maximum stack depth exceeded',
		JSON_ERROR_STATE_MISMATCH   => 'Underflow or the modes mismatch',
		JSON_ERROR_CTRL_CHAR        => 'Unexpected control character found',
		JSON_ERROR_SYNTAX           => 'Syntax error, malformed JSON',
		JSON_ERROR_UTF8             => 'Malformed UTF-8 characters, possibly incorrectly encoded'
	);

	($json = @file_get_contents($file)) || throw_error();
	$data = json_decode($json);
	$error = json_last_error();
	if ($error != JSON_ERROR_NONE){
		$message = array_key_exists($error, $errors) ? $errors[$error] : "Unknown error ({$error})";
		throw new Exception("$file: $message");
	}
	return $data;
}

function replace_text($files,$replace) {
	$files = is_array($files) ? $files : array($files);
	foreach ($files as $file) {
		($content = @file_get_contents($file)) or throw_error();
		foreach($replace as $searchFor => $newValue)
			$content = str_replace($searchFor,$newValue,$content);
		@file_put_contents($file,$content) or throw_error();
	}
}

/////////////////////
//   Web app API   //
/////////////////////

class API {
	protected $header = "";
	public function log($message) {
		$prefix = date("Y/m/d H:i:s")." [".$this->header."] ";
		file_put_contents("/apps/toolbox/log", $prefix.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
	}
	public function output_error($message) {
		$this->log($message);
		answer($message,"500");
	}
	public function api_error() {
		$this->output_error("Unknown API: ".$_SERVER['REQUEST_METHOD']." ".$_SERVER['PATH_INFO'].$_SERVER['QUERY_STRING']);
	}
}

class API_log {
	public function get($path,$params,$data) {
		answer(array_reverse(file('/apps/toolbox/log')));
	}
}

// URL path & params retrieval
$path = array_filter(explode("/", substr(@$_SERVER['PATH_INFO'], 1))); // array_filter to remove empty elements
$params = array(); parse_str(@$_SERVER['QUERY_STRING'],$params);

// Resource retrieval
if (!isset($path[0]))
	answer("Unknown API",400);
switch ($path[0]) {
	default:
		$resource = 'API_'.$path[0]; break;
}
if (!class_exists($resource))
	answer("Unknown resource name",400);
$handler = new $resource($path,$params);

// HTTP method retrieval
$method = strtolower($_SERVER['REQUEST_METHOD']);
if (!method_exists($handler,$method))
	answer("Unsupported action",405);

// Payload retrieval and decoding
$data = file_get_contents('php://input');
if (($method === "post" || $method === "put") &&
	isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'],'application/json') !== false &&
	!$data = json_decode($data)) {
		answer("Unsupported request body",400);
}

// Launch relevant API method
try {
	$handler->$method($path,$params,$data);
} catch (Exception $e) {
	$handler->output_error($e->getMessage());
}

?>

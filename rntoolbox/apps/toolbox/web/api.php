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

class API_apps extends API {
	public function __construct() {
		$this->header = "apps";
	}
	public function post($path,$params,$data) { // POST /apps
		global $factory;
		execute("rn_nml -I ".$factory.$data);
	}
}

class API_packages extends API {
	protected $file;
	public function __construct($path,$params) {
		global $factory;
		$this->header = "packages";

		if(isset($path[2]) || (isset($path[1]) && $path[1] != "setup"))
			$this->api_error();
		if(isset($params["file"])) {
			if(!is_file($factory.$params["file"]))
				$this->output_error("Unknown file: ".$params["file"]);
			$this->file = $factory.$params["file"];
		}
	}

	public function get($path,$params,$data) {
		global $factory;

		$dir = new RecursiveDirectoryIterator($factory);
		$pattern = "*.deb"; // GET /packages
		if(isset($path[1])) { // GET /packages/setup
			$pattern = "*package.json";
		}
		$files = new RegexIterator(new RecursiveIteratorIterator($dir),"/.$pattern/", RegexIterator::GET_MATCH);
		$packages = array();
		foreach($files as $file) {
			$package = new stdClass;
			$package->path = str_replace($factory,"",$file[0]);
			if(isset($path[1])) {
				try {
					$package_setup = parse_json($file[0]);
					if(isset($package_setup->config->custom_script)) {
						$relatedFile = rtrim(dirname($package->path),'/').'/'.$package_setup->config->custom_script;
						if(!is_file($factory.$relatedFile)) // This setup file is not valid
							throw new Exception("File ".$relatedFile." does not exist");
						$package->relatedFile = $relatedFile;
					}
					$package->valid = true;
					$package->appname = $package_setup->rn_name;
					$package->version = $package_setup->version;
					$package->description = $package_setup->description;

				} catch (Exception $e) {
					$package->valid = false;
					$package->error = $e->getMessage();
				}
			}
			$packages[] = $package;
		}
		answer($packages);
	}

	public function post($path,$params,$data) {
		global $factory;
		if(isset($path[1]))
			$this->api_error();
		if(!isset($params["method"]) || $params["method"] != "serverSetupFile")
			throw new Exception("Unset/unknown 'method' parameter");

		// Retrieve package creation data
		if(!is_file($factory.$data))
			$this->output_error("Unknown file: ".$data);
		$file = $factory.$data;
		$package_setup = parse_json($file);

		// Setting some dirs
		$factoryDir = "/apps/toolbox/factory/".$package_setup->rn_name;
		$factoryDebDir = "$factoryDir/DEBIAN";
		$factoryAppsDir = "$factoryDir/apps/".$package_setup->rn_name;
		$packageDir = dirname($file);

		// Copy skeleton files
		execute("cp -R /apps/toolbox/factory/skeleton $factoryDir");

		// --------------------------------
		// Skeleton update for the web-app
		// --------------------------------
		@rename($factoryDir."/apps/skeleton",$factoryAppsDir) or throw_error();

		// Update skeleton values in files
		$searchFor = array("rn_name","cap_name","deb_name","version","description");
		foreach($searchFor as $suffix)
			$replace["skeleton_".$suffix] = $package_setup->$suffix;
		replace_text(array("$factoryDebDir/control","$factoryAppsDir/config.xml","$factoryAppsDir/https.conf"),$replace);

		// Update DEBIAN control file
		$depends[] = "rntoolbox (>= ".$package_setup->debian->toolbox_version.")";
		foreach ($package_setup->dependencies as $dep) {
			if (isset($dep->min_version))
				$suffix = " (>= ".$dep->min_version.")";
			$depends[] = $dep->package.$suffix;
		}
		replace_text("$factoryDebDir/control", array("skeleton_depends" => implode(", ",$depends)));

		// Insert config data in DEBIAN maintainer scripts
		$parameters = "APPNAME=".$package_setup->rn_name.PHP_EOL;
		foreach ($package_setup->debian as $var => $value)
			$parameters .= "$var=$value".PHP_EOL;
		replace_text(array("$factoryDebDir/postinst","$factoryDebDir/postrm","$factoryDebDir/prerm"),array("##PARAMETERS##" => $parameters));

		// Modify https.conf if necessary
		if (isset($package_setup->apache_group)) {
			if ($package_setup->apache_group == "admin") {
				$replace["##AdminAuth##"] = "";
			} else {
				$replace["skeleton_group"] = $package_setup->apache_group;
				$replace["##GroupAuth##"] = "";
			}
			replace_text("$factoryAppsDir/https.conf", $replace);
		}

		// Build setup.json file
		@mkdir("$factoryAppsDir/initial-setup",0755) or throw_error();
		@file_put_contents("$factoryAppsDir/initial-setup/setup.json",json_encode($package_setup->setup,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) or throw_error();
		// Copy post-install script
		if (isset($package_setup->setup->custom_script)) {
			$sourceFile = $packageDir."/".$package_setup->setup->custom_script;
			if(!is_file($sourceFile)) { throw new Exception("File ".$package_setup->setup->custom_script." not found"); }
			@copy($sourceFile,$factoryAppsDir."/initial-setup/".$package_setup->setup->custom_script) or throw_error();
		}

		// Include package if necessary
		if ($package_setup->setup->deployment == "included") {
			$archiveName = $package_setup->setup->url;
			@copy($packageDir."/$archiveName",$factoryAppsDir."/$archiveName") or throw_error();
		}

		// logo.png update
		if (is_file($packageDir."/logo.png"))
			@copy($packageDir."/logo.png",$factoryAppsDir."/logo.png") or throw_error();

		// Generate deb file
		$debFile = $packageDir."/".$package_setup->deb_name."_".$package_setup->version."_all.deb";
		execute("fakeroot dpkg -b $factoryDir $debFile");
		// Clean it up
		delete_dir($factoryDir,true);
		// Return deb file path
		answer(str_replace($factory,"",$debFile));
	}
}

class API_log {
	public function get($path,$params,$data) {
		answer(array_reverse(file('/apps/toolbox/log')));
	}
}

$shares = new SimpleXMLElement(shell_exec("rn_nml -g shares"));
$admin_share = "/".$shares->xpath('//Share[@share-name="admin"]/@id')[0];
$factory = "$admin_share/factory";

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

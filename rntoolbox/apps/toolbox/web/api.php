<?php

/////////////////////
//    Utilities    //
/////////////////////

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

///////////////////////
//   REST handlers   //
///////////////////////

class APIerror extends Exception {}

abstract class RESThandler {
	// Input data
	protected $fullPath = "";
	protected $path = array();
	protected $params = array();
	protected $method = "";
	protected $data = "";
	// Referenced objects
	private $api; // API object

	protected function get_long_code($success,$code) {
		$long_code = new stdClass;
		$success_codes = array(
			200 => 'OK',
			201 => 'Created',
			204 => 'No Content',
			304 => 'Not Modified'
		);
		$error_codes = array (
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			406 => 'Not Acceptable',
			409 => 'Conflict',
			422 => 'Unprocessable Entity',
			500 => 'Internal Server Error'
		);

		// Set code
		$long_code->code = $code;
		if ($success && !array_key_exists($code, $success_codes))
			$long_code->code = 200;
		if (!$success && !array_key_exists($code, $error_codes))
			$long_code->code = 400;

		// Set text
		$long_code->text = $success ? $success_codes[$long_code->code] : $error_codes[$long_code->code];
		return $long_code;
	}

	// Abstract functions
	abstract protected function answer($message,$success,$code);

	// Common functions
	protected function output_error($message,$code=400) {
		if (isset($this->api))
			$this->api->log($message);
		else
			API_log::toFile("API",$message);
		$this->answer($message,false,$code);
	}
	public function run() {
		try {
			// Parse path
			// array_filter to remove empty elements
			$this->path = array_filter(explode("/", substr($this->fullPath,1)));
			// Resource retrieval
			if (!isset($this->path[0]))
				throw new APIerror();
			$resource = 'API_'.$this->path[0];

			if ($this->path[0] == "packages"
				&& isset($this->path[1]) && $this->path[1] == "setup") {
				$resource = 'API_setups';

				if (isset($this->path[2])) {
					$resource = 'API_setup_files';
				}
			}

			if ($this->path[0] == "apps"
				&& isset($this->path[2]) && $this->path[2] == "files") {
				$resource = 'API_files';
			}

			// Check resource and method
			if (!class_exists($resource))
				throw new Exception("Unknown resource name",400);
			$this->api = new $resource($this->path,$this->params);
			if (!method_exists($resource,$this->method))
				throw new Exception("Unsupported action",405);

			// Launch relevant API method
			$method = $this->method;
			$output = $this->api->$method($this->path,$this->params,$this->data);
			if (!is_null($output))
				$this->answer($output,true,200);
		} catch (APIerror $e) {
			$message = "Unknown API: ".strtoupper($this->method)." ".$this->fullPath;
			if (count($this->params)>0)
				$message .= "?".http_build_query($this->params);
			$this->output_error($message,'400');
		} catch (Exception $e) {
			$this->output_error($e->getMessage(),$e->getCode());
		}
	}
}

class HTTPhandler extends RESThandler {
	protected function answer($message,$success,$code) {
		$long_code = $this->get_long_code($success,$code);
		$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
		header($protocol . ' ' . $long_code->code . ' ' . $long_code->text);

		$message = is_array($message) ? $message : array($message);
		header('Content-Type: application/json');
		echo json_encode($message);
		die;
	}
	public function __construct() {
		// Full path
		$this->fullPath = @$_SERVER['PATH_INFO'];
		// Params
		parse_str(@$_SERVER['QUERY_STRING'],$this->params);
		// Method
		$this->method = strtolower($_SERVER['REQUEST_METHOD']);
		// Payload
		$this->data = file_get_contents('php://input');
		if (($this->method === "post" || $this->method === "put") &&
			isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'],'application/json') !== false &&
			!$this->data = json_decode($this->data)) {
				$this->output_error("Unsupported request body",400);
		}
	}
}

class CLIhandler extends RESThandler {
	protected function answer($message,$success,$code) {
		if ($success) {
			echo json_encode($message, JSON_UNESCAPED_SLASHES).PHP_EOL;
			exit(0);
		} else {
			$long_code = $this->get_long_code($success,$code);
			fwrite(STDERR, "[$long_code->code: $long_code->text] ".$message.PHP_EOL);
			exit(1);
		}
	}
	public function __construct() {
		global $argv;
		$opts = getopt("crud",array("path:","file:","method:","data:","v:"));
		if (!is_array($opts))
			$this->output_error("Invalid command line arguments: ".implode(" ",array_slice($argv,1)),406);
		$action = array();
		foreach (array_keys($opts) as $opt) switch ($opt) {
			case 'c': $action[] = "post"; break;
			case 'r': $action[] = "get"; break;
			case 'u': $action[] = "put"; break;
			case 'd': $action[] = "delete"; break;
			case 'path':
				$this->fullPath = $opts["path"]; break;
			case 'file':
			case 'method':
				$this->params[$opt] = $opts[$opt]; break;
			case 'data':
				$this->data = $opts["data"]; break;
		}
		if (count($action) == 1)
			$this->method = $action[0];
		else
			$this->output_error("Invalid number of api verbs",405);
	}
}

/////////////////////
//   Web app API   //
/////////////////////

class API {
	protected $header;
	public function log($message) {
		API_log::toFile($this->header,$message);
	}
}

class API_apps extends API {
	public $header = "apps";
	public static function getList() {
		$apps = array();
		foreach (new DirectoryIterator("/apps") as $fileInfo) {
			if($fileInfo->isDir() && !$fileInfo->isDot() && is_file($fileInfo->getPathName()."/.webapp")) {
				$apps[] = $fileInfo->getFilename();
			}
		}
		return $apps;
	}
	public function get($path,$params,$data) { // GET /apps
		return $this::getList();
	}
	public function post($path,$params,$data) { // POST /apps
		global $factory;
		execute("rn_nml -I ".$factory.$data);
	}
}

class API_files extends API {
	public $header = "apps";
	protected $name;
	protected function dir($type) {
		global $admin_share;
		switch($type) {
			case "conf": return "$admin_share/config/".$this->name; // conf dir of the application
			case "setup": return "$admin_share/setup/".$this->name; // setup dir of the application
			case "web": return "/apps/".$this->name."/web"; // dir containing web files in home dir
			case "app": return "/apps/".$this->name; // home dir of the application
		}
	}

	public function __construct($path,$params) {
		// Check errors
		if (!in_array($path[1],API_apps::getList()))
			throw new Exception("Unknown application: ".$path[1],404);
		if (!isset($path[3]))
			throw new APIerror();

		$this->header = $path[1];
		$this->name = $path[1];
	}

	public function get($path,$params,$data) {
		if ($path[3] != "conf" && $path[3] != "setup")
			throw new APIerror();

		// GET /apps/APPNAME/files/{conf,setup}
		if (!isset($path[4]))
			return array_map('basename', glob($this->dir($path[3])."/*"));

		// GET /apps/APPNAME/files/{conf,setup}/FILENAME
		$filepath = $this->dir($path[3])."/".$path[4];
		if (!is_file($filepath))
			throw new Exception("File unknown");
		readfile($filepath); die;
	}

	public function put($path,$params,$data) {
		switch ($path[3]) {
			case "conf":  // PUT /apps/APPNAME/files/conf/FILENAME
			case "setup": // PUT /apps/APPNAME/files/setup/FILENAME
				if (!isset($path[4]))
					throw new APIerror();
				$filepath = $this->dir($path[3])."/".$path[4];
				if (!is_file($filepath))
					throw new Exception("File unknown");

				@file_put_contents($filepath,$data,LOCK_EX) or throw_error();
				break;
			case "all": // PUT /apps/APPNAME/files/all
				if (isset($path[4]))
					throw new APIerror();
				$this->log("Update all files");

				////////////////////////
				// Setup files update //
				////////////////////////
				if (!is_file($this->dir("setup")."/setup.json")) {
					execute("cp ".$this->dir("app")."/initial-setup/* ".$this->dir("setup")."/");
				}
				$custo = parse_json($this->dir("setup")."/setup.json");

				//////////////////////
				// Web files update //
				//////////////////////

				// Delete all files stored in web folder
				$web = $this->dir("web");
				delete_dir($web);

				// Static files download and deployment
				$ext = pathinfo($custo->url,PATHINFO_EXTENSION);
				$tmp = "$web/archive.$ext";
				if ($custo->deployment == "included")
					$prefix = $this->dir("app")."/";
				@copy($prefix.$custo->url, $tmp) or throw_error();

				switch ($ext) {
					case "zip":
						$zip = new ZipArchive;
						if ($zip->open($tmp) !== true) throw_error();
						$zip->extractTo($web) or throw_error();
						$zip->close();
						@unlink($tmp) or throw_error();

						$webFiles = array_values(array_diff(scandir($web), array('..', '.')));
						if ((count($webFiles) == 1) && is_dir($web."/$webFiles[0]")) {
							// There is a top level directory
							execute("(shopt -s dotglob && mv ".$web."/$webFiles[0]/* ".$web."/)");
							@rmdir($web."/$webFiles[0]") or throw_error();
						}
						break;
					default:
						throw new Exception("Unsupported archive format");
						break;
				}

				////////////////////////////
				// Config files migration //
				////////////////////////////

				if (count($custo->config_files) > 0) {

					foreach ($custo->config_files as &$config_file) {
						if(!is_file($web."/".$config_file->source_path))
							throw new Exception("Unknown configuration file: ".$config_file->source_path);
						$local_config_file = $this->dir("conf")."/".basename($config_file->destination_path);
						$edited = (is_file($local_config_file) && (md5_file($local_config_file) != $config_file->md5sum));
						// Store the new default config file md5
						$config_file->md5sum = md5_file($web."/".$config_file->source_path);
						// Move the config file, keeping the edited one if necessary
						@rename($web."/".$config_file->source_path,$local_config_file.($edited ? ".default" : "")) or throw_error();
						// Replace source file by a link to the local file
						@symlink($local_config_file,$web."/".$config_file->destination_path) or throw_error();
					}

					// Update custo file with md5 config files values
					@file_put_contents($this->dir("setup")."/setup.json", json_encode($custo,JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) or throw_error();
				}

				/////////////////////////////
				// Custom configure script //
				/////////////////////////////
				if (isset($custo->custom_script)) {
					switch (pathinfo($custo->custom_script, PATHINFO_EXTENSION)) {
						case "sh":
							shell_exec($this->dir("setup")."/".$custo->custom_script);
							break;
						case "php":
							include $this->dir("setup")."/".$custo->custom_script;
							break;
						default:
							throw new Exception("Script language not supported");
							break;
					}
				}
			break;
			default:
				throw new Exception("Unknown API: ".implode("/",$path));
		}
	}

	public function delete($path,$params,$data) {
		if($path[3] == "web") { // DELETE /apps/APPNAME/files/web
			$this->log("Remove requested");
			// Conf files and mysql database are kept
		} else if ($path[3] == "all") { // DELETE /apps/APPNAME/files/all
			$this->log("Purge requested");
			// Remove conf & setup files
			delete_dir($this->dir("conf"));
			delete_dir($this->dir("setup"));
		}
		delete_dir($this->dir("web")); // Delete all files stored in web folder
	}
}

class API_packages extends API {
	public $header = "packages";
	public function __construct($path,$params) {
		if(isset($path[1]))
			throw new APIerror();
	}

	public function get($path,$params,$data) {
		global $factory;
		$dir = new RecursiveDirectoryIterator($factory);
		$files = new RegexIterator(new RecursiveIteratorIterator($dir),"/.*.deb/", RegexIterator::GET_MATCH);
		$packages = array();
		foreach($files as $file) {
			$package = new stdClass;
			$package->path = str_replace($factory,"",$file[0]);
			$packages[] = $package;
		}
		return $packages;
	}

	public function post($path,$params,$data) {
		global $factory;

		if(!isset($params["method"]) || $params["method"] != "serverSetupFile")
			throw new Exception("Unset/unknown 'method' parameter");

		// Retrieve package creation data
		if(!is_file($factory.$data))
			throw new Exception("Unknown file: ".$data,404);
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
		return str_replace($factory,"",$debFile);
	}
}

class API_setups extends API {
	public $header = "packages_setup";

	public function get($path,$params,$data) { // GET /packages/setup
		global $factory;
		$dir = new RecursiveDirectoryIterator($factory);
		$files = new RegexIterator(new RecursiveIteratorIterator($dir),"/.*package.json/", RegexIterator::GET_MATCH);
		$packages = array();
		foreach($files as $file) {
			$package = new stdClass;
			$package->path = str_replace($factory,"",$file[0]);
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
			$packages[] = $package;
		}
		return $packages;
	}

	public function post($path,$params,$data) {
		global $factory;
		$setupDir = $factory."/".$data;
		@mkdir($setupDir,0755) or throw_error();
		@copy("/apps/toolbox/factory/package_sample.json",$setupDir."/package.json") or throw_error();
	}
}

class API_setup_files extends API {
	public $header = "setup_files";
	private $source;

	public function __construct($path,$params) {
		global $factory;

		// Check path
		$files = array("source");
		if (!in_array($path[2],$files) || isset($path[3]))
			throw new APIerror();

		// Check 'source' parameter
		if (isset($params["source"])) {
			if (!is_file($factory.$params["source"]))
				throw new Exception("Unknown file: ".$params["source"]);
			$this->source = $factory.$params["source"];
		} else {
			throw new Exception("'source' parameter is mandatory");
		}

	}

	public function get($path,$params,$data) {
		readfile($this->source);
	}

	public function put($path,$params,$data) {
		@file_put_contents($this->source,$data,LOCK_EX) or throw_error();
	}
}

class API_log {
	private static $path = "/var/log/rntoolbox";
	public static function toFile($header,$message) {
		$prefix = date("Y/m/d H:i:s")." [".$header."] ";
		file_put_contents(self::$path, $prefix.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
	}
	public function get($path,$params,$data) {
		return array_reverse(file(self::$path));
	}
}

////////////////////
//      Main      //
////////////////////

$shares = new SimpleXMLElement(shell_exec("rn_nml -g shares"));
$admin_share = "/".$shares->xpath('//Share[@share-name="admin"]/@id')[0];
$factory = "$admin_share/factory";

// Launch HTTP or CLI handler
if (php_sapi_name() == "cli") {
	(new CLIhandler())->run();
} else {
	(new HTTPhandler())->run();
}

?>

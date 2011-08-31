<?php

define('EDIT_CLOSE', 'close');
define('EDIT_OPEN', 'open');
define('EDIT_FIND', 'find');
define('EDIT_INFIND', 'inline-find');

/**
 * MODX 1.2.5 compatible editor
 */
class Editor
{
	/**
	 * @var Edit root
	 */
	protected $root;

	/**
	 * @var Store edited files in cache until commit
	 */
	private $cache = array();

	/**
	 * @var List of files to delete
	 */
	private $delete = array();

	/**
	 * @var Current file
	 */
	private $file = null;

	/**
	 * @var Block start line number
	 */
	private $start_line;

	/**
	 * @var Block end line number
	 */
	private $end_line;

	/**
	 * @var Inline block start offset
	 */
	private $start_inline;

	/**
	 * @var Inline block end offset
	 */
	private $end_inline;

	/**
	 * @var Inline block cache
	 */
	private $inline_cache;

	/**
	 * @var Current editor status
	 */
	private $data = array(
		'mode' => EDIT_CLOSE,
	);

	/**
	 * @var Array of force overwiting test
	 */
	private $copyresolvers = array();

	/**
	 * Constructor
	 */
	function __construct($root)
	{
		$this->root = rtrim($root, '/') . '/';
	}

	/**
	 * Make all line endings the same - UNIX
	 */
	protected function normalize($string)
	{
		$string = str_replace(array("\r\n", "\r"), "\n", $string);
		return $string;
	}

	/**
	 * Set editor mode
	 */
	protected function mode($mode = null)
	{
		return ($mode === null) ? ($this->data['mode']) : ($this->data['mode'] = $mode);
	}

	/**
	 * Open file for editing
	 */
	public function file_open($file)
	{
		if ($this->mode() != EDIT_CLOSE) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_CLOSE . '"');
		}

		/*
		   * Load file to cache
		   */
		if (!isset($this->cache[$file])) {
			$this->cache[$file] = @file_get_contents($this->root . $file);
			if ($this->cache[$file] === false) {
				unset($this->cache[$file]);
				throw new FileNotFound('Can\'t open file "' . $file . '" for editing');
			}
			else if (!is_writable($this->root . $file)) {
				unset($this->cache[$file]);
				throw new FileNotFound("File $file is not writable");
			}
			$this->cache[$file] = explode("\n", $this->normalize($this->cache[$file]));
		}

		$this->file = $file;
		$this->mode(EDIT_OPEN);
		$this->start_line = $this->end_line = 0;
	}

	/**
	 * Close currrent file
	 */
	public function file_close()
	{
		if ($this->mode() != EDIT_OPEN) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_OPEN . '"');
		}
		$this->file = null;
		$this->mode(EDIT_CLOSE);
	}

	/**
	 * Write changes
	 */
	public function commit_changes()
	{
		if ($this->mode() != EDIT_CLOSE) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_CLOSE . '"');
		}

		$errors = array();

		// Save edits from cache
		foreach ($this->cache as $file => $contents)
		{
			// Make directory
			if (!is_dir(dirname($this->root . $file))) {
				if (!$this->mkdir(dirname($this->root . $file))) {
					$errors[] = $file;
					continue;
				}
			}

			// Write file
			if (file_put_contents($this->root . $file, implode("\n", $contents)) === false) {
				$errors[] = $file;
			}
			else
			{
				// Remove commited files from cache
				unset($this->cache[$file]);
			}
		}

		// Perform deletions
		foreach ($this->delete as $file)
		{
			if (!unlink($this->root . $file)) {
				$errors[] = $file;
			}
		}

		if (sizeof($errors) > 0) {
			throw new CommitFailed('Can\'t save changes in files ' . implode(', ', $errors));
		}
	}

	/**
	 * Start edit block
	 */
	public function edit_open()
	{
		$this->mode(EDIT_OPEN);
	}

	/**
	 * Finish edit block
	 */
	public function edit_close()
	{
		$this->mode(EDIT_OPEN);
	}

	/**
	 * Find action
	 */
	public function find($pattern)
	{
		if ($this->mode() != EDIT_OPEN && $this->mode() != EDIT_FIND) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_OPEN . '" or "' . EDIT_FIND . '"');
		}

		// Short name for file cache
		$file = &$this->cache[$this->file];

		// Prepare pattern
		$orig_pattern = $pattern;
		/*
		 * Not shure is trim() complains with MODX standart. Should be 
		 * tested on real mods from MODDB...
		 */
		// $pattern = trim($pattern);
		$pattern = explode("\n", $this->normalize($pattern));
		foreach ($pattern as &$line)
		{
			$line = preg_quote(trim($line), '#');
		}

		// Seek through file
		for ($i = $this->end_line; $i < sizeof($file); $i++)
		{
			if ($this->matchFind($pattern, $file, $i)) {
				// Set find block borders
				$this->start_line = $i;
				$this->end_line = $this->start_line + sizeof($pattern);

				// Reset inline block borders
				$this->start_inline = $this->end_inline = 0;

				// Change mode
				$this->mode(EDIT_FIND);
				return;
			}
		}

		throw new FindNotFound("Can't find \"$orig_pattern\" in file {$this->file} strating from line {$this->end_line}");
	}

	/**
	 * ADD AFTER action implementation
	 */
	public function add_after($code)
	{
		if ($this->mode() != EDIT_FIND) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_FIND . '"');
		}

		// Short name for file cache
		$file = &$this->cache[$this->file];

		// Prepare inserted code
		$code = explode("\n", $this->normalize($code));

		array_splice($file, $this->end_line, 0, $code);
	}

	/**
	 * ADD BEFORE action implementation
	 */
	public function add_before($code)
	{
		if ($this->mode() != EDIT_FIND) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_FIND . '"');
		}

		// Short name for file cache
		$file = &$this->cache[$this->file];

		// Prepare inserted code
		$code = explode("\n", $this->normalize($code));

		array_splice($file, $this->start_line, 0, $code);
		$this->start_line += sizeof($code);
		$this->end_line += sizeof($code);
	}

	/**
	 * REPLACE WITH action implementation
	 */
	public function replace_with($code)
	{
		if ($this->mode() != EDIT_FIND) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_FIND . '"');
		}

		// Short name for file cache
		$file = &$this->cache[$this->file];

		// Prepare inserted code
		$code = explode("\n", $this->normalize($code));

		$old_len = $this->end_line - $this->start_line;
		array_splice($file, $this->start_line, $old_len, $code);
		$this->end_line += sizeof($code) - $old_len;
	}

	/**
	 * INCREMENT action stub
	 * @todo implement real INCREMENT support (see AutoMod editor)
	 */
	public function increment($code)
	{
		throw new UnsupportedAction("INCREMENT action isn't supported by editor.");
	}

	/**
	 * REMOVE action implementation
	 */
	public function remove($code)
	{
		$this->find($code);
		$this->replace_with('');
	}

	/**
	 * Handle inline edit start
	 */
	public function inline_open()
	{
		// Short name for file cache
		$file = &$this->cache[$this->file];

		// Fetch found block to inline cache
		$this->inline_cache = implode("\n", array_slice($file, $this->start_line, $this->end_line - $this->start_line));
	}

	/**
	 * Handle inline edit end
	 */
	public function inline_close()
	{
		$this->mode(EDIT_FIND);
		// Put inline edit cache back to file
		$this->replace_with($this->inline_cache);
	}

	/**
	 * IN-LINE FIND action implementation
	 */
	public function inline_find($pattern)
	{
		if ($this->mode() != EDIT_FIND) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_FIND . '"');
		}

		// Short name for inline cache
		$cache = &$this->inline_cache;

		// Prepare pattern
		// TODO: Add support for inline increment
		$pattern = $this->normalize($pattern);

		$pos = strpos($cache, $pattern, $this->start_inline);
		if ($pos === false) {
			throw new FindNotFound("Inline find failed: can't find \"$pattern\" in \"$cache\"");
		}

		$this->start_inline = $pos;
		$this->end_inline = $pos + strlen($pattern);

		$this->mode(EDIT_INFIND);
	}

	/**
	 * IN-LINE ADD AFTER action implementation
	 */
	public function inline_add_after($code)
	{
		if ($this->mode() != EDIT_INFIND) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_INFIND . '"');
		}

		// Short name for inline cache
		$cache = &$this->inline_cache;

		$code = $this->normalize($code);

		$cache = substr_replace($cache, $code, $this->end_inline, 0);
	}

	/**
	 * IN-LINE ADD BEFORE action implementation
	 */
	public function inline_add_before($code)
	{
		if ($this->mode() != EDIT_INFIND) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_INFIND . '"');
		}

		// Short name for inline cache
		$cache = &$this->inline_cache;

		$code = $this->normalize($code);

		$cache = substr_replace($cache, $code, $this->start_inline, 0);

		$this->start_inline += strlen($code);
		$this->end_inline += strlen($code);
	}

	/**
	 * IN-LINE REPLACE action implementation
	 */
	public function inline_replace($code)
	{
		if ($this->mode() != EDIT_INFIND) {
			throw new InvalidMode('Unexpected editor mode "' . $this->mode() . '", expected "' . EDIT_INFIND . '"');
		}

		// Short name for inline cache
		$cache = &$this->inline_cache;

		$code = $this->normalize($code);

		$cache = substr_replace($cache, $code, $this->start_inline, $this->end_inline - $this->start_inline);

		$this->end_inline += strlen($code) - ($this->end_inline - $this->start_inline);
	}

	// IN-LINE REMOVE action implementation
	public function inline_remove($code)
	{
		$this->inline_find($code);
		$this->inline_replace('');
	}

	/**
	 * IN-LINE INCREMENT action stub
	 */
	public function inline_increment($code)
	{
		throw new UnsupportedAction("Inline increment is unsupported at this time");
	}

	/**
	 * COPY action implementation
	 * @param $from string full path to original file
	 * @param $to string path to target relative to phpBB root
	 */
	public function copy($from, $to)
	{
		if (substr($from, -3) == '*.*') {
			$from_dir = substr($from, 0, -3);
			$to_dir = substr($to, 0, -3);
			$from_dir = rtrim($from_dir, '/');
			$to_dir = rtrim($to_dir, '/');

			// Recursively add files from directory
			$list = $this->list_files($from_dir);

			foreach ($list as $file)
			{
				$this->copy($from_dir . $file, $to_dir . $file);
			}
		}
		else
		{
			$to = ltrim($to, '/');
			// Don't allow to overwrite file to prevent mode conflicts
			if ($this->is_creatable($this->root . $to)) {
				if (file_exists($this->root . $to)) {
					$resolution = $this->testForcedOverwrite($from, $this->root . $to);

					if ($resolution == EditorCopyResolver::DONTCARE) {
						throw new FileNotFound("Target file \"$to\" can't be overwrited");
					}
					else if ($resolution == EditorCopyResolver::KEEP) {
						return;
					}
				}
				// Load file to cache
				$this->cache[$to] = @file_get_contents($from);

				if ($this->cache[$to] === false) {
					unset($this->cache[$to]);
					throw new FileNotFound("Can't load file \"$from\"");
				}

				$extension = substr($to, strrpos($to, '.'));
				if($extension == '.php' || $extension == '.htm'
				   || $extension == '.html' || $extension == '.css'
				   || $extension == '.js' || $extension == '.cfg') {
					$this->cache[$to] = $this->normalize($this->cache[$to]);
					$this->cache[$to] = explode("\n", $this->cache[$to]);
				}
				else {
					$this->cache[$to] = array($this->cache[$to]); // We don't really need to explode binary files
				}
			}
			else
			{
				throw new FileNotFound("Target file \"$to\" can't be created or overwrited");
			}
		}
	}

	/**
	 * DELETE action implementation
	 */
	public function delete($file)
	{
		if (substr($file, -3, 3) == '*.*') {
			// Recursively delete files from directory
			$list = $this->list_files($this->root, substr($file, 0, -3));
			foreach ($list as $file)
			{
				$this->delete($file);
			}
		}
		else
		{
			if (!file_exists($this->root . $file)) // Skip non-existent files
			{
				return;
			}
			else if (!is_writable(dirname($this->root . $file))) {
				throw new PermissionDenied("Can't delete $file: it's parent dir is not writeable");
			}
			else if (is_dir($this->root . $file)) // Recursively delete dirs
			{
				$this->delete(trim($this->root . $file, '/') . '/*.*');
			}
			else // Queue deletion
			{
				$this->delete[] = $file;
			}
		}
	}

	/**
	 * SQL action stub
	 */
	public function sql($query, $dbms = 'sql-parser')
	{
		// Put query to relevant file
		$file = "install/mods/$dbms.sql";
		if (!isset($this->cache[$file])) {
			try
			{
				$this->file_open($file);
				$this->file_close();
			}
			catch(FileNotFound $e)
			{
				$this->cache[$file] = array();
			}
		}

		$this->cache[$file][] = $query;
	}

	/**
	 * Mod installer registration
	 */
	public function installer($file)
	{
		$installer_file = 'install/mods/installer.txt';
		if (!isset($this->cache[$installer_file])) {
			try
			{
				$this->file_open($installer_file);
				$this->file_close();
			}
			catch(FileNotFound $e)
			{
				$this->cache[$installer_file] = array();
			}
		}

		$this->cache[$installer_file][] = $file;


		// Let installer run when install/ folder exists
		$this->file_open($file);
		$this->edit_open();
		try
		{
			$this->find('define(\'IN_INSTALL\', true);');
		}
		catch(FindNotFound $e)
		{
			$this->find('<?');
			$this->add_after("define('IN_INSTALL', true);");
		}
		$this->edit_close();
		$this->file_close();
	}

	/**
	 * Recursively list all files in directory $from
	 * TODO: May be listing must not be recursive
	 *
	 * @param string $from Base dir to search in, must end with /
	 * @param string $path Internaly used recursion parameter
	 * @return array Array of paths files in dir relative to $from
	 */
	protected function list_files($from, $path = '')
	{
		$list = array();
		$scan = scandir($from . $path);
		foreach ($scan as $file)
		{
			if ($file == '.' || $file == '..') {
				continue;
			}
			else if (is_dir($from . $path . '/' . $file)) {
				$list = array_merge($list, $this->list_files($from, $path . '/' . $file));
			}
			else
			{
				$list[] = $path . '/' . $file;
			}
		}

		return $list;
	}

	/**
	 * Performs matching find's patterns to specified lines
	 * @todo Support for matching patterns of INCREMENT action
	 */
	private function matchFind($pattern, &$file, $start)
	{
		for ($i = 0; $i < sizeof($pattern); $i++)
		{
			if (!isset($file[$start + $i])) {
				return false;
			}

			if (!preg_match("#{$pattern[$i]}#", $file[$start + $i])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Recursive mkdir wrapper
	 *
	 * Note: as of using PHP5 OOP features I can use $recursive
	 * parameter of built-in mkdir() function.
	 */
	protected function mkdir($dir, $mode = 0775)
	{
		return mkdir($dir, $mode, true);
	}

	/**
	 * Test if we can create or overwrite specified file
	 */
	protected function is_creatable($file)
	{
		if (is_file($file)) {
			return is_writable($file);
		}
		else
		{
			do
			{
				$file = dirname($file);
			} while (!is_dir($file) && dirname($file) != $file);

			return is_writable($file);
		}
	}

	/**
	 * Dumps edit cache for debugging purposes
	 */
	public function dump()
	{
		var_dump($this->cache);
	}

	/**
	 * Add force overwriting test
	 */
	public function addCopyResolver($test)
	{
		$this->copyresolvers[] = $test;
	}

	/**
	 * Test if we should overwrite this file
	 */
	protected function testForcedOverwrite($from, $to)
	{
		foreach ($this->copyresolvers as $test)
		{
			$resolution = $test->test($from, $to);
			if ($resolution != EditorCopyResolver::DONTCARE) {
				return $resolution;
			}
		}

		return EditorCopyResolver::DONTCARE;
	}
}

interface EditorCopyResolver
{
	const DONTCARE = 0;
	const KEEP = 1;
	const OVERWRITE = 2;

	/**
	 * Test if existing file $to should be overwrited by file $from
	 */
	public function test($from, $to);
}

class EditorException extends Exception
{
}

class FileNotFound extends EditorException
{
}

class PermissionDenied extends EditorException
{
}

class FindNotFound extends EditorException
{
}

class InvalidMode extends EditorException
{
}

class CommitFailed extends EditorException
{
}

class UnsupportedAction extends EditorException
{
}

?>

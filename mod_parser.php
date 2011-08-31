<?php
/**
 * MODX Script parser
 *
 * Fully compatible with MODX 1.2.5
 */

define('MODXLIB', dirname(__FILE__).'/');
require_once(MODXLIB.'editor.php');
require_once(MODXLIB.'exceptions.php');

class ModParser
{
	protected $xml;

	protected $root;

	protected $actions = array(
		'copy' => array(),
		'delete' => array(),
		'edit' => array(),
		'sql' => array(),
		'installer' => '',
	);

	/**
	 * @var Array of force overwiting test
	 */
	private $copyresolvers = array();

	/**
	 * Constructor
	 */
	function __construct($install, $root = null)
	{
		if ($root === null) {
			$this->root = rtrim(dirname($install), '/') . '/';
		}
		else
		{
			$this->root = $root;
		}

		// Load XML
		$this->xml = @simplexml_load_file($install);
		if (!$this->xml) {
			throw new XmlError('Loading install script failed: ' . implode('; ', libxml_get_errors()));
		}

		$this->parse_actions($this->xml->{'action-group'});
	}

	/**
	 * Parse XML action tree
	 */
	private function parse_actions($xml)
	{
		if (isset($xml->copy)) {
			$this->parse_copy($xml->copy);
		}
		if (isset($xml->open)) {
			$this->parse_open($xml->open);
		}
		if (isset($xml->sql)) {
			$this->parse_sql($xml->sql);
		}
		if (isset($xml->delete)) {
			$this->parse_delete($xml->delete);
		}
		if (isset($xml->{'php-installer'})) {
			$this->parse_installer($xml->{'php-installer'});
		}
	}

	/**
	 * Parse XML delete tags
	 */
	private function parse_delete($xml)
	{
		foreach ($xml as $delete)
		{
			foreach ($delete->file as $file)
			{
				$this->actions['delete'][] = (string)$file['name'];
			}
		}
	}

	/**
	 * Parse php-installer XML tags
	 */
	private function parse_installer($xml)
	{
		$this->actions['installer'] = (string)$xml;
	}

	/**
	 * Parse XML sql tags
	 */
	private function parse_sql($sql)
	{
		foreach ($sql as $query)
		{
			$dbms = empty($query['dbms']) ? 'sql-parser' : (string)$query['dbms'];
			if (!isset($this->actions['sql'][$dbms])) {
				$this->actions['sql'][$dbms] = array();
			}

			$this->actions['sql'][$dbms][] = (string)$query;
		}
	}

	/**
	 * Parse XML open tags
	 */
	private function parse_open($xml)
	{
		foreach ($xml as $open)
		{
			foreach ($open->edit as $edit)
			{
				$this->actions['edit'][(string)$open['src']][] = $this->parse_edit($edit);
			}
		}
	}

	/**
	 * Parse XML edit tag
	 */
	private function parse_edit($edit)
	{
		$data = array('find' => array());

		// Process finds
		foreach ($edit->find as $find)
		{
			$data['find'][] = (string)$find;
		}

		// Process actions
		if (isset($edit->action)) {
			$data['action'] = array();
			foreach ($edit->action as $action)
			{
				$data['action'][] = array(
					'type' => (string)$action['type'],
					'code' => (string)$action,
				);
			}
		}

		// Process inline edits
		if (isset($edit->{'inline-edit'})) {
			$data['inline'] = array();
			foreach ($edit->{'inline-edit'} as $inline)
			{
				$data['inline'][] = $this->parse_inline($inline);
			}
		}

		// Process removes
		if (isset($edit->remove)) {
			$data['remove'] = (string)$edit->remove;
		}

		return $data;
	}

	/**
	 * Parse inline-edit XML tag
	 */
	private function parse_inline($inline)
	{
		// Parse inline-finds
		$data = array('find' => array());
		foreach ($inline->{'inline-find'} as $find)
		{
			$data['find'][] = (string)$find;
		}

		// Parse inline-actions
		if (isset($inline->{'inline-action'})) {
			$data['action'] = array();
			foreach ($inline->{'inline-action'} as $action)
			{
				$data['action'][] = array(
					'type' => (string)$action['type'],
					'code' => (string)$action,
				);
			}
		}

		// Parse inline-remove
		if (isset($inline->{'inline-remove'})) {
			$data['remove'] = (string)$inline->{'inline-remove'};
		}

		return $data;
	}

	/**
	 * Parse copy section of XML tree
	 */
	private function parse_copy($xml)
	{
		foreach ($xml->file as $entry)
		{
			$this->actions['copy'][] = array(
				'from' => $this->root . ((string)$entry['from']),
				'to' => (string)$entry['to'],
			);
		}
	}

	/**
	 * Extract language- and template-specific instructions
	 */
	public function link($langs, $templates)
	{
		$files = array();

		if (isset($this->xml->header->{'link-group'})) {
			foreach ($this->xml->header->{'link-group'}->link as $link)
			{
				if (in_array((string)$link['type'], array('template', 'language', 'template-lang'))) {
					if (in_array(basename($link['href'], '.xml'), array_merge($templates, $langs))) {
						$files[] = (string)$link['href'];
					}
				}
			}
		}

		$files = array_unique($files);

		$list = array();

		foreach ($files as $file)
		{
			$list[] = new ModParser($this->root . $file, $this->root);
		}

		return $list;
	}

	/**
	 * Install mod with edits for specified languages and templates
	 *
	 * @param string $phpbb phpBB root
	 * @param array $langs array of desired languages to install
	 * @param array $tamplates array of desired templates to install
	 */
	public function install($phpbb, $langs, $templates)
	{
		$this->install_one($phpbb);

		$links = $this->link($langs, $templates);

		foreach ($links as $link)
		{
			$link->install_one($phpbb);
		}
	}

	/**
	 * Install only this mod
	 *
	 * @param string $phpbb phpBB root
	 */
	public function install_one($phpbb)
	{
		// Don't let install mods to non-phpBB
		if (!$this->test_phpbb($phpbb)) {
			throw new NotPhpbb('phpBB not found');
		}

		$editor = new Editor($phpbb);
		foreach ($this->copyresolvers as $test)
		{
			$editor->addCopyResolver($test);
		}

		try
		{
			// Perform copy action
			foreach ($this->actions['copy'] as $file)
			{
				$editor->copy($file['from'], $file['to']);
			}

			// Perform delete action
			foreach ($this->actions['delete'] as $file)
			{
				$editor->delete($file);
			}

			// Perform edits
			foreach ($this->actions['edit'] as $file => $edits)
			{
				$editor->file_open($file);

				foreach ($edits as $edit)
				{
					$this->do_edit($editor, $edit);
				}

				$editor->file_close();
			}

			// Perform sql queries
			foreach ($this->actions['sql'] as $dbms => $queries)
			{
				foreach ($queries as $query)
				{
					$editor->sql($query, $dbms);
				}
			}

			// Perform sql queries
			if (!empty($this->actions['installer'])) {
				$editor->installer($this->actions['installer']);
			}
		}
		catch (EditorException $e)
		{
			throw new InstallFailed("Editor exception", 0, $e);
		}

		$editor->commit_changes();
	}

	/**
	 * Perform edit
	 */
	private function do_edit($editor, $edit)
	{
		$editor->edit_open();

		// Find
		foreach ($edit['find'] as $find)
		{
			$editor->find($find);
		}

		// Perform actions
		if (isset($edit['action'])) {
			foreach ($edit['action'] as $action)
			{
				switch ($action['type'])
				{
					case 'before-add':
						$editor->add_before($action['code']);
						break;
					case 'after-add':
						$editor->add_after($action['code']);
						break;
					case 'replace-with';
						$editor->replace_with($action['code']);
						break;
					default:
						throw new UnsupportedAction("Action {$action['type']} isn't supported");
						break;
				}
			}
		}

		// Perform inline actions
		if (isset($edit['inline'])) {
			foreach ($edit['inline'] as $edit)
			{
				$this->do_inline($editor, $edit);
			}
		}

		// Perform remove
		if (isset($edit['remove'])) {
			$editor->remove($edit['remove']);
		}

		$editor->edit_close();
	}

	/**
	 * Perform inline edit
	 */
	private function do_inline($editor, $edit)
	{
		$editor->inline_open();

		// Find
		foreach ($edit['find'] as $find)
		{
			$editor->inline_find($find);
		}

		// Perform actions
		if (isset($edit['action'])) {
			foreach ($edit['action'] as $action)
			{
				switch ($action['type'])
				{
					case 'before-add':
						$editor->inline_add_before($action['code']);
						break;
					case 'after-add':
						$editor->inline_add_after($action['code']);
						break;
					case 'replace-with';
						$editor->inline_replace($action['code']);
						break;
					default:
						throw new UnsupportedAction("Action {$action['type']} isn't supported");
						break;
				}
			}
		}

		// Perform remove
		if (isset($edit['remove'])) {
			$editor->inline_remove($edit['remove']);
		}

		$editor->inline_close();
	}

	/**
	 * Test if specified path contains phpBB
	 */
	protected function test_phpbb($phpbb)
	{
		if (!($data = @file_get_contents($phpbb . '/viewtopic.php'))) {
			return false;
		}
		else if (strpos($data, 'define(\'IN_PHPBB\', true);') === false) {
			return false;
		}

		return true;
	}

	/**
	 * Add force overwriting test
	 */
	public function addCopyResolver($test)
	{
		$this->copyresolvers[] = $test;
	}
}

/** General ModParser exception */
class ModParserException extends Exception53
{
	private $modName = '';

	public function setModName($modName)
	{
		$this->modName = $modName;
	}

	public function getModName()
	{
		return $this->modName;
	}
}

/** Invalid XML exception */
class XmlError extends ModParserException
{
}

/** Target path doesn't contain phpBB */
class NotPhpbb extends ModParserException
{
}

/** General install fail. Use ::getPrevious() to get actual exception */
class InstallFailed extends ModParserException
{
}

?>

<?php

namespace Paste;

// page model
class Page {

	// page successfully loaded
	public $loaded = FALSE;

	// page name and link id
	public $name;

	// path to page content
	public $path;
	
	// page title, used in menu
	public $title;

	// page content
	public $content;

	// mustache template name
	public $template;

	// redirect URL for creating aliases
	public $redirect;

	// parent section
	public $parent;

	// page is a section index
	public $is_parent = FALSE;

	// visible in menu
	public $visible = TRUE;

	// all parent sections
	public $parents = array();
	
	// template file extension
	public static $template_ext = '.stache';

	// template directory relative to app path
	public static $template_dir = 'templates';

	// content "database"
	public static $db;

	// content file extension
	public static $content_ext = '.html';
	
	// content directory relative to app path
	public static $content_dir = 'content';
	
	// current page data model
	public static $current_page;
	
	// decipher request and render content page
	public static function get($uri = NULL) {
		
		// trim slashes
		$uri = trim($uri, '/');
		
		// decipher content request
		$request = empty($uri) ? array('index') : explode('/', $uri);

		// current section is 2nd to last argument (ie. parent3/parent2/parent/page) or NULL if root section
		$parent = (count($request) < 2) ? NULL : $request[count($request) - 2];

		// current page is always last argument of request
		$page = end($request);
		
		// get requested page from content database
		self::$current_page = self::find(array('parent' => $parent, 'name' => $page));
		
		// no page found
		if (self::$current_page === FALSE OR ! self::$current_page->loaded) {

			// send 404 header
			header('HTTP/1.1 404 File Not Found');

			// draw 404 content if available
			self::$current_page = self::find(array('name' => '404'));
			
			// if no 404 content available, do somethin' sensible
			if (self::$current_page === FALSE) {

				// simple 404 page
				self::$current_page = new Page;
				self::$current_page->title = 'Error 404 - File Not Found';
				self::$current_page->content = '<h1>Error 404 - File Not Found</h1>';
				
			}

		// page redirect configured
		} elseif (! empty(self::$current_page->redirect)) {

			// redirect to url
			return Paste::redirect(self::$current_page->url());

		}
		
		// send text/html UTF-8 header
		header('Content-Type: text/html; charset=UTF-8');
		
		// render the template 
		echo self::$current_page->render();

	}
	

	// takes a content file path and returns a Page model
	public static function factory($path) {

		// instantiate Page model
		$page = new Page;

		// file name without prefix or extension
		$page->name = self::base_name($path);

		// file modified time
		// $page->mtime = filemtime($path);

		// path without trailing slash
		$page->path = rtrim($path, '/');

		// strip content path off to get parents
		$parents = substr($page->path, strlen(Paste::$path.self::$content_dir.'/'));

		// parents array is all enclosing sections
		$parents = array_reverse(explode('/', $parents, -1));

		// filter parent sections for base names
		$page->parents = array_map(array('Paste\\Page', 'base_name'), $parents);

		// parents are represented by their index file
		if ($page->name == 'index') {

			$page->is_parent = TRUE;

			// if deeper than root (root section remains index)
			if (! empty($page->parents)) {
				
				// change name from index to parent name and remove from parents array
				$page->name = array_shift($page->parents);

			}

		}

		// set parent from parents array if deeper than root
		$page->parent = (empty($page->parents[0])) ? NULL : $page->parents[0];

		// setup parent1, parent2, etc. properties for use in templates
		foreach ($page->parents as $num => $parent)
			$page->{'parent'.$num} = $parent;

		// load file content into model
		$page->load();

		// return loaded page model
		return $page;

	}

	// build full URL based on parents, or use defined redirect
	public function url($base = '/') {

		// parent configured to redirect to first child
		if ($this->is_parent AND $this->redirect == 'first_child') {

			// get first child page name
			$first = $this->first_child();

			// return first child url
			return $first->url();

		// redirect configured
		} elseif (! empty($this->redirect)) {

			return $this->redirect;

		} else {

			// iterate parents in reverse
			foreach (array_reverse($this->parents) as $parent)
				$base .= $parent.'/';

			// add page name
			return $base.$this->name;

		}

	}

	// check if current page
	public function is_current() {

		$current_page = self::$current_page->name;
		$current_parent = self::$current_page->parent;
		
		if (self::$current_page->is_parent) {
			// if a parent, don't allow parent to be current()
			return ($this->name == $current_page AND $this->parent == $current_parent);
		} else {
			// if a regular page, allow parent to be current() 
			// TODO:: change this in template to check for section() or parent()->name
			return (($this->name == $current_page AND $this->parent == $current_parent) OR ($this->is_parent AND $this->name == $current_parent));
		}


	}

	// load individual content page, process variables
	public function load() {

		if (($html = @file_get_contents(realpath($this->path))) !== FALSE) {

			// process variables
			$vars = $this->_variables($html);

			// assign vars to current model
			foreach ($vars as $key => $value)
				$this->$key = $value;

			// set title to name if not set otherwise
			$this->title = (empty($this->title)) ? ucwords(str_replace('_', ' ', $this->name)) : $this->title;

			// assign entire html to content property
			$this->content = $html;

			// add page variables for debugging
			// $this->content .= "<pre>".htmlentities(print_r($vars, TRUE)).'</pre>';
			
			// page is loaded
			$this->loaded = TRUE;

		}
	}

	// process content for embedded variables
	protected function _variables($html) {

		// credit to Ben Blank: http://stackoverflow.com/questions/441404/regular-expression-to-find-and-replace-the-content-of-html-comment-tags/441462#441462
		$regexp = '/<!--((?:[^-]+|-(?!->))*)-->/Ui';
		preg_match_all($regexp, $html, $comments);

		// split comments on newline
		$lines = array();
		foreach ($comments[1] as $comment) {
			$var_lines = explode("\n", trim($comment));
			$lines = array_merge($lines, $var_lines);
		}

		// split lines on colon and assign to key/value
		$vars = array();
		foreach ($lines as $line) {
			$parts = explode(":", $line, 2);
			if (count($parts) == 2) {
				$vars[trim($parts[0])] = trim($parts[1]);
			}
		}

		// assign variables in content
		foreach ($vars as $key => $value) {

			// convert booleans to native
			if (strtolower($value) === "false" OR $value === '0') {

				$value = FALSE;

			// convert booleans to native
			} elseif (strtolower($value) === "true" OR $value === '1') {

				$value = TRUE;

			// strip any comments from	variables, except redirect
			} elseif ($key !== 'redirect' AND strpos($value, '//')) {

				$value = substr($value, 0, strpos($value, '//'));

			}

			$vars[$key] = $value;
		}

		return $vars;

	}

	public static function debug() {
		
		echo 'Debug:<br>';
		print_r(self::find_children(NULL));
		
	}
	
	public function menu() {

		return self::find_children(NULL);
		
	}

	// get parent section
	public function parent() {

		// the root section has no parents! like batman
		if ($this->name == 'index')
			return FALSE;

		// root parent is named 'index', rest are renamed to section name
		$parent = ($this->parent == NULL) ? 'index' : $this->parent;

		return self::find(array('name' => $parent));

	}
	
	// get all parents in an array
	public function parents() {

		// init
		$parents = array();

		// start the loop
		$parent = $this;
		
		// add parents while possible
		while (TRUE) {
			
			// get parent
			$parent = $parent->parent();
			
			// no parent to add
			if (empty($parent))
				break;

			// add to list
			$parents[] = $parent;

		}
		
		// reverse list of parents and return
		return array_reverse($parents);

	}

	// return all visible child pages
	public function children($terms = array()) {

		// add optional search terms
		$terms = array_merge($terms, array('parent' => $this->name, 'visible' => TRUE));

		return self::find_all($terms);

	}

	public function first_child() {

		// get visible child pages
		$children = $this->children();

		// get first of child pages
		return array_shift($children);

	}

	public function last_child() {

		// get visible child pages
		$children = $this->children();

		// get first of child pages
		return array_shift($children);

	}

	// return all visible siblings
	public function siblings($terms = array()) {

		// add optional search terms
		$terms = array_merge($terms, array('parent' => $this->parent, 'visible' => TRUE));

		return self::find_all($terms);

	}

	public function first_sibling() {

		// get visible siblings
		$siblings = $this->siblings();

		// get first sibling in section
		return array_shift($siblings);

	}

	public function last_sibling() {

		// get visible siblings
		$siblings = $this->siblings();

		// get last sibling in section
		return array_pop($siblings);

	}

	public function next_sibling() {

		// get next page in section
		$next = $this->_relative_page(1);

		// cycle to first page if last in section
		return ($next === FALSE) ? $this->first_sibling()->url() : $next->url();

	}

	public function prev_sibling() {

		// get previous page in section
		$prev = $this->_relative_page(-1);

		// cycle to last page if first in section
		return ($prev === FALSE) ? $this->last_sibling()->url() : $prev->url();
	}

	// returns page relative to current
	public function _relative_page($offset = 0) {

		// create page map from current section
		$parent = self::find_names(array('parent' => $this->parent, 'visible' => TRUE));

		// find current key
		$current_page_index = array_search($this->name, $parent);

		// check desired offset
		if (isset($parent[$current_page_index + $offset])) {

			// get relative page name
			$relative_page = $parent[$current_page_index + $offset];

			// return relative page model
			return self::find(array('name' => $relative_page));

		}

		// otherwise return false
		return FALSE;
	}
	
	// return array of cascading templates
	public function templates() {

		// init array
		$templates = array();

		// iterate over containing parents
		foreach ($this->parents() as $parent) {
			
			// add parent template if any
			if (! empty($parent->template))
				$templates[] = $parent->template;

		}
		
		// add page template -- order from parents is already reversed, so this is last
		if (! empty($this->template))
			$templates[] = $this->template;
		
		// remove any duplicates and return array
		return array_unique($templates);

	}
	
	// render the page with templates
	public function render() {
		
		// directory where content files are stored
		$templates_path = Paste::$path.self::$template_dir.'/';
		
		// TODO: instantiate engine in constructor, use FilesystemLoader
		// TODO: setup cache folder in Paste
		$mustache = new \Mustache_Engine(array(
			'loader' => new \Mustache_Loader_StringLoader,
			'partials_loader' => new \Mustache_Loader_FilesystemLoader($templates_path, array('extension' => ".stache")),
			// 'cache' => Paste::$path.'cache',
		));

		// placeholder
		$template = '{{{content}}}';

		// iterate over templates and merge together
		foreach ($this->templates() as $parent_template) {
			
			// directory where template files are stored - template name - file extension
			$template_path = Paste::$path.self::$template_dir.'/'.$parent_template.self::$template_ext;

			// load template file 
			$parent_template = file_get_contents(realpath($template_path));
			
			// merge one template into another via the {{{content}}} string
			$template = str_replace('{{{content}}}', $parent_template, $template);

		}
		
		$template = $mustache->loadTemplate($template);
		return $template->render($this);

	}
	

	// load content database
	public static function content_load() {
		
		// traverse content directory and load all content
		if (empty(self::$db)) {
			
			// directory where content files are stored
			$content_path = Paste::$path.self::$content_dir.'/';
			
			// load root and all child sections
			self::$db = self::load_section($content_path);
			
		}
		
	}

	// retrieve single page by properties
	public static function find($terms) {
		
		$pages = self::find_all($terms);

		return (empty($pages)) ? FALSE : current($pages);

	}

	// filter and return pages by properties
	public static function find_all($terms) {
		
		// ensure we have content loaded
		self::content_load();

		$pages = array();

		foreach (self::$db as &$page) {

			foreach ($terms as $property => $value) {

				if ($page->$property !== $value)
					// skip to next page if property doesn't match
					continue 2;

			}

			// clone the page object so we don't alter original
			$pages[] = $page;

		}

		return $pages;

	}

	// returns page names in a flat array
	public static function find_names($terms) {

		$pages = array();

		foreach (self::find_all($terms) as $page) {

			$pages[] = $page->name;

		}

		return $pages;

	}

	// get child pages of a parent
	public static function find_children($parent) {
		
		return self::find_all(array('parent' => $parent));

	}

	// recursively load sections of content
	public static function load_section($path) {

		$pages = array();

		foreach (self::list_path($path) as $file) {
			
			// sub directory
			if (is_dir($path.$file))
				$pages = array_merge($pages, self::load_section($path.$file.'/'));

			// content file with proper extension
			if (is_file($path.$file) AND strpos($file, self::$content_ext))
				$pages[] = self::factory($path.$file);

		}
		
		return $pages;

	}

	// return directory list
	public static function list_path($path) {

		$files = array();

		if (($handle = opendir($path)) === FALSE)
			return $files;

		while (($file = readdir($handle)) !== FALSE) {

			// ignore dot dirs and paths prefixed with an underscore or period
			if ($file != '.' AND $file != '..' AND $file[0] !== '_' AND $file[0] !== '.') {

				$files[] = $file;

			}
		}

		closedir($handle);

		// sort files via natural text comparison, similar to OSX Finder
		usort($files, 'strnatcasecmp');

		// return sorted array (filenames => basenames)
		return $files;

	}

	// get base filename without sorting prefix or extension
	public static function base_name($file) {

		// get file name without content extension
		$name = basename($file, self::$content_ext);

		// base name is everything after intial period if one exists
		$prefix = strpos($name, '.');

		// strip prefix and return cleaned name
		return ($prefix) ? substr($name, $prefix + 1) : $name;

	}
	
	
	

}

<?php
/**
 * Lithium Hooks: A collection of git hooks & scripts that can be used for development in the
 * Lithium core and with Lithium applications.
 *
 * @copyright     Copyright 2009, Union of Rad, Inc. (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace app\extensions\command;

use lithium\core\Libraries;
use lithium\util\Inflector;
use lithium\util\String;
use \RecursiveIteratorIterator;
use \RecursiveDirectoryIterator;

/**
 * Runs syntax checks against files.
 */
class Syntax extends \lithium\console\Command {

	/**
	 * Comma-separated list of checks to use.
	 *
	 * @var string
	 */
	public $checks;

	/**
	 * A regex to exclude paths from being checked.
	 *
	 * @var string Regular expression.
	 */
	public $exclude = '\.';

	/**
	 * Enable output of metrics.
	 *
	 * @var boolean
	 */
	public $metrics;

	/**
	 * Enable blaming of each failure.
	 *
	 * @var boolean
	 */
	public $blame;

	protected $_vcs;

	public $project;

	public function run($file = null) {
		if (!$this->checks) {
			$this->help();
			return 1;
		}
		$this->checks = explode(',' , $this->checks);

		if (!$this->project) {
			$this->project = $this->request->env['working'];
		}
		$this->project = realpath($this->project);

		if (is_dir($this->project . '/.git')) {
			$this->_vcs = 'git';
		}

		if ($file[0] !== '/') {
			$file = $this->project . '/' . $file;
		}
		$failures = is_file($file) ? $this->_checkFile($file) : $this->_checkDirectory($file);

		if ($this->metrics) {
			$this->_metrics($failures);
		}
		return $failures ? 1 : 0;
	}

	protected function _checkFile($file) {
		$message = 'Checking syntax of `' . str_replace($this->project . '/', null, $file) .'`. ';
		$this->out($message, false);
		$failures = array();

		foreach ($this->checks as $check) {
			$class = Libraries::locate('command.syntax', Inflector::camelize($check));
			$check = new $class(array('request' => $this->request));

			if (!$check->accepts($file)) {
				$this->out("Skipped. ", false);
			} elseif ($failures = $check->process($file)) {
				$this->out("Failed. ", false);
			} else {
				$this->out("Passed. ", false);
			}
		}
		$this->nl();

		if ($failures) {
			foreach ($failures as &$failure) {
				$failure['author'] = $this->_blame($failure);
				$this->error(sprintf(
					$this->blame ? '%1$4u| %2$3u| %3$20s| %4$s' : '%1$4u| %2$3u| %4$s',
					$failure['line'] ?: '??',
					$failure['column'] ?: '??',
					$failure['author'] ?: '??',
					$failure['message'] ?: '??'
				));
			}
			$this->nl();
			return $failures;
		}
	}

	protected function _checkDirectory($directory) {
		$base = new RecursiveDirectoryIterator($directory);
		$iterator = new RecursiveIteratorIterator($base);
		$failures = array();

		foreach ($iterator as $item) {
			$basename = $item->getBasename();
			$file = $item->getPathname();

			if (preg_match('/\/' . $this->exclude . '/', $file) || $basename == 'empty') {
				continue;
			}
			if ($result = $this->_checkFile($file)) {
				$failures = array_merge($failures, $result);
			}
		}
		return $failures;
	}

	public function checks() {
		$this->header('Available Checks:');
		$classes = array_unique(Libraries::locate('command.syntax', null, array(
			'recursive' => false
		)));
		foreach ($classes as $command) {
            $command = explode('\\', $command);
            $this->out(' - ' . Inflector::underscore(array_pop($command)));
		}
	}

	protected function _metrics($failures) {
		$this->header('Metrics');
		$this->nl();
		$total = count($failures);
		$byAuthor = array();

		foreach ($failures as $failure) {
			$byAuthor[$failure['author']][$failure['message']][] = $failure;
		}
		ksort($byAuthor);

		foreach ($byAuthor as $author => $failures) {
			if (!$author) {
				continue;
			}
			$this->out($author);
			ksort($failures);

			foreach ($failures as $message => $messageFailures) {
				$this->out(' - `' . $message . '` ('. count($messageFailures) .')');
			}
			$this->nl();
		}
		$this->nl();
		$this->out("Total: {$total}");
		$this->nl();
	}

	protected function _blame($failure) {
		if (!$this->_vcs == 'git') {
			return null;
		}
		$backup = getcwd();
		chdir($this->project);
		$lines = count(file($failure['file']));

		$command = 'git blame -L{:start},{:end} --porcelain {:file}';
		$replace = array(
			'start' => $failure['line'],
			'end' => $lines == $failure['line'] ? $failure['line'] : $failure['line'] + 1,
			'file' => $failure['file']
		);
		exec(String::insert($command, $replace), $output, $return);
		chdir($backup);

		if ($return == 0) {
			list(, $author) = explode(' ', $output[1], 2);
			return $author;
		}
	}
}

?>
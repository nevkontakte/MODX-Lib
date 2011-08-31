<?php
/**
 * Custom Exception class which implements storing previous exception feature first added in PHP 5.3
 */
if (version_compare(phpversion(), '5.3', '<')) {
	class Exception53 extends Exception
	{
		private $native = false;
		private $previous = null;

		function __construct($message = '', $code = 0, $previous = null)
		{
			parent::__construct($message, $code);
			$this->previous = $previous;
		}

		public function getPrevious()
		{
			return $this->previous;
		}
	}
}
else
{
	class Exception53 extends Exception
	{
	}
}
?>

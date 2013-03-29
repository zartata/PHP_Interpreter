<?php
namespace Foxway;
/**
 * Runtime class of Foxway extension.
 *
 * @file Runtime.php
 * @ingroup Foxway
 * @author Pavel Astakhov <pastakhov@yandex.ru>
 * @licence GNU General Public Licence 2.0 or later
 */
class Runtime {

	private $lastCommand = false;
	private $lastDebug = false;
	private $lastParam = null;
	private $listParams = array();
	private $lastOperator = false;
	private $lastVariable = false;
	private $listVariables = array();
	private $lastVariableOperator = false;

	private $stack = array();
	private static $variables = array();

	private function pushStack() {
		$this->stack[] = array($this->lastCommand, $this->lastDebug, $this->lastParam, $this->listParams, $this->lastOperator, $this->lastVariable, $this->listVariables, $this->lastVariableOperator);
	}

	public function popStack() {
		if( count($this->stack) == 0 ) {
			$this->lastCommand = false;
			$this->lastDebug = false;
			$this->lastParam = null;
			$this->listParams = array();
			$this->lastOperator = false;
			$this->lastVariable = false;
			$this->listVariables = array();
			$this->lastVariableOperator = false;
		} else {
			list($this->lastCommand, $this->lastDebug, $this->lastParam, $this->listParams, $this->lastOperator, $this->lastVariable, $this->listVariables, $this->lastVariableOperator) = array_pop($this->stack);
		}
	}

	public function addCommand( $name, $debug ) {
		if( $this->lastCommand ) {
			$this->pushStack();
		}
		$this->lastCommand = $name;
		$this->lastDebug = $debug;
	}

	public function addParam( $param ) {
		if( $this->lastOperator ) {
			switch ( $this->lastOperator ) {
				case '.':
					$this->lastParam = $this->lastParam . $param;
					$this->lastOperator = false;
					break;
				default:
					// TODO
					\MWDebug::log( 'Error! Unknown operator "' . htmlspecialchars($this->lastOperator) . '" in ' . __METHOD__ );
					break;
			}
		} else {
			if( !is_null($this->lastParam) ) {
				$this->listParams[] = $this->lastParam;
			}
			$this->lastParam = $param;
		}
	}

	public function addOperator( $operator ) {
		$this->lastOperator = $operator;
	}

	public function getCommandResult( &$debug ) {
		$this->listParams[] = $this->lastParam;
		$return = null;

		switch ($this->lastCommand) {
			case 'echo':
				$return = implode('', $this->listParams);
				if( $this->lastDebug !== false ) {
					$debug[$this->lastDebug] = '<span style="color:#0000E6" title="'. token_name(T_ECHO) . ' do ' . htmlspecialchars($return) . '">' . $debug[$this->lastDebug] . '</span>';
				}
				break;
			default:
				$lastCommand = $this->lastCommand;
				if( substr($lastCommand, 0, 1) == '$' ) {
					switch ($this->lastVariableOperator) {
						case '=':
							if( $this->lastDebug !== false ) {
								$debug[$this->lastDebug] = '<span style="color:#6D3206" title="'.token_name(T_VARIABLE).' set '.htmlspecialchars( var_export($this->lastParam, true) ).'">' . $lastCommand . '</span>';
							}
							self::$variables[ substr($lastCommand, 1) ] = $this->lastParam;
							break;
						default:
							// TODO exception
							$return = 'Error! Unknown operator "' . htmlspecialchars($this->lastVariableOperator) . '" in ' . __METHOD__;
							\MWDebug::log($return);
							break;
					}

				} else {
					// TODO exception
					$return = 'Error in ' . __METHOD__;
				}
				break;
		}
		$this->popStack();
		return $return;
	}

	public function setVariable( $name, $debug ) {
		$this->addCommand($name, $debug);
	}

	public function setVariableOperator( $operator ) {
		$this->lastVariableOperator = $operator;
	}

	public function getVariable( $name ) {
		if( isset(self::$variables[$name]) ) {
			return self::$variables[$name];
		} else {
			//TODO THROW
			return null;
		}
	}

}

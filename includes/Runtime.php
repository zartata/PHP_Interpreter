<?php
namespace Foxway;

define( 'FOXWAY_STACK_RESULT', 'r' );
define( 'FOXWAY_STACK_COMMAND', 'c' );
define( 'FOXWAY_STACK_PARAM', 'p' );
define( 'FOXWAY_STACK_PARAM_2', 's' );
define( 'FOXWAY_STACK_INC_AFTER', 'i' );
define( 'FOXWAY_STACK_TOKEN_LINE', 'l' );
define( 'FOXWAY_STACK_DO_TRUE', 't' );
define( 'FOXWAY_STACK_DO_FALSE', 'f' );
define( 'FOXWAY_STACK_ARRAY_INDEX', 'a' );

// definitions for Runtame::$functions
define( 'FOXWAY_DEFAULT_VALUES', 'd' );
define( 'FOXWAY_MIN_VALUES', '<' );

/**
 * Runtime class of Foxway extension.
 *
 * @file Runtime.php
 * @ingroup Foxway
 * @author Pavel Astakhov <pastakhov@yandex.ru>
 * @licence GNU General Public Licence 2.0 or later
 */
class Runtime {

	static public $functions=array();
	static public $constants=array();
	static public $allowedNamespaces = true;
	static public $time = 0;
	protected $lastCommand = false;
	protected $passByReference = 0;

	/**
	 *
	 * @var RValue
	 */
	protected $lastParam = null;

	/**
	 *
	 * @var array
	 */
	protected $listParams = array();
	protected $lastOperator = false;
	protected $variableOperator = false;
	protected $mathMemory = array();

	protected $stack = array();
	protected static $variables = array();
	protected static $staticVariables = array();
	protected static $globalVariables = array();
	//protected static $time = array();
	protected static $startTime = array();
	protected $thisVariables;
	protected $args;
	protected $scope;

	// @see http://www.php.net/manual/ru/language.operators.precedence.php
	protected static $operatorsPrecedence = array(
		//array('['),
		//		++		--		(int)			(float)		(string)		(array)		(bool)			(unset)
		array(T_INC, T_DEC, '~', T_INT_CAST, T_DOUBLE_CAST, T_STRING_CAST, T_ARRAY_CAST, T_BOOL_CAST, T_UNSET_CAST),
		array('!'),
		array('*', '/', '%'),
		array('+', '-', '.'),
		//		<<	>>
		array(T_SL, T_SR),
		//						<=						>=
		array('<', '>', T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL),
		//		==				!=				===				!==
		array(T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL),
		array('&'),
		array('^'),
		array('|'),
		array(T_BOOLEAN_AND), // &&
		array(T_BOOLEAN_OR), // ||
		array('?', ':'),
		//				+=			-=				*=			/=			.=				%=				&=			|=			^=			<<=			>>=				=>
		array('=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_DOUBLE_ARROW),
		array(T_LOGICAL_AND), // and
		array(T_LOGICAL_XOR), // xor
		array(T_LOGICAL_OR), // or
		array(','),
	);
	private static $precedencesCount;
	private static $precedencesMatrix=array();

	public function __construct( array $args, $scope ) {
		$this->args = $args;
		if( !isset(self::$variables[$scope]) ) {
			self::$variables[$scope] = array();
		}
		$this->scope = $scope;
		$this->thisVariables = &self::$variables[$scope];
		$this->thisVariables['argv'] = $args;
		$this->thisVariables['argc'] = count($args);
		$this->thisVariables['GLOBALS'] = &self::$globalVariables;
		if( empty(self::$precedencesCount) ) {
			foreach (self::$operatorsPrecedence as $key => &$value) {
				self::$precedencesMatrix += array_fill_keys($value, $key);
			}
			self::$precedencesCount = $key;
		}
	}

	public function getOperators() {
		static $operators = array();
		if( count($operators) == 0 ) {
			foreach (self::$operatorsPrecedence as &$value) {
				$operators = array_merge($operators, $value);
			}
		}
		return $operators;
	}

	protected function pushStack() {
		$this->stack[] = array($this->lastCommand, $this->passByReference, $this->listParams, $this->lastOperator, $this->variableOperator, $this->mathMemory);
		$this->resetRegisters();
	}

	protected function popStack() {
		if( count($this->stack) == 0 ) {
			$this->resetRegisters();
		} else {
			list($this->lastCommand, $this->passByReference, $this->listParams, $this->lastOperator, $this->variableOperator, $this->mathMemory) = array_pop($this->stack);
		}
	}

	protected function resetRegisters() {
		$this->lastCommand = false;
		$this->passByReference = 0;
		$this->lastParam = null;
		$this->listParams = array();
		$this->lastOperator = false;
		$this->variableOperator = false;
		$this->mathMemory = array();
	}

	public function addCommand( $name ) {
		if( $this->lastOperator ) {
			$precedence = self::$precedencesMatrix[$this->lastOperator];
			$this->mathMemory[$precedence] = array($this->lastOperator, $this->lastParam);
			$this->lastOperator = false;
		}
		$this->pushStack();
		$this->lastCommand = $name;
	}

	/**
	 *
	 * @param string $variable Variable name
	 * @param integer $scope Variable scope (default, static, global)
	 * @return boolean Normally return true, false for already initialized static variables
	 */
	public function addParamVariable( $variable, $scope = T_VARIABLE ) {
		$return = true;
		$variable = substr($variable, 1);

		switch ($scope) {
			case T_STATIC:
				if( isset($this->thisVariables[$variable]) ) {
					return new ErrorMessage(__LINE__, null, E_PARSE, T_STATIC);
				}
				$args0 = isset($this->args[0]) ? $this->args[0] : '';
				if( !isset(self::$staticVariables[$args0]) ) {
					self::$staticVariables[$args0] = array();
				}
				if( !isset(self::$staticVariables[$args0][$variable]) ) {
					self::$staticVariables[$args0][$variable] = null;
				}else{
					$return = false;
				}
				$this->thisVariables[$variable] = &self::$staticVariables[$args0][$variable];
				break;
			case T_GLOBAL:
				if( !isset(self::$globalVariables[$variable]) ) {
					self::$globalVariables[$variable] = null;
				}
				$this->thisVariables[$variable] = &self::$globalVariables[$variable];
				return $return;
				break;
		}
		$this->addParam( new RVariable($variable, $this->thisVariables) );

		return $return;
	}

	public function addParamValue( $value ) {
		$this->addParam( new RValue($value) );
	}

	protected function addParam(RValue $param) {
		if( $this->lastOperator ) {
			$precedence = self::$precedencesMatrix[$this->lastOperator];
			$this->mathMemory[$precedence] = array($this->lastOperator, $this->lastParam);
			$this->lastOperator = false;
		}
		$this->lastParam = $param;
	}

	protected function parenthesesOpen() {
		global $wgFoxwayPassByReference;

		if( $this->lastOperator ) {
			$precedence = self::$precedencesMatrix[$this->lastOperator];
			$this->mathMemory[$precedence] = array($this->lastOperator, $this->lastParam);
			$this->lastOperator = false;
		}

		$lastCommand = $this->lastCommand;
		$this->pushStack();
		if( is_scalar($lastCommand) && isset($wgFoxwayPassByReference[$lastCommand]) ) {
			$this->passByReference = $wgFoxwayPassByReference[$lastCommand];
		}

	}

	protected function parenthesesClose() {
		$this->doMath();
		if( count($this->listParams) ) {
			if( $this->lastParam instanceof RValue ) {
				if( $this->passByReference & 1 ) {
					$this->listParams[] = $this->lastParam;
				}else{
					$this->listParams[] = $this->lastParam->getValue();
				}
			}
			$this->lastParam = $this->listParams;
		}
		$this->popStack();
	}

	public function addOperator( $operator ) {
		switch ($operator) {
			case ',':
				$this->doMath( self::$precedencesMatrix[$operator] );
				if( $this->lastOperator == T_DOUBLE_ARROW ) {
					$this->lastOperator = false;
				}else{
					if( $this->passByReference & 1 ) {
						$this->listParams[] = $this->lastParam;
					}else{
						$this->listParams[] = $this->lastParam->getValue();
					}
					if( $this->passByReference > 0 ) {
						$this->passByReference >>= 1;
					}
				}
				$this->lastParam = null;
				break;
			case '?':
				$this->doMath( self::$precedencesMatrix[$operator] );
				return $this->lastParam->getValue();
				break;
			case '"(':
			case '(':
				$this->parenthesesOpen();
				break;
			case '")':
				$this->lastOperator = false;
				$this->parenthesesClose();
				break;
			case ',)':
				if( !is_null($this->lastParam) ) {
					$this->addOperator(',');
				}
				// break is not necessary here
			case ')':
				$return = $this->checkExceedsTime();
				if( $return !== null ) {
					$this->lastCommand = false;
				}
				$this->parenthesesClose();
				switch ($this->lastCommand) {
					case false:
					case T_ECHO:
					case T_PRINT:
					case T_CONTINUE:
					case T_BREAK:
						break 2;
					case T_WHILE:
					case T_IF:
						$return = array( $this->lastCommand, $this->lastParam->getValue() );
						//$this->lastCommand = false;
						//return $return;
						break;
					case T_ARRAY:
						$this->lastParam = new RValue( (array)$this->lastParam );
						break;
					case 'get_defined_vars': //Returns an array of all defined variables  @see http://www.php.net/manual/en/function.get-defined-vars.php
						if( count($this->lastParam) != 0 ) {
							$return = BaseFunction::wrongParameterCount('f_get_defined_vars', __LINE__);
							$return->params[2] = isset($this->args[0]) ? $this->args[0] : 'n\a';
						}
						$this->lastParam = new RValue( $this->thisVariables );
						break;
					default:
						$return = $this->doCommand();
						break;
				}
				$this->lastCommand = false;
				$this->popStack();
				//$this->doMath();
				return $return;
				break;
			case '[':
				$this->addCommand( $this->lastParam );
				break;
			case ']':
				$this->doMath();
				$this->lastParam = new RArray( $this->lastCommand, $this->lastParam );
				$this->popStack();
				break;
			default:
				$precedence = self::$precedencesMatrix[$operator];
				//						For negative operator
				if( $precedence == 0 || $this->lastOperator || is_null($this->lastParam) ) {
					switch ($operator) {
						case '+':
							break; // ignore this
						case '-':
						case '~':
						case T_INT_CAST:
						case T_DOUBLE_CAST:
						case T_STRING_CAST:
						case T_ARRAY_CAST:
						case T_BOOL_CAST:
						case T_UNSET_CAST:
						case T_INC:
						case T_DEC:
							if( !isset($this->mathMemory[0]) ) {
								$this->mathMemory[0] = array();
							}
							$this->mathMemory[0][] = $operator;
							if( $this->lastParam instanceof RVariable && !$this->lastOperator ) {
								$this->lastOperator = $operator;
								$this->doMath(0);
							}
							break;
						default:
							\MWDebug::log( __METHOD__ . " unknown operator '$operator'" );
							break;
					}
				} else {
					//doOperation for higher precedence
					$this->doMath($precedence);
					$this->lastOperator = $operator;
				}
				break;
		}
	}

	protected function doMath( $precedence = false ) {
		if( isset($this->mathMemory[0]) ) {
			while( $mathZerroMemory = array_pop($this->mathMemory[0]) ) {
				$this->doOperation($mathZerroMemory);
			}
			unset($this->mathMemory[0]);
		}
		if($precedence === false){
			$precedence = self::$precedencesCount;
		}
		for($n = 1; $n <= $precedence; $n++) {
			if( isset($this->mathMemory[$n]) ) {
				$this->doOperation($this->mathMemory[$n][0], $this->mathMemory[$n][1]);
				unset($this->mathMemory[$n]);
			}
		}
	}

	/**
	 *
	 * @param mixed $operator
	 * @param RVariable $param
	 */
	protected function doOperation($operator, $param = null) {
		$lastParam = $this->lastParam->getValue();

		switch ($operator) {
			case T_INC: // ++
			case T_DEC: // --
				$lastParam = $this->lastOperator;
				$this->lastOperator = false;
				$param = $this->lastParam;
				// break is not necessary here
			case '=':
			case T_CONCAT_EQUAL:// .=
			case T_PLUS_EQUAL:// +=
			case T_MINUS_EQUAL:// -=
			case T_MUL_EQUAL: // *=
			case T_DIV_EQUAL: // /=
			case T_MOD_EQUAL: // %=
			case T_AND_EQUAL:// &=
			case T_OR_EQUAL:// |=
			case T_XOR_EQUAL:// ^=
			case T_SL_EQUAL:// <<=
			case T_SR_EQUAL:// >>=
				$this->lastParam = $param->doOperation( $operator, $lastParam );
				break;
			case T_DOUBLE_ARROW:// =>
				$this->listParams[$param->getValue()] = $lastParam;
				$this->lastOperator = T_DOUBLE_ARROW;
				break;
			case '.':
				$this->lastParam = new RValue( $param->getValue() . $lastParam );
				break;
			case '+':
				$this->lastParam = new RValue( $param->getValue() + $lastParam );
				break;
			case '-':
				$this->lastParam = $param === null ? new RValue( -$lastParam ) : new RValue( $param->getValue() - $lastParam );
				break;
			case '*':
				$this->lastParam = new RValue( $param->getValue() * $lastParam );
				break;
			case '/':
				if( $lastParam == 0 ) { // Division by zero
					$this->lastParam = new RValue( false );
				} else {
					$this->lastParam = new RValue( $param->getValue() / $lastParam );
				}
				break;
			case '%':
				if( $lastParam == 0 ) { // Division by zero
					$this->lastParam = new RValue( false );
				} else {
					$this->lastParam = new RValue( $param->getValue() % $lastParam );
				}
				break;
			case '&':
				$this->lastParam = new RValue( $param->getValue() & $lastParam );
				break;
			case '|':
				$this->lastParam = new RValue( $param->getValue() | $lastParam );
				break;
			case '^':
				$this->lastParam = new RValue( $param->getValue() ^ $lastParam );
				break;
			case T_BOOLEAN_AND: // &&
				$this->lastParam = new RValue( $param->getValue() && $lastParam );
				break;
			case T_BOOLEAN_OR: // ||
				$this->lastParam = new RValue( $param->getValue() || $lastParam );
				break;
			case T_SL: // <<
				$this->lastParam = new RValue( $param->getValue() << $lastParam );
				break;
			case T_SR: // >>
				$this->lastParam = new RValue( $param->getValue() >> $lastParam );
				break;
			case '~':
				$this->lastParam = new RValue( ~$lastParam );
				break;
			case T_INT_CAST:
				$this->lastParam = new RValue( (integer) $lastParam );
				break;
			case T_DOUBLE_CAST:
				$this->lastParam = new RValue( (float) $lastParam );
				break;
			case T_STRING_CAST:
				$this->lastParam = new RValue( (string) $lastParam );
				break;
			case T_ARRAY_CAST:
				$this->lastParam = new RValue( (array) $lastParam );
				break;
			case T_BOOL_CAST:
				$this->lastParam = new RValue( (bool) $lastParam );
				break;
			case T_UNSET_CAST:
				$this->lastParam = new RValue( (unset) $lastParam );
				break;
			case '<':
				$this->lastParam = new RValue( $param->getValue() < $lastParam );
				break;
			case '>':
				$this->lastParam = new RValue( $param->getValue() > $lastParam );
				break;
			case T_IS_SMALLER_OR_EQUAL: // <=
				$this->lastParam = new RValue( $param->getValue() <= $lastParam );
				break;
			case T_IS_GREATER_OR_EQUAL: // >=
				$this->lastParam = new RValue( $param->getValue() >= $lastParam );
				break;
			case T_IS_EQUAL: // ==
				$this->lastParam = new RValue( $param->getValue() == $lastParam );
				break;
			case T_IS_NOT_EQUAL: // !=
				$this->lastParam = new RValue( $param->getValue() != $lastParam );
				break;
			case T_IS_IDENTICAL: // ===
				$this->lastParam = new RValue( $param->getValue() === $lastParam );
				break;
			case T_IS_NOT_IDENTICAL: // !==
				$this->lastParam = new RValue( $param->getValue() !== $lastParam );
				break;
			default:
				\MWDebug::log( __METHOD__ . " unknown operator '$operator'" );
				break;
		}
	}

	// Remember the child class RuntimeDebug
	public function getCommandResult( ) {
		$return = $this->checkExceedsTime();
		if( $return !== null ) {
			return $return;
		}
		if( $this->lastParam !== null ) {
			$this->addOperator(',');
		}

		// Remember the child class RuntimeDebug
		switch ($this->lastCommand) {
			case T_ECHO:
			case T_PRINT:
			case T_CONTINUE:
			case T_BREAK:
				$return = array( $this->lastCommand, $this->listParams );
				break;
			case false:
				break; // exsample: $foo = 'foobar';
			default:
				// TODO
				$return = 'Error! Unknown command "' . htmlspecialchars($this->lastCommand) . '" in ' . __METHOD__;
				\MWDebug::log($return);
		}
		$this->popStack();
		//$this->lastParam = null;
		return $return;
	}

	protected function doCommand() {
		$return = null;

		$functionName = "f_{$this->lastCommand}";
		$functionClass = 'Foxway\\' . Interpreter::getClassNameForFunction($this->lastCommand);

		// @todo check for $functionClass is exists class
		$class = new \ReflectionClass($functionClass);
		if( $class->isSubclassOf("Foxway\\BaseFunction") ) {
			try {
				$this->lastParam = $functionClass::$functionName( $this->lastParam );
			} catch (Exception $exc) {
				$this->lastParam = new RValue(null);
				$return = new ErrorMessage(
					__LINE__,
					null,
					E_WARNING,
					array(
						'foxway-php-warning-exception-in-function',
						"{$functionClass}->{$functionName}",
						isset($this->args[0]) ? $this->args[0] : 'n\a',
						$exc->getMessage(),
					)
				);
			}
			if( $this->lastParam instanceof iRawOutput ) {
				$return = $this->lastParam;
				if( $this->lastParam instanceof ErrorMessage ) {
					$return->params[2] = isset($this->args[0]) ? $this->args[0] : 'n\a';
					$this->lastParam = new RValue(null);
				}
			}
		} else {
			$this->lastParam = new RValue(null);
			return new ErrorMessage(
					__LINE__,
					null,
					E_ERROR,
					array('foxway-unexpected-result-work-function', __METHOD__, isset($this->args[0]) ? $this->args[0] : 'n\a')
				);
		}

		return $return;
	}

	public function startTime($scope) {
		self::$startTime[$scope] = microtime(true);
		if( isset(self::$time[$scope]) ) {
			return $this->checkExceedsTime();
		}else{
			self::$time[$scope] = 0;
		}
	}

	public function stopTime($scope) {
		self::$time[$scope] += microtime(true) - self::$startTime[$scope];
	}

	public static function getTime() {
		return self::$time;
	}

	public function checkExceedsTime() {
		global $wgFoxway_max_execution_time_for_scope;
		if( microtime(true) - self::$startTime[$this->scope] + self::$time[$this->scope] > $wgFoxway_max_execution_time_for_scope ) {
			return new ErrorMessage( __LINE__, null, E_ERROR, array( 'foxway-php-fatal-error-max-execution-time-scope', $wgFoxway_max_execution_time_for_scope, isset($this->args[0])?$this->args[0]:'n\a' ) );
		}
		return null;
	}

	public static function runSource($code, array $args = array(), $scope = '') {
		return self::run( Compiler::compile($code), $args, $scope );
	}

	public static function run($code, array $args, $scope = '') {
		if( !isset(self::$variables[$scope]) ) {
			self::$variables[$scope] = array();
		}
		$thisVariables = &self::$variables[$scope];
		$thisVariables['argv'] = $args;
		$thisVariables['argc'] = count($args);
		$thisVariables['GLOBALS'] = &self::$globalVariables;
		$memory=array();
		$return = array();
		$break = 0; // used for T_BREAK
		$continue = false; // used for T_CONTINUE
		$loopsOwner = null;

		$c=count($code);
		$i=-1;
		do {
			if( $break ) {
				if( $loopsOwner == T_WHILE ) {
					$break--;
					continue;
				}
				break;
			}elseif( $continue ) {
				if( $loopsOwner == T_WHILE ) {
					$i = -1;
					$continue = false;
				}else{
					continue;
				}
			}
			$i++;
			for(; $i<$c; $i++ ) {
				$value = &$code[$i];
				switch ($value[FOXWAY_STACK_COMMAND]) {
					case T_CONST:
					case T_DOUBLE_ARROW:
						break; // ignore it, @todo need remove it from $code in class Compiler
					case T_ENCAPSED_AND_WHITESPACE:
						$value[FOXWAY_STACK_RESULT] = implode($value[FOXWAY_STACK_PARAM]);
						break;
					case '.':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] . $value[FOXWAY_STACK_PARAM_2];
						break;
					case '+':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] + $value[FOXWAY_STACK_PARAM_2];
						break;
					case '-':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] - $value[FOXWAY_STACK_PARAM_2];
						break;
					case '*':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] * $value[FOXWAY_STACK_PARAM_2];
						break;
					case '/':
						if( (int)$value[FOXWAY_STACK_PARAM_2] == 0 ) {
							throw new ExceptionFoxway(null, FOXWAY_PHP_WARNING_DIVISION_BY_ZERO, $value[FOXWAY_STACK_TOKEN_LINE]);
						}
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] / $value[FOXWAY_STACK_PARAM_2];
						break;
					case '%':
						if( (int)$value[FOXWAY_STACK_PARAM_2] == 0 ) {
							throw new ExceptionFoxway(null, FOXWAY_PHP_WARNING_DIVISION_BY_ZERO, $value[FOXWAY_STACK_TOKEN_LINE]);
						}
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] % $value[FOXWAY_STACK_PARAM_2];
						break;
					case '&':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] & $value[FOXWAY_STACK_PARAM_2];
						break;
					case '|':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] | $value[FOXWAY_STACK_PARAM_2];
						break;
					case '^':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] ^ $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_SL:			// <<
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] << $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_SR:			// >>
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] >> $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_BOOLEAN_AND:	// &&
					case T_LOGICAL_AND:	// and
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] && $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_LOGICAL_XOR:	// xor
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] xor $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_BOOLEAN_OR:	// ||
					case T_LOGICAL_OR:	// or
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] || $value[FOXWAY_STACK_PARAM_2];
						break;
					case '<':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] < $value[FOXWAY_STACK_PARAM_2];
						break;
					case '>':
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] > $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_IS_SMALLER_OR_EQUAL:	// <=
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] <= $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_IS_GREATER_OR_EQUAL:	// >=
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] >= $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_IS_EQUAL:			// ==
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] == $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_IS_NOT_EQUAL:		// !=
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] != $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_IS_IDENTICAL:		// ===
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] === $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_IS_NOT_IDENTICAL:	// !==
						$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM] !== $value[FOXWAY_STACK_PARAM_2];
						break;
					case T_ECHO:
						foreach( $value[FOXWAY_STACK_PARAM] as $v ) {
							$return[] = $v[FOXWAY_STACK_RESULT];
						}
						break;
					case T_PRINT:
						$return[] = $value[FOXWAY_STACK_PARAM];
						break;
					case '~':
						$value[FOXWAY_STACK_RESULT] = ~$value[FOXWAY_STACK_PARAM_2];
						break;
					case '!':
						$value[FOXWAY_STACK_RESULT] = !$value[FOXWAY_STACK_PARAM_2];
						break;
					case T_INT_CAST:		// (int)
						$value[FOXWAY_STACK_RESULT] = (int)$value[FOXWAY_STACK_PARAM_2];
						break;
					case T_DOUBLE_CAST:		// (double)
						$value[FOXWAY_STACK_RESULT] = (double)$value[FOXWAY_STACK_PARAM_2];
						break;
					case T_STRING_CAST:		// (string)
						$value[FOXWAY_STACK_RESULT] = (string)$value[FOXWAY_STACK_PARAM_2];
						break;
					case T_ARRAY_CAST:		// (array)
						$value[FOXWAY_STACK_RESULT] = (array)$value[FOXWAY_STACK_PARAM_2];
						break;
					case T_BOOL_CAST:		// (bool)
						$value[FOXWAY_STACK_RESULT] = (bool)$value[FOXWAY_STACK_PARAM_2];
						break;
					case T_UNSET_CAST:		// (unset)
						$value[FOXWAY_STACK_RESULT] = (unset)$value[FOXWAY_STACK_PARAM_2];
						break;
					case '?':
						if( $value[FOXWAY_STACK_PARAM] ) { // true ?
							if( $value[FOXWAY_STACK_PARAM_2][FOXWAY_STACK_DO_TRUE] ) { // true ? 1+2 :
								$memory[] = array( &$value[FOXWAY_STACK_PARAM_2][FOXWAY_STACK_PARAM], $code, $i, $c, $loopsOwner );
								$code = $value[FOXWAY_STACK_PARAM_2][FOXWAY_STACK_DO_TRUE];
								$i = -1;
								$c = count($code);
								$loopsOwner = '?';
							}else{ // true ? 1 :
								$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM_2][FOXWAY_STACK_PARAM];
							}
						}else{ // false ?
							if( $value[FOXWAY_STACK_PARAM_2][FOXWAY_STACK_DO_FALSE] ) { // false ? ... : 1+2
								$memory[] = array( &$value[FOXWAY_STACK_PARAM_2][FOXWAY_STACK_PARAM_2], $code, $i, $c, $loopsOwner );
								$code = $value[FOXWAY_STACK_PARAM_2][FOXWAY_STACK_DO_FALSE];
								$i = -1;
								$c = count($code);
								$loopsOwner = '?';
							}else{ // false ? ... : 1
								$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_PARAM_2][FOXWAY_STACK_PARAM_2];
							}
						}
						break;
					case T_IF:
						if( $value[FOXWAY_STACK_PARAM] ) { // Example: if( true )
							if( $value[FOXWAY_STACK_DO_TRUE] ) { // Stack not empty: if(true);
								$memory[] = array( null, $code, $i, $c, $loopsOwner );
								$code = $value[FOXWAY_STACK_DO_TRUE];
								$i = -1;
								$c = count($code);
								$loopsOwner = T_IF;
							}
						}else{ // Example: if( false )
							if( isset($value[FOXWAY_STACK_DO_FALSE]) ) { // Stack not empty: if(false) ; else ;
								$memory[] = array( null, $code, $i, $c, $loopsOwner );
								$code = $value[FOXWAY_STACK_DO_FALSE];
								$i = -1;
								$c = count($code);
								$loopsOwner = T_IF;
							}
						}
						break;
					case T_WHILE: // PHP code "while($foo) { ... }" doing as T_WHILE { T_DO($foo) ... }. If $foo == false, T_DO doing as T_BREAK
						$memory[] = array( null, $code, $i, $c, $loopsOwner );
						$code = $value[FOXWAY_STACK_DO_TRUE];
						$i = -1;
						$c = count($code);
						$loopsOwner = T_WHILE;
						break;
					case T_DO:
						if( $value[FOXWAY_STACK_PARAM] ) {
							continue; // this is "while(true)", just go next
						}// while(false) doing as T_BREAK;
						break 2; // go to one level down
					case T_BREAK:
						$break = $value[FOXWAY_STACK_RESULT];
						if( $loopsOwner == T_WHILE ) {
							$break--;
						}
						break 2; // go to one level down
					case T_CONTINUE:
						$break = $value[FOXWAY_STACK_RESULT]-1;
						if( $loopsOwner == T_WHILE && $break == 0 ) { // Example: while(true) continue;
							$i = -1;
							break;
						}
						$continue = true;
						break 2; // go to one level down
					case T_ARRAY:			// array
						$value[FOXWAY_STACK_RESULT] = array(); // init array
						foreach ($value[FOXWAY_STACK_PARAM] as $v) {
							if( $v[FOXWAY_STACK_COMMAND] == T_DOUBLE_ARROW ) {
								$value[FOXWAY_STACK_RESULT][ $v[FOXWAY_STACK_RESULT] ] = $v[FOXWAY_STACK_PARAM_2];
							}else{
								$value[FOXWAY_STACK_RESULT][] = $v[FOXWAY_STACK_RESULT];
							}
						}
						break;
					case T_VARIABLE:
						if( isset($thisVariables[$value[FOXWAY_STACK_PARAM]]) ) {
							$value[FOXWAY_STACK_RESULT] = $thisVariables[$value[FOXWAY_STACK_PARAM]];
							if( isset($value[FOXWAY_STACK_ARRAY_INDEX]) ) { // Example: $foo[1]
								foreach( $value[FOXWAY_STACK_ARRAY_INDEX] as $v ) {
									if( isset($value[FOXWAY_STACK_RESULT][$v]) ) {
										$value[FOXWAY_STACK_RESULT] = $value[FOXWAY_STACK_RESULT][$v];
									}else{
										$value[FOXWAY_STACK_RESULT] = null;
										// @todo E_NOTICE
									}
								}
							}
						}else{
							$value[FOXWAY_STACK_RESULT] = null;
							// @todo E_NOTICE
						}
						break;
					case T_STATIC:
						$p = isset($args[0]) ? $args[0] : ''; // Page name
						$vn = $value[FOXWAY_STACK_PARAM_2]; // variable name
						if( !isset(self::$staticVariables[$p][$vn]) ) {
							self::$staticVariables[$p][$vn] = &$value[FOXWAY_STACK_PARAM];
							if( $value[FOXWAY_STACK_DO_FALSE] ) {
								//self::$staticVariables[$p][$vn] = null;
								$memory[] = array( null, $code, $i, $c, $loopsOwner );
								$code = $value[FOXWAY_STACK_DO_FALSE];
								$i = -1;
								$c = count($code);
								$loopsOwner = T_STATIC;
							}
						}
						$thisVariables[$vn] = &self::$staticVariables[$p][$vn];
						break;
					case T_GLOBAL:
						foreach( $value[FOXWAY_STACK_PARAM] as $vn ) { // variable names
							if( !isset(self::$globalVariables[$vn]) ) {
								self::$globalVariables[$vn] = null;
							}
							$thisVariables[$vn] = &self::$globalVariables[$vn];
						}
						break;
					case T_STRING:
						$name = $value[FOXWAY_STACK_PARAM_2];
						if( isset($value[FOXWAY_STACK_PARAM]) ) { // This is function or object
							if( is_array($value[FOXWAY_STACK_PARAM]) ) { // This is function
								if( isset( self::$functions[$name] ) ) {
									$function = &self::$functions[$name];
									$param = array();
									foreach($value[FOXWAY_STACK_PARAM] as $val) {
										if( $val[FOXWAY_STACK_COMMAND] == T_VARIABLE ) { // Example $foo
											$ref = &$thisVariables[ $val[FOXWAY_STACK_PARAM] ];
											if( isset($val[FOXWAY_STACK_ARRAY_INDEX]) ) { // Example: $foo[1]
												foreach( $val[FOXWAY_STACK_ARRAY_INDEX] as $v ) {
													if( !isset($ref[$v]) ) {
														$ref[$v]=null;
														// @todo E_NOTICE
													}
													$ref = &$ref[$v];
												}
											}
											$param[] = &$ref;
										}else{
											$param[] = $val[FOXWAY_STACK_RESULT];
										}
									}
									$count = count( $param );
									do {
										if( isset($function[$count]) ) {
											$function = &$function[$count];
											break;
										}else{
											if( isset($function[FOXWAY_DEFAULT_VALUES]) ) { // Has default values
												$param += $function[FOXWAY_DEFAULT_VALUES];
												$count = count( $param );
												if( isset($function[$count]) ) {
													$function = &$function[$count];
													break;
												}
											}
											if( isset($function[FOXWAY_MIN_VALUES]) ) {
												if( $count >= $function[FOXWAY_MIN_VALUES] && isset($function['']) ) {
													$function = &$function[''];
													break;
												}
											}
										}
										throw new ExceptionFoxway($name, FOXWAY_PHP_WARNING_WRONG_PARAMETER_COUNT, $value[FOXWAY_STACK_TOKEN_LINE]);
									} while(false);

									if( is_callable($function) ) {
										try {
											wfSuppressWarnings();
											$result = $function($param);
											if( $result instanceof outPrint ) {
												$value[FOXWAY_STACK_RESULT] = $result->returnValue;
												$return[] = $result;
											} else {
												$value[FOXWAY_STACK_RESULT] = $result;
											}
											wfRestoreWarnings();
										} catch ( ExceptionFoxway $e ) {
											// @todo
											// $e add $value[FOXWAY_STACK_TOKEN_LINE]
											// $e add $name
											throw $e;
										} catch (Exception $e) {
											throw new ExceptionFoxway($name, FOXWAY_PHP_FATAL_ERROR_CALL_TO_FUNCTION, $value[FOXWAY_STACK_TOKEN_LINE]);
										}
									}else{
										throw new ExceptionFoxway($name, FOXWAY_PHP_FATAL_UNABLE_CALL_TO_FUNCTION, $value[FOXWAY_STACK_TOKEN_LINE]);
									}
								}else{
									throw new ExceptionFoxway($name, FOXWAY_PHP_FATAL_CALL_TO_UNDEFINED_FUNCTION, $value[FOXWAY_STACK_TOKEN_LINE]);
								}
							}else{ // This is object
								// @todo
							}
						}else{ // This is constant
							if( isset(self::$constants[$name]) ) {
								$value[FOXWAY_STACK_RESULT] = is_callable(self::$constants[$name]) ? self::$constants[$name]() :self::$constants[$name];
							}else{
								$value[FOXWAY_STACK_RESULT] = $name;
								// @todo send notice undefined constant
							}
						}
						break;
					case T_EMPTY:
						foreach($value[FOXWAY_STACK_PARAM] as $val) {
							if( $val[FOXWAY_STACK_COMMAND] == T_VARIABLE ) { // Example: empty($foo);
								if( !isset($thisVariables[ $val[FOXWAY_STACK_PARAM] ]) ) { // undefined variable
									continue;
								}
								$ref = &$thisVariables[ $val[FOXWAY_STACK_PARAM] ];
							}else{
								$ref = &$val[FOXWAY_STACK_RESULT];
							}
							if( isset($val[FOXWAY_STACK_ARRAY_INDEX]) ) { // Example: $foo[1]
								$vn = array_pop( $val[FOXWAY_STACK_ARRAY_INDEX] );
								foreach( $val[FOXWAY_STACK_ARRAY_INDEX] as $v ) {
									if( !isset($ref[$v]) ) { // undefined array index
										continue 2;
									}
									$ref = &$ref[$v];
								}
								if( is_string($ref) ) { // @todo it only for compatible with PHP 5.4 on PHP 5.3 @see http://www.php.net/manual/en/function.empty.php Example #2 empty() on String Offsets
									if( (is_string($vn) && $vn == (string)(int)$vn && !empty($ref[$vn]) || (!is_string($vn) && !empty($ref[$vn]))) ) {
										$value[FOXWAY_STACK_RESULT] = false;
										break 2;
									}// index is string
								}elseif( !empty($ref[$vn]) ) {
									$value[FOXWAY_STACK_RESULT] = false;
									break 2;
								}
							}elseif( !empty($ref) ) { // there is no array index and empty() returns false
								$value[FOXWAY_STACK_RESULT] = false;
								break 2;
							}
						}
						$value[FOXWAY_STACK_RESULT] = true;
						break;
					default:
						if( !isset($thisVariables[ $value[FOXWAY_STACK_PARAM][FOXWAY_STACK_PARAM] ]) ) { // Use undefined variable
							if( isset($value[FOXWAY_STACK_ARRAY_INDEX]) ) { // Example: $foo[1]++
								$thisVariables[ $value[FOXWAY_STACK_PARAM][FOXWAY_STACK_PARAM] ] = array();
							}else{
								$thisVariables[ $value[FOXWAY_STACK_PARAM][FOXWAY_STACK_PARAM] ] = null;
							}
							// @todo E_NOTICE
						}
						$ref = &$thisVariables[ $value[FOXWAY_STACK_PARAM][FOXWAY_STACK_PARAM] ];
						if( isset($value[FOXWAY_STACK_PARAM][FOXWAY_STACK_ARRAY_INDEX]) ) { // Example: $foo[1]++
							foreach( $value[FOXWAY_STACK_PARAM][FOXWAY_STACK_ARRAY_INDEX] as $v ) {
								if( $v === null ) { // Example: $foo[]
									$t = null;
									$ref[] = &$t;
									$ref = &$t;
									unset($t);
								}else{
									if( !isset($ref[$v]) ) {
										$ref[$v] = null;
										// @todo E_NOTICE
									}
									$ref = &$ref[$v];
								}
							}
						}
						switch ($value[FOXWAY_STACK_COMMAND]) {
							case T_INC:
								$ref++;
								break;
							case T_DEC:
								$ref--;
								break;
							case '=':
								// Save result in T_VARIABLE FOXWAY_STACK_RESULT, Save result in $thisVariables[variable name]
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref = $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_PLUS_EQUAL:		// +=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref += $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_MINUS_EQUAL:		// -=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref -= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_MUL_EQUAL:		// *=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref *= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_DIV_EQUAL:		// /=
								if( (int)$value[FOXWAY_STACK_PARAM_2] == 0 ) {
									throw new ExceptionFoxway(null, FOXWAY_PHP_WARNING_DIVISION_BY_ZERO, $value[FOXWAY_STACK_TOKEN_LINE]);
								}
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref /= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_CONCAT_EQUAL:	// .=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref .= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_MOD_EQUAL:		// %=
								if( (int)$value[FOXWAY_STACK_PARAM_2] == 0 ) {
									throw new ExceptionFoxway(null, FOXWAY_PHP_WARNING_DIVISION_BY_ZERO, $value[FOXWAY_STACK_TOKEN_LINE]);
								}
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref %= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_AND_EQUAL:		// &=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref &= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_OR_EQUAL:		// |=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref |= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_XOR_EQUAL:		// ^=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref ^= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_SL_EQUAL:		// <<=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref <<= $value[FOXWAY_STACK_PARAM_2];
								break;
							case T_SR_EQUAL:		// >>=
								$value[FOXWAY_STACK_PARAM][FOXWAY_STACK_RESULT] = $ref >>= $value[FOXWAY_STACK_PARAM_2];
								break;
						}
						break;
				}
			}
		} while( list($code[$i][FOXWAY_STACK_RESULT], $code, $i, $c, $loopsOwner) = array_pop($memory) );

		return $return;
	}

}

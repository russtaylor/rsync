<?php
/**
 * @Author : a.zinovyev
 * @Package: rsync
 * @License: http://www.opensource.org/licenses/mit-license.php
 */

namespace xobotyi\rsync;


/**
 * Class Command
 *
 * @package xobotyi\rsync
 */
abstract class Command
{
    /**
     * @var array
     */
    protected $OPTIONS_LIST = [];
    /**
     * @var string
     */
    private $cwd = './';
    /**
     * @var string
     */
    private $executable;
    /**
     * @var int|null
     */
    private $exitCode;
    /**
     * @var array
     */
    private $options = [];
    /**
     * @var array
     */
    private $parameters = [];
    /**
     * @var string
     */
    private $stderr;
    /**
     * @var string
     */
    private $stdout;

    /**
     * Command constructor.
     *
     * @param string $executable
     * @param string $cwd
     *
     * @throws \xobotyi\rsync\Exception\Command
     */
    public function __construct(string $executable, string $cwd = './') {
        $this->setExecutable($executable)
             ->setCWD($cwd);
    }

    /**
     * Check if given variable can be casted to the string
     *
     * @param $var
     *
     * @return bool
     */
    private static function isStringable(&$var) :bool {
        return (\is_string($var) || \is_numeric($var) || (\is_object($var) && \method_exists($var, '__toString')));
    }

    /**
     *
     *
     * @param array  $arrayToStore
     * @param string $option
     * @param        $value
     *
     * @throws \xobotyi\rsync\Exception\Command
     */
    private static function StoreOption(array &$arrayToStore, string $option, $value) :void {
        if ($value === true) {
        }
        else if ($value === false) {
            unset($arrayToStore[$option]);

            return;
        }
        else if (is_array($value)) {
            foreach ($value as &$val) {
                if (!self::isStringable($val)) {
                    throw new Exception\Command("Option {$option} has non-stringable element");
                }
            }
        }
        else if (!self::isStringable($value)) {
            throw new Exception\Command("Option {$option} got non-stringable value");
        }

        $arrayToStore[$option] = $value;
    }

    /**
     * Check if given path is an executable file
     *
     * @param string $exec path to executable
     *
     * @return bool
     */
    public static function isExecutable(string $exec) :bool {
        if (substr(strtolower(php_uname('s')), 0, 3) === 'win') {
            if (strpos($exec, '/') !== false || strpos($exec, '\\') !== false) {
                $exec = dirname($exec);
                $exec = ($exec ? $exec . ':' : '') . basename($exec);
            }

            exec('where' . ' /Q ' . escapeshellcmd($exec), $output, $code);

            return $code === 0;
        }

        return (bool)shell_exec('which' . ' ' . escapeshellcmd($exec));
    }

    /**
     * Build the command
     *
     * @return string
     */
    public function __toString() :string {
        $options    = $this->getOptionsString();
        $parameters = $this->getParametersString();

        return $this->executable . ($options ?: '') . ($parameters ?: '');
    }

    /**
     * Build the options string
     *
     * @return string
     */
    public function getOptionsString() :string {
        if (empty($this->options)) {
            return '';
        }

        $shortOptions = $longOptions = $parametrizedOptions = '';

        foreach ($this->options as $opt => $value) {
            $option       = $this->OPTIONS_LIST[$opt]['option'];
            $isLongOption = strlen($option) > 1;

            if (!($this->OPTIONS_LIST[$opt]['argument'] ?? false)) {
                $isLongOption
                    ? $longOptions .= ' --' . $option
                    : $shortOptions .= $option;

                continue;
            }

            $option = ($isLongOption ? ' --' : ' -') . $option;

            if ($this->OPTIONS_LIST[$opt]['repeatable'] ?? false) {
                foreach ($value as $val) {
                    $parametrizedOptions .= $option . ' ' . escapeshellarg($val);
                }

                continue;
            }

            $parametrizedOptions .= $option . ' ' . escapeshellarg($value);
        }

        $shortOptions        = $shortOptions ?: '';
        $longOptions         = $longOptions ?: '';
        $parametrizedOptions = $parametrizedOptions ?: '';

        return ($shortOptions ? ' -' . $shortOptions : '')
               . $longOptions
               . $parametrizedOptions;
    }

    /**
     * Build the parameters string
     *
     * @return string
     */
    public function getParametersString() :string {
        if (empty($this->parameters)) {
            return '';
        }

        $parametersStr = '';

        foreach ($this->parameters as $value) {
            $parametersStr .= ' ' . $value;
        }

        return $parametersStr;
    }

    /**
     * Add single parameter to the string. New parameter will be appended to the end.
     *
     * @param $parameter
     *
     * @return $this
     * @throws \xobotyi\rsync\Exception\Command
     */
    public function addParameter($parameter) {
        if (!self::isStringable($parameter)) {
            throw new Exception\Command("Got non-stringable parameter");
        }

        $this->parameters[] = $parameter;

        return $this;
    }

    /**
     * Empty the parameters.
     *
     * @return \xobotyi\rsync\Command
     */
    public function clearParameters() :self {
        $this->parameters = [];

        return $this;
    }

    /**
     * Execute the command by opening the child process.
     *
     * @return \xobotyi\rsync\Command
     * @throws \xobotyi\rsync\Exception\Command
     */
    public function execute() :self {
        $this->exitCode = 1;    // exit 0 on ok
        $this->stdout   = '';   // output of the command
        $this->stderr   = '';   // errors during execution

        $descriptor = [
            0 => ["pipe", "r"],    // stdin is a pipe that the child will read from
            1 => ["pipe", "w"],    // stdout is a pipe that the child will write to
            2 => ["pipe", "w"]     // stderr is a pipe
        ];

        $proc = proc_open((string)$this, $descriptor, $pipes, $this->cwd);

        if ($proc === false) {
            throw new Exception\Command("Unable to execute command '{$this}'");
        }

        $this->stdout = trim(stream_get_contents($pipes[1]));
        $this->stderr = trim(stream_get_contents($pipes[2]));

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->exitCode = proc_close($proc);

        return $this;
    }

    /**
     * Return the current working directory.
     *
     * @return string
     */
    public function getCWD() :string {
        return $this->cwd;
    }

    /**
     * Set the current working directory. Will be used during the command execution.
     *
     * @param string $cwd CWD path
     *
     * @return \xobotyi\rsync\Command
     */
    public function setCWD(string $cwd) :self {
        $this->cwd = $cwd;

        return $this;
    }

    /**
     * Get the executable path
     *
     * @return string
     */
    public function getExecutable() :string {
        return $this->executable;
    }

    /**
     * Set the executable path
     *
     * @param string $executable
     *
     * @return $this
     * @throws \xobotyi\rsync\Exception\Command
     */
    public function setExecutable(string $executable) {
        if (!($executable = \trim($executable))) {
            throw new Exception\Command("Executable path must be a valuable string");
        }

        if (!self::isExecutable($executable)) {
            throw new Exception\Command("{$executable} is not executable");
        }

        $this->executable = $executable;

        return $this;
    }

    /**
     * Return the last command execution exitCode, null if command hasn't been executed yet
     *
     * @return string|null
     */
    public function getExitCode() :?string {
        return $this->exitCode;
    }

    /**
     * Get the options array
     *
     * @return array
     */
    public function getOptions() :array {
        return $this->options;
    }

    /**
     * Set the bunch of option
     *
     * @param array $options an array of options, where array keys are options names and array values are options values
     *
     * @return $this
     * @throws \xobotyi\rsync\Exception\Command
     */
    public function setOptions(array $options) {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }

        return $this;
    }

    /**
     * Set the command's option.
     *
     * @param string $optName option name (see the constants list for options names and its descriptions)
     * @param mixed  $val     option value, by default is true, if has false value - option wil be removed from result
     *                        command.
     *
     * @return $this
     * @throws \xobotyi\rsync\Exception\Command
     */
    public function setOption(string $optName, $val = true) {
        if (!($this->OPTIONS_LIST[$optName] ?? false)) {
            throw new Exception\Command("Option {$optName} is not supported");
        }

        if (!is_bool($val) && !($this->OPTIONS_LIST[$optName]['argument'] ?? false)) {
            throw new Exception\Command("Option {$optName} can not have any argument");
        }

        if (is_array($val) && !($this->OPTIONS_LIST[$optName]['repeatable'] ?? false)) {
            throw new Exception\Command("Option {$optName} is not repeatable (its value cant be an array)");
        }

        self::StoreOption($this->options, $optName, $val);

        return $this;
    }

    /**
     * Return the parameters of command
     *
     * @return array
     */
    public function getParameters() :array {
        return $this->parameters;
    }

    /**
     * Set the bunch of command parameters
     *
     * @param array $parameters array of strings to set as command parameters, each string will be appended to the end
     *                          of command
     *
     * @return $this
     * @throws \xobotyi\rsync\Exception\Command
     */
    public function setParameters(array $parameters) {
        $params = [];
        foreach ($parameters as &$value) {
            if (!self::isStringable($value)) {
                throw new Exception\Command("Got non-stringable parameter");
            }

            $params[] = (string)$value;
        }

        $this->parameters = $params;

        return $this;
    }

    /**
     * Return the last command execution stderr, null if command hasn't been executed yet
     *
     * @return string|null
     */
    public function getStderr() :?string {

        return $this->stderr;
    }

    /**
     * Return the last command execution stdout, null if command hasn't been executed yet
     *
     * @return string|null
     */
    public function getStdout() :?string {
        return $this->stdout;
    }
}
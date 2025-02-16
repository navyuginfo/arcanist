<?php

/**
 * A linter for JSON files.
 */
final class ArcanistJSONLintLinter extends ArcanistExternalLinter {

  public function getInfoName() {
    return 'JSON Lint';
  }

  public function getInfoURI() {
    return 'https://github.com/zaach/jsonlint';
  }

  public function getInfoDescription() {
    return pht('Use `jsonlint` to detect syntax errors in JSON files.');
  }

  public function getLinterName() {
    return 'JSON';
  }

  public function getLinterConfigurationName() {
    return 'jsonlint';
  }

  public function getDefaultBinary() {
    return 'jsonlint';
  }

  public function getVersion() {
    // NOTE: `jsonlint --version` returns a non-zero exit status.
    list($err, $stdout) = exec_manual(
      '%C --version',
      $this->getExecutableCommand());

    $matches = array();
    if (preg_match('/^(?P<version>\d+\.\d+\.\d+)$/', $stdout, $matches)) {
      return $matches['version'];
    } else {
      return false;
    }
  }

  public function getInstallInstructions() {
    return pht('Install jsonlint using `npm install -g jsonlint`.');
  }

  public function shouldExpectCommandErrors() {
    return true;
  }

  public function supportsReadDataFromStdin() {
    return true;
  }

  protected function getMandatoryFlags() {
    return array(
      '--compact',
    );
  }

  protected function parseLinterOutput($path, $err, $stdout, $stderr) {
    $lines = phutil_split_lines($stderr, false);

    $messages = array();
    foreach ($lines as $line) {
      $matches = null;
      $match = preg_match(
        '/^(?:(?<path>.+): )?'.
        'line (?<line>\d+), col (?<column>\d+), '.
        '(?<description>.*)$/',
        $line,
        $matches);

      if ($match) {
        $message = new ArcanistLintMessage();
        $message->setPath($path);
        $message->setLine($matches['line']);
        $message->setChar($matches['column']);
        $message->setCode($this->getLinterName());
        $message->setDescription(ucfirst($matches['description']));
        $message->setSeverity(ArcanistLintSeverity::SEVERITY_ERROR);

        $messages[] = $message;
      }
    }

    if ($err && !$messages) {
      return false;
    }

    return $messages;
  }
}

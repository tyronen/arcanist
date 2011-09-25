<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Uses "PyLint" to detect various errors in Python code. To use this linter,
 * you must install pylint and configure which codes you want to be reported as
 * errors, warnings and advice.
 *
 * You should be able to install pylint with ##sudo easy_install pylint##. If
 * your system is unusual, you can manually specify the location of pylint and
 * its dependencies by configuring these keys in your .arcrc:
 *
 *   lint.pylint.prefix
 *   lint.pylint.logilab_astng.prefix
 *   lint.pylint.logilab_common.prefix
 *
 * You can specify additional command-line options to pass to PyLint by setting
 * this key:
 *
 *   lint.pylint.options
 *
 * Now, configure which warnings and errors you want PyLint to raise by setting
 * these keys:
 *
 *   lint.pylint.codes.error
 *   lint.pylint.codes.warning
 *   lint.pylint.codes.advice
 *
 * Set these to regular expressions -- for instance, if you want to raise all
 * PyLint errors as Arcanist errors, set this for ##lint.pylint.codes.error##:
 *
 *    ^E.*
 *
 * You can also match more granular errors:
 *
 *    ^E(0001|0002)$
 *
 * You can also provide a list of regular expressions.
 *
 * @group linter
 */
class ArcanistPyLintLinter extends ArcanistLinter {

  private function getMessageCodeSeverity($code) {

    $working_copy = $this->getEngine()->getWorkingCopy();

    // The config file defines how PyLint message codes translate to
    // arcanist severities.  The config options provide regex's to
    // match against the message codes generated by PyLint.  Severity's
    // are matched in the order of errors, warnings, then advice.
    // The first severity that matches, in that order, is returned.
    $error_regexp   = $working_copy->getConfig('lint.pylint.codes.error');
    $warning_regexp = $working_copy->getConfig('lint.pylint.codes.warning');
    $advice_regexp  = $working_copy->getConfig('lint.pylint.codes.advice');

    if (!$error_regexp && !$warning_regexp && !$advice_regexp) {
      throw new ArcanistUsageException(
        "You are invoking the PyLint linter but have not configured any of ".
        "'lint.pylint.codes.error', 'lint.pylint.codes.warning', or ".
        "'lint.pylint.codes.advice'. Consult the documentation for ".
        "ArcanistPyLintLinter.");
    }

    $code_map = array(
      ArcanistLintSeverity::SEVERITY_ERROR    => $error_regexp,
      ArcanistLintSeverity::SEVERITY_WARNING  => $warning_regexp,
      ArcanistLintSeverity::SEVERITY_ADVICE   => $advice_regexp,
    );

    foreach ($code_map as $sev => $codes) {
      if ($codes === null) {
        continue;
      }
      if (!is_array($codes)) {
        $codes = array($codes);
      }
      foreach ($codes as $code_re) {
        if (preg_match("/{$code_re}/", $code)) {
          return $sev;
        }
      }
    }

    // If the message code doesn't match any of the provided regex's,
    // then just disable it.
    return ArcanistLintSeverity::SEVERITY_DISABLED;
  }

  private function getPyLintPath() {
    $pylint_bin = "pylint";

    // Use the PyLint prefix specified in the config file
    $working_copy = $this->getEngine()->getWorkingCopy();
    $prefix = $working_copy->getConfig('lint.pylint.prefix');
    if ($prefix !== null) {
      $pylint_bin = $prefix."/bin/".$pylint_bin;
    }

    if (!Filesystem::pathExists($pylint_bin)) {

      list($err) = exec_manual('which %s', $pylint_bin);
      if ($err) {
        throw new ArcanistUsageException(
          "PyLint does not appear to be installed on this system. Install it ".
          "(e.g., with 'sudo easy_install pylint') or configure ".
          "'lint.pylint.prefix' in your .arcconfig to point to the directory ".
          "where it resides.");
      }
    }

    return $pylint_bin;
  }

  private function getPyLintPythonPath() {
    // Get non-default install locations for pylint and its dependencies
    // libraries.
    $working_copy = $this->getEngine()->getWorkingCopy();
    $prefixes = array(
      $working_copy->getConfig('lint.pylint.prefix'),
      $working_copy->getConfig('lint.pylint.logilab_astng.prefix'),
      $working_copy->getConfig('lint.pylint.logilab_common.prefix'),
    );

    // Add the libraries to the python search path
    $python_path = array();
    foreach ($prefixes as $prefix) {
      if ($prefix !== null) {
        $python_path[] = $prefix.'/lib/python2.6/site-packages';
      }
    }

    $python_path[] = '';
    return implode(":", $python_path);
  }

  private function getPyLintOptions() {
    // Options to pass the PyLint
    // - '-rn': don't print lint report/summary at end
    // - '-iy': show message codes for lint warnings/errors
    $options = array('-rn',  '-iy');

    // Add any options defined in the config file for PyLint
    $working_copy = $this->getEngine()->getWorkingCopy();
    $config_options = $working_copy->getConfig('lint.pylint.options');
    if ($config_options !== null) {
      $options += $config_options;
    }

    return implode(" ", $options);
  }

  public function willLintPaths(array $paths) {
    return;
  }

  public function getLinterName() {
    return 'PyLint';
  }

  public function getLintSeverityMap() {
    return array();
  }

  public function getLintNameMap() {
    return array();
  }

  public function lintPath($path) {
    $pylint_bin = $this->getPyLintPath();
    $python_path = $this->getPyLintPythonPath();
    $options = $this->getPyLintOptions();
    $path_on_disk = $this->getEngine()->getFilePathOnDisk($path);

    try {
      list($stdout, $_) = execx(
          "/usr/bin/env PYTHONPATH=%s\$PYTHONPATH ".
            "{$pylint_bin} {$options} {$path_on_disk}",
          $python_path);
    } catch (CommandException $e) {
      // PyLint will return a non-zero exit code if warnings/errors are found.
      // Therefore we detect command failure by checking that the stderr is
      // some non-expected value.
      if ($e->getStderr() !== "No config file found, ".
                              "using default configuration\n") {
        throw $e;
      }

      $stdout = $e->getStdout();
    }

    $lines = explode("\n", $stdout);
    $messages = array();
    foreach ($lines as $line) {
      $matches = null;
      if (!preg_match('/([A-Z]\d+): *(\d+): *(.*)$/', $line, $matches)) {
        continue;
      }
      foreach ($matches as $key => $match) {
        $matches[$key] = trim($match);
      }

      $message = new ArcanistLintMessage();
      $message->setPath($path);
      $message->setLine($matches[2]);
      $message->setCode($matches[1]);
      $message->setName($this->getLinterName()." ".$matches[1]);
      $message->setDescription($matches[3]);
      $message->setSeverity($this->getMessageCodeSeverity($matches[1]));
      $this->addLintMessage($message);
    }
  }

}
<?php

/**
 * GNATtest Result Parser
 */
final class GNATtestResultParser {

  /**
   * Parse test results from provided input and return an array
   * of ArcanistUnitTestResult.
   *
   * @param string  $test_results  String containing test results
   *
   * @return array ArcanistUnitTestResult
   */
  public function parseTestResults($test_results) {
    if (!strlen($test_results)) {
      throw new Exception(
        'test_results argument to parseTestResults must not be empty');
    }

    $results = array();
    foreach(preg_split('/((\r?\n)|(\r\n?))/', $test_results) as $line) {
      if (strpos($line, 'PASSED')) {
        $r = $this->createArcanistResult(
          $this->getTestname($line), ArcanistUnitTestResult::RESULT_PASS,
          NULL, $this->getDuration($line));
        if($r)
          $results[] = $r;
        continue;
      }
      if (strpos($line, 'FAILED')) {
        $r = $this->createArcanistResult(
          $this->getTestname($line), ArcanistUnitTestResult::RESULT_FAIL,
          $this->getReason(false, $line), NULL);
        if($r)
          $results[] = $r;
        continue;
      }
      if (strpos($line, 'CRASHED')) {
        $r = $this->createArcanistResult(
          $this->getTestname($line), ArcanistUnitTestResult::RESULT_BROKEN,
          $this->getReason(true, $line), NULL);
        if($r)
          $results[] = $r;
        continue;
      }
    }

    if (!count($results)) {
      throw new Exception('No test results found');
    }
    return $results;
  }

  /**
   * Create ArcanistResult from given parameters.
   *
   * @param string  $name      Name of the test
   * @param string  $status    Status of the test
   * @param string  $data      Optional user data (reason)
   * @param string  $duration  Optional test duration in seconds
   *
   * @return ArcanistUnitTestResult
   */
  private function createArcanistResult($name, $status, $data, $duration) {
    /* Get spec and determine if this is a file in the current git project */
    $spec = $this->getFilepath($name, False);
    if (!phutil_nonempty_string($spec))
      return null;
    if (!$this->isCheckedIn($spec))
      return null;

    $result = new ArcanistUnitTestResult();
    $result->setResult($status);
    $result->setName($name);
    if (phutil_nonempty_string($data)) {
      $result->setUserData($data."\n");
    }
    if (phutil_nonempty_string($duration)) {
      $result->setDuration(floatval($duration));
    }

    /* Store body filename in extra data. */
    $data = array();
    $data[0] = $this->getFilepath($name, True);
    $result->setExtraData($data);

    return $result;
  }

  /**
   * Extract the testname from the given input string. Raise exception if
   * the testname could not be found.
   *
   * @param string  $str  Input line to parse
   *
   * @return string
   */
  private function getTestname($str) {
    if (preg_match('/.*.ad[s|b]:[0-9]+:[0-9]/', $str, $testname)) {
      return $testname[0];
    }
    throw new Exception('getTestname failed for "'.$str.'"');
  }

  /**
   * Check if given file is known to git.
   *
   * @param string  $path  Path of file to check
   *
   * @return bool True if file is checked in
   */
  function isCheckedIn($path) {
    $output = [];
    $returnVar = 0;
    exec("git ls-files --error-unmatch " . escapeshellarg($path) . " 2>&1", $output, $returnVar);
    return $returnVar === 0;
  }

  /**
   * Return glob brace string to look for Ada package bodies / specs.
   * Only consider files in src directories (up to two-level nested).
   *
   * @return string
   */
  private function getBrace() {
    return "{src/,*/src/,*/*/src/}";
  }

  /**
   * Return file path of source spec or body for given testname. Raise exception
   * if the filename could not be extracted and return empty string if the
   * source spec of body is not in the supported src directories
   *
   * @param string  $name  Name of a test
   * @param bool    $body  Whether to look for a spec or body
   *
   * @return string
   */
  private function getFilepath($name, $body) {
    if (preg_match('/^(.*?).ads/', $name, $filename)) {
      if ($body)
        $filename = str_replace('.ads', '.adb', $filename[0]);
      else
        $filename = $filename[0];
      $brace = $this->getBrace();
      $bodies = glob("$brace$filename", GLOB_BRACE);
      if (empty($bodies) === false) {
        return $bodies[0];
      }
      return "";
    }
    throw new Exception('getFilepath failed for "'.$name.'"');
  }

  /**
   * Extract test duration from the given input string.
   * Raise exception if the duration could not be extracted.
   *
   * @param string  $str  Input line to parse
   *
   * @return string
   */
  private function getDuration($str) {
    if (preg_match('/[0-9]+\.[0-9]+/', $str, $duration)) {
      return $duration[0];
    }
    throw new Exception('getDuration failed for "'.$str.'"');
  }
  /**
   * Extract the reason for a failing/crashing test from the given input string.
   * Raise exception if the reason could not be extracted.
   *
   * @param boolean  $crashed  Whether the test crashed
   * @param string   $str      Input line to parse
   *
   * @return string
   */
  private function getReason($crashed, $str) {
    $pattern = 'FAILED';

    if ($crashed) {
      $pattern = 'CRASHED';
    }

    $result = substr($str, strpos($str, $pattern) + strlen($pattern) + 2);
    if (!phutil_nonempty_string($result)) {
      throw new Exception('getReason failed for "'.$str.'"');
    }
    return $result;
  }
}

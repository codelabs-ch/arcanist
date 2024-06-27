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
        $results[] = $this->createArcanistResult(
          $this->getTestname($line), ArcanistUnitTestResult::RESULT_PASS,
          NULL, $this->getDuration($line));
        continue;
      }
      if (strpos($line, 'FAILED')) {
        $results[] = $this->createArcanistResult(
          $this->getTestname($line), ArcanistUnitTestResult::RESULT_FAIL,
          $this->getReason(false, $line), NULL);
        continue;
      }
      if (strpos($line, 'CRASHED')) {
        $results[] = $this->createArcanistResult(
          $this->getTestname($line), ArcanistUnitTestResult::RESULT_BROKEN,
          $this->getReason(true, $line), NULL);
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
    $data[0] = $this->getFilepath($name);
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
   * Return file path of source body for given testname. Raise exception if the
   * filename could not be extracted and return empty string if the source body
   * is not in the src directory.
   *
   * @param string  $name  Name of a test
   *
   * @return string
   */
  private function getFilepath($name) {
    if (preg_match('/^(.*?).ads/', $name, $filename)) {
      $filename = str_replace('.ads', '.adb', $filename[0]);

      /* Only consider files in src directory */
      $bodies = glob('tools/*/src/'.$filename);
      if (empty($bodies) === false) {
        return $bodies[0];
      }
      $bodies = glob('src/'.$filename);
      if (empty($bodies) === false) {
        return $bodies[0];
      }
      /* For tools/ used via submodule */
      $bodies = glob('*/src/'.$filename);
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

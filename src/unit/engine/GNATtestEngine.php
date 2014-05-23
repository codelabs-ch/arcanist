<?php

/**
 * Unit test engine wrapper for GNATtest.
 */
final class GNATtestEngine extends ArcanistUnitTestEngine {

  private $covCache = array();

  public function run() {
    $projectRoot = $this->getWorkingCopy()->getProjectRoot();

    echo "Cleaning '$projectRoot' before test execution ... ";
    exec("cd $projectRoot && make clean");
    echo "OK\n";

    echo "Running tests ...\n";
    $future = new ExecFuture('%C', 'GNATTEST_EXIT=off make tests');
    list($stdout, $stderr) = $future->resolvex();

    $parser = new GNATtestResultParser();
    $results = $parser->parseTestResults($stdout);

    $future = new ExecFuture('%C', 'git diff');
    list($stdout, $stderr) = $future->resolvex();
    if (!empty($stdout) || !empty($stderr)) {
      throw new Exception('git diff detected changes after test run');
    }

    if ($this->getEnableCoverage() !== false) {
      $this->addCoverage($results);
    }

    return $results;
  }

  /**
   * Read coverage report produced by gcov and add it to the test results.
   * Inspired by MobileUnitTestEngine.php, thanks to the authors.
   *
   * @param array $results Unit test results
   *
   */
  private function addCoverage($results) {

    $cmd = 'find . -name *.gcda -exec gcov {} \; 2>&1';
    exec($cmd, $output, $exit_status);

    if ($exit_status > 0) {
      throw new Exception('Command "'.$cmd.'" failed: '.implode($output));
    }

    foreach ($results as $result) {
      $coverage = array();

      $path = $result->getExtraData()[0];

      if (!strlen($path)) {
        continue;
      }

      if (array_key_exists($path, $this->covCache) !== false) {
        $coverage[$path] = $this->covCache[$path];
      } else {
        $body = basename($path);
        $gcov_filename = $body.'.gcov';
        $str = '';

        if (!file_exists($gcov_filename)) {
          throw new Exception('Coverage file "'.$gcov_filename.'" does not exist');
        }

        foreach (file($gcov_filename) as $gcov_line) {
          $gcov_matches = array();
          if (preg_match('/.*?(\S|\d+):.*?(\d+)/is', $gcov_line, $gcov_matches)
            && $gcov_matches[2][0] > 0
          ) {
            if ($gcov_matches[1][0] === '#' || $gcov_matches[1][0] === '=') {
              $str .= 'U';
            } else if ($gcov_matches[1][0] === '-') {
              $str .= 'N';
            } else if ($gcov_matches[1][0] > 0) {
              $str .= 'C';
            } else {
              $str .= 'N';
            }
          }
        }
        $coverage[$path] = $str;
        $this->covCache[$path] = $str;
      }
      $result->setCoverage($coverage);
    }

    foreach (glob('*.gcov') as $file) {
      unlink($file);
    }

    return $coverage;
  }
}

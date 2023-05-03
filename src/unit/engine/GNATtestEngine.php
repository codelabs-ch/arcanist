<?php

/**
 * Unit test engine wrapper for GNATtest.
 */
final class GNATtestEngine extends ArcanistUnitTestEngine {

  private $covCache = array();

  public function run() {
    $projectRoot = $this->getWorkingCopy()->getProjectRoot();

    $cpuinfo = file_get_contents('/proc/cpuinfo');
    preg_match_all('/^processor/m', $cpuinfo, $matches);
    $cpus = count($matches[0]);

    echo "Cleaning '$projectRoot' before test execution...\n";
    exec("cd $projectRoot && make -j $cpus clean 2>&1", $output);

    echo "Running test build...\n";
    exec("make -j$cpus NO_PROOF=1 2>&1", $output, $return);
    if ($return) {
      echo implode("\n", array_slice($output, -35))."\n\n";
      throw new Exception("Test build of '$projectRoot' failed");
    }

    echo "Running tests...\n";
    $future = new ExecFuture('%C', 'GNATTEST_EXIT=off make tests');
    list($stdout, $stderr) = $future->resolvex();

    $parser = new GNATtestResultParser();
    $results = $parser->parseTestResults($stdout);

    $future = new ExecFuture('%C', 'git status --porcelain');
    list($stdout, $stderr) = $future->resolvex();
    if (!empty($stdout) || !empty($stderr)) {
      throw new Exception('Workdir changes detected after test run');
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

    $cmd = 'gcov `find . -name "*.gcda" | tr "\n" " " 2>&1`';
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

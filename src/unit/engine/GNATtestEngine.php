<?php

/**
 * Unit test engine wrapper for GNATtest.
 */
final class GNATtestEngine extends ArcanistUnitTestEngine {

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

    return $results;
  }
}

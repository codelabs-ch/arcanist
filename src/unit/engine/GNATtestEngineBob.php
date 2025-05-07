<?php

/**
 * Unit test engine wrapper for GNATtest.
 */
final class GNATtestEngineBob extends ArcanistUnitTestEngine {

  private $covCache = array();

  public function run() {
    $projectRoot = $this->getWorkingCopy()->getProjectRoot();
    // TODO: Make this configurable by creating a meta information
    //       file for bob-aware arc unit in bob recipe scm checkout step.
    //       Care must be taken that this meta file is not part of
    //       the hash.
    //       https://github.com/BobBuildTool/bob/blob/master/pym/bob/utils.py#L408
    $bobRoot = realpath($projectRoot . "/../../../../../../");
    $bobRecipe = "unittests";

    echo "Checking bob recipe...\n";
    $future = new ExecFuture('%C', "bob -C $bobRoot ls $bobRecipe");
    try {
      $future->resolvex();
    } catch (Exception $e) {
      throw new Exception("Unable to find bob '$bobRecipe' recipe in '$bobRoot': {$e->getMessage()}");
    }

    echo "Removing existing build dirs...\n";
    // $future = new ExecFuture
    //   ('%C', "bob -C $bobRoot query-path --dev-sandbox -f {build} $bobRecipe/*-test");
    $future = new ExecFuture
      ('%C', "bob -C $bobRoot query-path -f {build} $bobRecipe/*-test");
    list($stdout, $stderr) = $future->resolvex();
    foreach (explode("\n", $stdout) as $workspace) {
      if (!strlen($workspace))
        break;
      $pkg = realpath("{$bobRoot}/{$workspace}/..");
      if (is_dir($pkg))
        Filesystem::remove($pkg);
    }

    echo "Running unittests...\n";
    // $future = new ExecFuture('%C', "bob -C $bobRoot dev --dev-sandbox $bobRecipe");
    $future = new ExecFuture('%C', "bob -C $bobRoot dev $bobRecipe");
    $future->resolvex();

    echo "Reading result logs...\n";
    $buffer = "";
    // Build dirs can only be collected if built/existing.
    // Therefore this step must come after 'dev unittests'.
    $future = new ExecFuture
      ('%C', "bob -C $bobRoot query-path  -f {build} $bobRecipe/*-test");
    // $future = new ExecFuture
    //   ('%C', "bob -C $bobRoot query-path --dev-sandbox -f {build} $bobRecipe/*-test");
    list($stdout, $stderr) = $future->resolvex();
    foreach (explode("\n", $stdout) as $workspace) {
      if (!strlen($workspace))
        break;
      $pkg = realpath("{$bobRoot}/{$workspace}/..");
      $logfile = "{$pkg}/log.txt";
      $buffer .= Filesystem::readFile($logfile);
    }

    $parser = new GNATtestResultParser();
    $results = $parser->parseTestResults($buffer);

    $future = new ExecFuture
      ('%C', "bob -C $bobRoot query-path --fail -f {dist} $bobRecipe");
    // $future = new ExecFuture
    //   ('%C', "bob -C $bobRoot query-path --fail --dev-sandbox -f {dist} $bobRecipe");
    list($stdout, $stderr) = $future->resolvex();

    if ($this->getEnableCoverage() !== false) {
      $this->addCoverage($results, trim("$bobRoot/$stdout"));
    }

    return $results;
  }

  /**
   * Read coverage report produced by gcov and add it to the test results.
   * Inspired by MobileUnitTestEngine.php, thanks to the authors.
   *
   * The gcov files are expected to be found in the provided directory.
   *
   * @param array $results Unit test results
   *
   */
  private function addCoverage($results, $gcov_path) {
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
        $cmd = "find $gcov_path -name $gcov_filename";
        $output = [];
        exec($cmd, $output, $exit_status);

        if ($exit_status > 0)
          throw new Exception("Coverage file '$gcov_filename' does not exist");
        if (count($output) != 1)
          throw new Exception("Unexpected find result count for '$gcov_filename': " . count($output));
        $gcov_filename = current($output);

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

    return $coverage;
  }
}

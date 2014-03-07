<?php

/**
 * Very basic unit test engine wrapper for Ahven.
 */
final class AhvenTestEngine extends ArcanistBaseUnitTestEngine {

  public function run() {
    $dir = Filesystem::createTemporaryDirectory();

    $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();

    $cmd_line = csprintf('make tests JUNIT_DIR=%s -C %s', $dir,
      $this->projectRoot);
    $future = new ExecFuture('%C', $cmd_line);
    list($stdout, $stderr) = $future->resolvex();

    $parser = new ArcanistXUnitTestResultParser();
    $results = array();

    foreach (Filesystem::listDirectory($dir, $hidden = false) as $file) {
      $res = $parser->parseTestResults (Filesystem::readFile($dir.'/'.$file));
      $results = array_merge($results, $res);
    }

    Filesystem::remove($dir);
    return $results;
  }
}

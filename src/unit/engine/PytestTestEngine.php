<?php

/**
 * Very basic 'py.test' unit test engine wrapper.
 */
final class PytestTestEngine extends ArcanistUnitTestEngine {

  private $projectRoot;

  public function run() {
    $working_copy = $this->getWorkingCopy();
    $this->projectRoot = $working_copy->getProjectRoot();

    $junit_tmp = new TempFile();
    $cover_tmp = new TempFile();

    $future = $this->buildTestFuture($junit_tmp, $cover_tmp);
    list($err, $stdout, $stderr) = $future->resolve();

    if (!Filesystem::pathExists($junit_tmp)) {
      throw new CommandException(
        pht('Command failed with error #%s!', $err),
        $future->getCommand(),
        $err,
        $stdout,
        $stderr);
    }

    $future = new ExecFuture('coverage xml -o %s', $cover_tmp);
    $future->setCWD($this->projectRoot);
    $future->resolvex();

    return $this->parseTestResults($junit_tmp, $cover_tmp);
  }

  public function buildTestFuture($junit_tmp, $cover_tmp) {
    $paths = $this->getPaths();

    $cmd_line = csprintf('pytest --junit-xml=%s', $junit_tmp);

    if ($this->getEnableCoverage() !== false) {
      $cmd_line = csprintf(
        'coverage run -m %C',
        $cmd_line);
    }

    return new ExecFuture('%C', $cmd_line);
  }

  public function parseTestResults($junit_tmp, $cover_tmp) {
    $parser = new ArcanistXUnitTestResultParser();
    $results = $parser->parseTestResults(
      Filesystem::readFile($junit_tmp));

    if ($this->getEnableCoverage() !== false) {
      $coverage_report = $this->readCoverage($cover_tmp);
      foreach ($results as $result) {
          $result->setCoverage($coverage_report);
      }
    }

    return $results;
  }

  public function readCoverage($path) {
    $coverage_data = Filesystem::readFile($path);
    if (empty($coverage_data)) {
       return array();
    }

    $coverage_dom = new DOMDocument();
    $coverage_dom->loadXML($coverage_data);

    $paths = $this->getPaths();
    $reports = array();
    $classes = $coverage_dom->getElementsByTagName('class');
    $sources = $coverage_dom->getElementsByTagName('source');

    foreach ($classes as $class) {
      $filename = $class->getAttribute('filename');

      // find file in given sources.
      foreach ($sources as $source) {
        $src_dir = $source->nodeValue;
        $to_check = $src_dir . "/" . $filename;
        if (file_exists($to_check)) {
          $absolute_path = $to_check;
          break;
        }
      }

      if (!isset($absolute_path) || !strlen($absolute_path))
        throw new Exception("File path for '$filename' could not be determined");

      if (!file_exists($absolute_path))
        throw new Exception("Absolute path '$absolute_path' does not exist");

      $in_path = False;
      foreach ($paths as $path) {
        if (str_ends_with($absolute_path, $path)) {
          $in_path = True;
          $relative_path = $path;
          break;
        }
      }
      if (!$in_path)
        continue;

      // get total line count in file
      $line_count = count(file($absolute_path));

      $coverage = '';
      $start_line = 1;
      $lines = $class->getElementsByTagName('line');
      for ($ii = 0; $ii < $lines->length; $ii++) {
        $line = $lines->item($ii);

        $next_line = (int)$line->getAttribute('number');
        for ($start_line; $start_line < $next_line; $start_line++) {
            $coverage .= 'N';
        }

        if ((int)$line->getAttribute('hits') == 0) {
            $coverage .= 'U';
        } else if ((int)$line->getAttribute('hits') > 0) {
            $coverage .= 'C';
        }

        $start_line++;
      }

      if ($start_line < $line_count) {
        foreach (range($start_line, $line_count) as $line_num) {
          $coverage .= 'N';
        }
      }

      $reports[$relative_path] = $coverage;
    }

    return $reports;
  }

}

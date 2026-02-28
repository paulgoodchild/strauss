<?php

namespace BrianHenryIE\Strauss\Tests\Integration;

use BrianHenryIE\Strauss\IntegrationTestCase;

/**
 * @coversNothing
 */
class ScanningParityFeatureTest extends IntegrationTestCase
{
    public function test_scanning_changes_do_not_change_transformed_output(): void
    {
        $dependencyComposerJson = <<<'JSON'
{
  "name": "local/scan-fixture",
  "version": "1.0.0",
  "autoload": {
    "psr-4": {
      "LocalScan\\": "src/"
    },
    "files": [
      "src/globals.php",
      "src/multi.php"
    ]
  }
}
JSON;

        $namedPhp = <<<'PHP'
<?php
namespace LocalScan;

class NamedClass {}
PHP;

        $globalsPhp = <<<'PHP'
<?php
namespace {
    class GlobalClass {}

    function global_helper() {
        return 'ok';
    }

    const GLOBAL_CONST = 'global';
}
PHP;

        $consumerPhp = <<<'PHP'
<?php
namespace LocalScan;

class Consumer {
    public function run(): array {
        $class = new \GlobalClass();
        $value = \global_helper();
        $const = \GLOBAL_CONST;
        return [$class::class, $value, $const];
    }
}
PHP;

        $multiPhp = <<<'PHP'
<?php
namespace LocalScan\PartA {
    class Alpha {}
}

namespace {
    function shared_helper() {
        return true;
    }
}

namespace LocalScan\PartB {
    class Beta {}
}
PHP;

        $projectComposerJson = <<<'JSON'
{
  "name": "local/scanning-parity-project",
  "minimum-stability": "dev",
  "prefer-stable": true,
  "repositories": [
    {
      "type": "path",
      "url": "../scan-fixture",
      "options": {
        "symlink": false
      }
    }
  ],
  "require": {
    "local/scan-fixture": "*"
  },
  "extra": {
    "strauss": {
      "namespace_prefix": "MyPrefix\\",
      "classmap_prefix": "MyPrefix_",
      "functions_prefix": "myprefix_",
      "constants_prefix": "MYPREFIX_",
      "target_directory": "vendor-prefixed"
    }
  }
}
JSON;

        mkdir($this->testsWorkingDir . 'scan-fixture/src', 0777, true);
        $this->getFileSystem()->write($this->testsWorkingDir . 'scan-fixture/composer.json', $dependencyComposerJson);
        $this->getFileSystem()->write($this->testsWorkingDir . 'scan-fixture/src/NamedClass.php', $namedPhp);
        $this->getFileSystem()->write($this->testsWorkingDir . 'scan-fixture/src/globals.php', $globalsPhp);
        $this->getFileSystem()->write($this->testsWorkingDir . 'scan-fixture/src/Consumer.php', $consumerPhp);
        $this->getFileSystem()->write($this->testsWorkingDir . 'scan-fixture/src/multi.php', $multiPhp);

        mkdir($this->testsWorkingDir . 'project', 0777, true);
        $this->getFileSystem()->write($this->testsWorkingDir . 'project/composer.json', $projectComposerJson);

        chdir($this->testsWorkingDir . 'project');
        exec('composer install', $composerInstallOutput, $composerInstallExitCode);
        $this->assertEquals(0, $composerInstallExitCode, implode(PHP_EOL, $composerInstallOutput));

        $exitCode = $this->runStrauss($output);
        $this->assertEquals(0, $exitCode, $output);

        $namedOutput = $this->getFileSystem()->read(
            $this->testsWorkingDir . 'project/vendor-prefixed/local/scan-fixture/src/NamedClass.php'
        );
        $globalsOutput = $this->getFileSystem()->read(
            $this->testsWorkingDir . 'project/vendor-prefixed/local/scan-fixture/src/globals.php'
        );
        $consumerOutput = $this->getFileSystem()->read(
            $this->testsWorkingDir . 'project/vendor-prefixed/local/scan-fixture/src/Consumer.php'
        );
        $multiOutput = $this->getFileSystem()->read(
            $this->testsWorkingDir . 'project/vendor-prefixed/local/scan-fixture/src/multi.php'
        );

        $this->assertStringContainsString('namespace MyPrefix\LocalScan;', $namedOutput);
        $this->assertStringContainsString('class NamedClass', $namedOutput);

        $this->assertStringContainsString('class MyPrefix_GlobalClass', $globalsOutput);
        $this->assertStringContainsString('function myprefix_global_helper', $globalsOutput);
        $this->assertStringContainsString('const MYPREFIX_GLOBAL_CONST', $globalsOutput);

        $this->assertStringContainsString('namespace MyPrefix\LocalScan;', $consumerOutput);
        $this->assertStringContainsString('new \MyPrefix_GlobalClass()', $consumerOutput);
        $this->assertStringContainsString('myprefix_global_helper()', $consumerOutput);
        $this->assertStringContainsString('\MYPREFIX_GLOBAL_CONST', $consumerOutput);

        $this->assertStringContainsString('namespace MyPrefix\LocalScan\PartA', $multiOutput);
        $this->assertStringContainsString('namespace MyPrefix\LocalScan\PartB', $multiOutput);
        $this->assertStringContainsString('function myprefix_shared_helper()', $multiOutput);
    }
}

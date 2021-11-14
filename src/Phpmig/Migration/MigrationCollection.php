<?php

/**
 * @package    Phpmig
 * @subpackage Phpmig\Migration
 */

namespace Phpmig\Migration;

class MigrationCollection {
    private $migrations = [];
    private $options = [];

    const OPTION_NAMESPACE = 'namespace';
    const OPTION_VERSION_PREFIX = 'version-prefix';

    public function __construct($options = [])
    {
        $default = [
            self::OPTION_NAMESPACE => '\\',
            self::OPTION_VERSION_PREFIX => ''
        ];

        $this->options = array_merge($default, $options);
    }

    public function addPath($path) {
        if (
            !is_dir($path) ||
            !is_readable($path)
        ) {
            throw new \InvalidArgumentException('Given path is not readable');
        }

        $migrationsPath = realpath($path);
        $this->migrations = array_merge($this->migrations, glob($migrationsPath . DIRECTORY_SEPARATOR . '*.php'));
    }

    public function addMigrations($migrationFiles) {
        if(is_array($migrationFiles) === false) {
            $migrationFiles = [$migrationFiles];
        }

        $this->migrations = array_merge($this->migrations, $migrationFiles);
    }

    public function getMigrations() {
        $to_run = array();

        $migrations = $this->migrations;

        foreach ($migrations as $path) {
            preg_match('/^[0-9]+/', basename($path), $matches);
            if (!array_key_exists(0, $matches)) {
                continue;
            }

            $version = $matches[0];

            $to_run[$path] = $this->getOptions();
        }

        return $to_run;
    }

    public function getOptions() {
        return $this->options;
    }
    
}
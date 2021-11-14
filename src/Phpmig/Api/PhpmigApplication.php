<?php
/**
 * @package    Phpmig
 * @subpackage Api
 */
namespace Phpmig\Api;

use Phpmig\Migration;
use Phpmig\Migration\MigrationCollection;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The phpmig application for API processing
 *
 * Usage:
 * <code>
 * $container = include_once "/full/path/to/phpmig.php";
 * $output = new Symfony\Component\Console\Output\OutputInterface\BufferedOutput;
 * $phpmig = new Phpmig\Api\PhpmigApplication($container, $output);
 * $phpmig->up(); // upgrade to latest version
 * echo $output->output(); // fetch output
 * </code>
 *
 * @author      Cody Phillips
 */
class PhpmigApplication
{
    protected $container;
    protected $output;
    protected $migrations;

    protected $adapter;
    
    public function __construct(\ArrayAccess $container, OutputInterface $output)
    {
        $this->container = $container;
        $this->output = $output;

        if (!isset($this->container['phpmig.migrator']))
            $this->container['phpmig.migrator'] = new Migration\Migrator($container['phpmig.adapter'], $this->container, $this->output);
        
        $migrations = array();

        $defaultCollection = new MigrationCollection();
        $this->container['phpmig.collections'][] = $defaultCollection;

        if (isset($this->container['phpmig.migrations'])) {
            $defaultCollection->addMigrations($container['phpmig.migrations']);
        }

        if (isset($this->container['phpmig.migrations_path'])) {
            $migrationsPath = realpath($this->container['phpmig.migrations_path']);
            
            $defaultCollection->addPath($migrationsPath);
        }

        foreach ($this->container['phpmig.collections'] as $collection) {
            $collectionMigrations = $collection->getMigrations();

            $migrations = array_merge($migrations, $collectionMigrations);
        }
        
        $this->migrations = $migrations;
        $this->adapter = $container['phpmig.adapter'];
    }
    
    /**
     * Migrate up
     *
     * @param string $version The version to migrate up to
     */
    public function up($version = null)
    {
        $adapter = ! empty($this->container['phpmig.adapter']) ? $this->container['phpmig.adapter'] : null;

        if ($adapter == null) {

            throw new RuntimeException("The container must contain a phpmig.adapter key!");
        }

        if (!$adapter->hasSchema()) {

            $this->container['phpmig.adapter']->createSchema();
        }

        foreach ($this->getMigrations($this->getVersion(), $version) as $migration) {
            $this->container['phpmig.migrator']->up($migration);
        }
    }
    
    /**
     * Migrate down
     *
     * @param string $version The version to migrate down to
     */
    public function down($version = 0)
    {
        if ($version === null || $version < 0)
            throw new \InvalidArgumentException("Invalid version given, expected  >= 0.");
            
        foreach ($this->getMigrations($this->getVersion(), $version) as $migration) {
            $this->container['phpmig.migrator']->down($migration);
        }
    }
    
    /**
     * Load all migrations to get $from to $to
     *
     * @param string $from The from version
     * @param string $to The to version
     * @return array An array of Phpmig\Migration\Migration objects to process
     */
    public function getMigrations($from, $to = null)
    {
        $to_run = array();

        $migrations = $this->migrations;
        $versions   = $this->adapter->fetchAll();

        $cleanedVersions = [];
        foreach ($versions as $index => $version) {
            preg_match('/([0-9]+)$/', $version, $matches);
            $cleanedVersions[$index] = $matches[1];
        }

        sort($versions);

        $direction = 'up';
        if($to !== null ){
            $direction = $to > $from ? 'up' : 'down';            
        }


        if ($direction == 'down') {
            rsort($migrations);

            foreach($migrations as $path => $options) {
                preg_match('/^[0-9]+/', basename($path), $matches);
                if (!array_key_exists(0, $matches)) {
                    continue;
                }
                
                $version = $matches[0];
                $prefixedVersion = $options[MigrationCollection::OPTION_VERSION_PREFIX] . $version;

                if ($version > $from) {
                    continue;
                }
                if ($version <= $to) {
                    continue;
                }

                if (in_array($prefixedVersion, $versions)) {
                    $to_run[$path] = $options;
                }
            }
        } else {
            sort($migrations);
            foreach($migrations as $path => $options) {
                preg_match('/^[0-9]+/', basename($path), $matches);
                if (!array_key_exists(0, $matches)) {
                    continue;
                }
                
                $version = $matches[0];
                $prefixedVersion = $options[MigrationCollection::OPTION_VERSION_PREFIX] . $version;

                if ($to !== null && ($version > $to)) {
                    continue;
                }

                if (!in_array($prefixedVersion, $versions)) {
                    $to_run[$path] = $options;
                }
            }
        }

        return $this->loadMigrations($to_run);
    }
    
    /**
     * Loads migrations from the given set of available migration files
     *
     * @param array $migrations An array of migration files to prepare migrations for
     * @return array An array of Phpmig\Migration\Migration objects
     */
    protected function loadMigrations($migrations)
    {
        $versions = array();
        $names = array();
        foreach ($migrations as $path => $options) {
            if (!preg_match('/^[0-9]+/', basename($path), $matches)) {
                throw new \InvalidArgumentException(sprintf('The file "%s" does not have a valid migration filename', $path));
            }
    
            $version = $matches[0];
            $prefixedVersion = $options[MigrationCollection::OPTION_VERSION_PREFIX] . $version;
    
            if (isset($versions[$version])) {
                throw new \InvalidArgumentException(sprintf('Duplicate migration, "%s" has the same version as "%s"', $path, $versions[$version]->getName()));
            }
    
            $migrationName = preg_replace('/^[0-9]+_/', '', basename($path));
            if (false !== strpos($migrationName, '.')) {
                $migrationName = substr($migrationName, 0, strpos($migrationName, '.'));
            }

            $class = rtrim($options[MigrationCollection::OPTION_NAMESPACE], '\\') . '\\' . $this->migrationToClassName($migrationName);
    
            if (isset($names[$class])) {
                throw new \InvalidArgumentException(sprintf(
                    'Migration "%s" has the same name as "%s"',
                    $path,
                    $names[$class]
                ));
            }
            $names[$class] = $path;
    
            require_once $path;
            if (!class_exists($class)) {
                throw new \InvalidArgumentException(sprintf(
                    'Could not find class "%s" in file "%s"',
                    $class,
                    $path
                ));
            }
    
            $migration = new $class($prefixedVersion);
    
            if (!($migration instanceof Migration\Migration)) {
                throw new \InvalidArgumentException(sprintf(
                    'The class "%s" in file "%s" must extend \Phpmig\Migration\Migration',
                    $class,
                    $path
                ));
            }
    
            $migration->setOutput($this->output); // inject output
    
            $versions[$version] = $migration;
        }
    
        return $versions;
    }
    
    /**
     * Transform create_table_user to CreateTableUser
     *
     * @param string $migrationName The migration name
     * @return string The CamelCase migration name
     */
    protected function migrationToClassName($migrationName)
    {
        $class = str_replace('_', ' ', $migrationName);
        $class = ucwords($class);
        return str_replace(' ', '', $class);
    }
    
    /**
     * Returns the current version
     *
     * @return string The current installed version
     */
    public function getVersion()
    {
        $versions = $this->container['phpmig.adapter']->fetchAll();
        sort($versions);
    
        if (!empty($versions)) {
            return end($versions);
        }
        return 0;
    }
}

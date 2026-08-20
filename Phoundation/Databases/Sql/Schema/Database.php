<?php

/**
 * class Database
 *
 *
 *
 * @author    Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @license   http://opensource.org/licenses/GPL-2.0 GNU Public License, Version 2
 * @copyright Copyright © 2025 Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @package   Phoundation\Databases
 */


declare(strict_types=1);

namespace Phoundation\Databases\Sql\Schema;

use Phoundation\Core\Log\Log;
use Phoundation\Data\DataEntries\Interfaces\IdentifierInterface;
use Phoundation\Data\Interfaces\IteratorInterface;
use Phoundation\Databases\Export;
use Phoundation\Databases\Import;
use Phoundation\Databases\Sql\Exception\SqlException;
use Phoundation\Databases\Sql\Exception\SqlTableDoesNotExistException;
use Phoundation\Databases\Sql\Schema\Interfaces\DatabaseInterface;
use Phoundation\Databases\Sql\Schema\Interfaces\TableInterface;
use Phoundation\Exception\OutOfBoundsException;
use Phoundation\Exception\UnderConstructionException;
use Phoundation\Filesystem\PhoFile;


class Database extends SchemaAbstract implements DatabaseInterface
{
    /**
     * The columns for this database
     *
     * @var array $columns
     */
    protected array $columns = [];

    /**
     * The indices for this database
     *
     * @var array $indices
     */
    protected array $indices = [];

    /**
     * The tables for this schema
     *
     * @var array $tables
     */
    protected array $tables = [];


    /**
     * Returns the database name
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->getName();
    }


    /**
     * Sets the database name
     *
     * This will effectively rename the database. Since MySQL does not support renaming operations, this requires
     * dumping the entire database and importing it under the new name and dropping the original. Depending on your
     * database size, this may take a while!
     *
     * @param string|null $name
     *
     * @return static
     */
    public function setName(?string $name): static
    {
        throw new UnderConstructionException(tr('Cannot set database name yet, this would rename the database'));
    }


    /**
     * Create this database
     *
     * @param bool $use
     *
     * @return static
     */
    public function create(bool $use = true): static
    {
        if ($this->exists()) {
            throw new SqlException(tr('Cannot create database ":name", it already exists', [
                ':name' => $this->getName(),
            ]));
        }

        Log::action(ts('Creating database ":database" on SQL instance ":instance"', [
            ':instance' => $this->getSqlObject()->getConnector(),
            ':database' => $this->getName()
        ]));

        // This query can only partially use bound variables!
        $this->getSqlObject()->query('CREATE DATABASE `' . $this->getName() . '` DEFAULT CHARSET=:character_set COLLATE=:collate', [
            ':character_set' => $this->configuration['character_set'],
            ':collate'       => $this->configuration['collate'],
        ]);

        if ($use) {
            $this->use($this->getName());
        }

        return $this;
    }


    /**
     * Returns if the database exists in the database or not
     *
     * @return bool
     */
    public function exists(): bool
    {
        // If this query returns nothing, the database does not exist. If it returns anything, it does exist.
        return (bool) $this->getSqlObject()->getRow('SHOW DATABASES LIKE :name', [':name' => $this->getName()]);
    }


    /**
     * Use the specified database name
     *
     * @param string $name
     *
     * @return static
     */
    protected function use(string $name): static
    {
        $this->getSqlObject()->use($name);
        return $this;
    }


    /**
     * Drop this database
     *
     * @return static
     */
    public function drop(): static
    {
        // This query cannot use bound variables!
        Log::warning(ts('Dropping database ":database" on SQL instance ":instance"', [
            ':instance' => $this->getSqlObject()->getConnector(),
            ':database' => $this->getName(),
        ]), 5);

        $this->getSqlObject()->query('DROP DATABASE IF EXISTS `' . $this->getName() . '`');
        return $this;
    }


    /**
     * Access a new Table object for the currently selected database
     *
     * @param string $name The name of the table for which the Table object will be returned
     *
     * @return TableInterface
     */
    public function getTableObject(string $name): TableInterface
    {
        // If we do not have this table yet, create it now
        if (!array_key_exists($name, $this->getTables())) {
            throw new SqlTableDoesNotExistException(ts('Cannot return Table object for table ":table", that table does not exist in database ":database"', [
                ':table'    => $name,
                ':database' => $this->getName()
            ]));
        }

        return new Table($name, $this->getSqlObject(), $this);
    }


    /**
     * Load the table parameters from the database
     *
     * @param IdentifierInterface|array|string|int|null $identifiers
     * @param bool                                      $like
     *
     * @return static
     */
    public function load(IdentifierInterface|array|string|int|null $identifiers = null, bool $like = false): static
    {
        // Load columns & indices data
        // TODO Implement
        return $this;
    }


    /**
     * Renames this database
     *
     * @param string $database_name
     * @return static
     *
     * @see https://www.atlassian.com/data/admin/how-to-rename-a-database-in-mysql
     */
    public function rename(string $database_name): static
    {
        $tables = $this->getTables();
        $target = Database::new($database_name, $this->getSqlObject(), $this->_parent);

        if ($target->exists()) {
            // Target already exists
            if (!FORCE) {
                throw new OutOfBoundsException(tr('Cannot rename database ":from" to ":to", the target database ":database" already exists', [
                    ':from'     => $this->getName(),
                    ':to'       => $database_name,
                    ':database' => $database_name,
                ]));
            }

            $target->drop();
        }

        $target->create();

        foreach ($tables as $table) {
            sql()->query('RENAME TABLE `' . $this->getName() . '`.`' . $table . '` 
                                TO           `' . $database_name . '`.`' . $table . '`');
        }

        // Drop the current database
        $this->drop();

        // Return link to the new target
        return $target;
    }


    /**
     * Will copy the current database to the new name
     *
     * Database will be copied by making a complete dump of the current database, which is then imported into the new
     * database
     *
     * @param string $database_name
     * @param int $timeout
     *
     * @return static
     */
    public function copy(string $database_name, int $timeout = 3600): static
    {
        // Export current database
        $_file      = PhoFile::newTemporary();
        $_target    = Database::new($database_name, $this->getSqlObject(), $this->_parent);
        $_connector = $this->getSqlObject()->getConnectorObject();

        if ($_target->exists()) {
            // Target already exists
            if (!FORCE) {
                throw new OutOfBoundsException(tr('Cannot copy database ":from" to ":to", the target database already exists', [
                    ':from' => $this->getName(),
                    ':to'   => $database_name,
                ]));
            }

            $_target->drop();
        }

        $_target->create();

        // Export the current database
        Export::new()
              ->setConnectorObject($_connector)
              ->setDatabase($this->getName())
              ->setTimeout($timeout)
              ->dump($_file);

        // Import dump into new database
        Import::new()
              ->setConnectorObject($_connector)
              ->setDatabase($database_name)
              ->setFileObject($_file)
              ->setTimeout($timeout)
              ->import();

        return $this;
    }


    /**
     * Returns all the tables for this database
     *
     * @return array
     */
    public function getTables(): array
    {
        if (empty($this->tables)) {
            $this->tables = sql()->listKeyValue('SELECT   `TABLE_NAME` 
                                                 FROM     `COLUMNS` 
                                                 WHERE    `TABLE_SCHEMA` = :database 
                                                 GROUP BY `TABLE_NAME`', [
                                                     ':database' => $this->name,
            ]);
        }

        return $this->tables;
    }


    /**
     * Returns an array with all tables in this database that have the specified column
     *
     * @param string $column
     *
     * @return array
     */
    public function getTablesWithColumnObject(string $column): array
    {
        return sql()->listKeyValue('SELECT `TABLE_NAME` 
                                    FROM   `INFORMATION_SCHEMA`.`COLUMNS` 
                                    WHERE  `COLUMN_NAME` = :column
                                    AND    `TABLE_SCHEMA` = DATABASE()', [
                                        ':column' => $column,
        ]);
    }


    /**
     * Returns the size in bytes for this table
     *
     * @return int
     */
    public function getSize(): int
    {
        return sql()->getInteger('SELECT `data_length` + `index_length` AS `size_bytes` 
                                  FROM   `information_schema`.`tables` 
                                  WHERE  `table_schema` = :database', [
                                      ':database' => $this->getName()
        ]);
    }


    /**
     * Returns the amount of rows in this table
     *
     * @return int
     */
    public function getCount(): int
    {
        return sql()->getInteger('SELECT SUM(`table_rows`) 
                                  FROM   `information_schema`.`tables`
                                  WHERE  `table_schema` = :database', [
                                      ':database' => $this->getName()
        ]);
    }
}

<?php

/**
 * Class Table
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
use Phoundation\Data\Interfaces\IteratorInterface;
use Phoundation\Data\Iterator;
use Phoundation\Databases\Sql\Exception\SqlException;
use Phoundation\Databases\Sql\Interfaces\SqlInterface;
use Phoundation\Databases\Sql\Schema\Interfaces\SchemaAbstractInterface;
use Phoundation\Databases\Sql\Schema\Interfaces\SchemaInterface;
use Phoundation\Databases\Sql\Schema\Interfaces\TableAlterInterface;
use Phoundation\Databases\Sql\Schema\Interfaces\TableDefineInterface;
use Phoundation\Databases\Sql\Schema\Interfaces\TableInterface;
use Phoundation\Databases\Sql\Sql;
use Phoundation\Utils\Arrays;
use Phoundation\Utils\Strings;


class Table extends SchemaAbstract implements TableInterface
{
    /**
     * The SQL interface
     *
     * @var Sql $_sql
     */
    protected Sql $_sql;

    /**
     * The columns for this table
     *
     * @var array $columns
     */
    protected array $columns;

    /**
     * The foreign keys for this table
     *
     * @var array $foreign_keys
     */
    protected array $foreign_keys = [];

    /**
     * The indices for this table
     *
     * @var array $indices
     */
    protected array $indices = [];


    /**
     * Table constructor
     *
     * @param string                                  $name
     * @param SqlInterface                            $_sql
     * @param SchemaAbstractInterface|SchemaInterface $_parent
     */
    public function __construct(string $name, SqlInterface $_sql, SchemaAbstractInterface|SchemaInterface $_parent)
    {
        parent::__construct($name, $_sql, $_parent);

        if ($name) {
            // Load this table
            $this->load($name);
        }
    }


    /**
     * Load the table parameters from the database
     *
     * @return void
     */
    protected function load(): void
    {
        // Load columns & indices data
        // TODO Implement
    }


    /**
     * Define and create the table
     *
     * @return TableDefineInterface
     */
    public function getDefineObject(): TableDefineInterface
    {
        return new TableDefine($this->name, $this->getSqlObject(), $this);
    }


    /**
     * Define and create the table
     *
     * @return TableAlterInterface
     */
    public function getAlterObject(): TableAlterInterface
    {
        return new TableAlter($this->name, $this->getSqlObject(), $this);
    }


    /**
     * Returns the parent for this Table object
     *
     * @return SchemaAbstractInterface|SchemaInterface
     */
    public function getParentObject(): SchemaAbstractInterface|SchemaInterface
    {
        $return = parent::getParentObject();

        if ($return->getName() !== $this->getSqlObject()->getDatabase()) {
            throw new SqlException(ts('Cannot return Table parent Database object, as the database for the parent ":parent" does not match the database for the Sql object ":sql"', [
                ':sql'    => $this->getSqlObject()->getDatabase(),
                ':parent' => $this->getParentObject()->getName()
            ]));
        }

        return $return;
    }


    /**
     * Returns the database to which this table belongs
     *
     * @return string
     */
    public function getDatabase(): string
    {
        return $this->getParentObject()->getName();
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
                                  WHERE  `table_schema` = :database 
                                  AND    `table_name`   = :table', [
                                      ':database' => $this->getDatabase(),
                                      ':table'    => $this->getName()
        ]);
    }


    /**
     * Returns the amount of rows in this table
     *
     * @return int
     */
    public function getCount(): int
    {
        return sql()->getInteger('SELECT COUNT(*) FROM `' . $this->getDatabase() . '`.`' . $this->getName() . '`');
    }


    /**
     * Renames this table
     *
     * @param string $table_name
     *
     * @return void
     */
    public function rename(string $table_name): void
    {
        sql()->query("RENAME TABLE `" . $this->name . "` TO `" . $table_name . "`");
    }


    /**
     * Returns if the table exists in the database or not
     *
     * @return bool
     */
    public function exists(): bool
    {
        // If this query returns nothing, the table does not exist. If it returns anything, it does exist.
        return (bool) sql()->getRow("SHOW TABLES LIKE :name", [":name" => $this->name]);
    }


    /**
     * Will drop this table
     *
     * @return static
     */
    public function drop(): static
    {
        Log::warning(ts('Dropping table ":table" in database ":database" for SQL instance ":instance"', [
            ":table"    => $this->name,
            ":instance" => $this->getSqlObject()->getConnector(),
            ":database" => $this->getName(),
        ]), 3);

        sql()->query("DROP TABLES IF EXISTS `" . $this->name . "`");

        return $this;
    }


    /**
     * Will truncate this table
     *
     * @return void
     */
    public function truncate(): void
    {
        Log::warning(ts("Truncating table :table", [":table" => $this->name]));
        sql()->query("TRUNCATE `" . $this->name . "`");
    }


    /**
     * Returns the number of records in this table
     *
     * @return int
     */
    public function getCount(): int
    {
        return sql()->getInteger("SELECT COUNT(*) as `count` FROM `" . $this->name . "`");
    }


    /**
     * Returns the table name
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }


    /**
     * Returns true if the specified column exists in this table
     *
     * @param string $column
     * @param bool   $cache
     *
     * @return bool
     */
    public function columnExists(string $column, bool $cache = false): bool
    {
        return $this->getColumns($cache)->keyExists(Strings::cut($column, '`', '`', needles_required: false));
    }


    /**
     * Returns the table columns
     *
     * @param bool $cache
     *
     * @return IteratorInterface
     */
    public function getColumns(bool $cache = false): IteratorInterface
    {
        if (!$cache) {
            unset($this->columns);
        }

        if (empty($this->columns)) {
            $columns = [];
            $results = sql()->listKeyValues("DESCRIBE `" . $this->name . "`");

            foreach ($results as $result) {
                $columns[$result["field"]] = Arrays::lowercaseKeys($result);
            }

            $this->columns = $columns;
        }

        return Iterator::new($this->columns);
    }


    /**
     * Returns true if the specified foreign key exists in this table
     *
     * @param string $key
     * @param bool   $cache
     *
     * @return bool
     */
    public function foreignKeyExists(string $key, bool $cache = false): bool
    {
        return $this->getForeignKeys($cache)->keyExists(Strings::cut($key, '`', '`', needles_required: false));
    }


    /**
     * Returns a list of table columns that have foreign keys pointed at the specified column
     *
     * @param string $column The column of this table to which the foreign keys have to point
     *
     * @return IteratorInterface
     */
    public function getForeignKeysToColumn(string $column): IteratorInterface
    {
        return sql()->listDataIterator('SELECT   CONCAT(`KEY_COLUMN_USAGE`.`TABLE_SCHEMA`, " / ", `KEY_COLUMN_USAGE`.`TABLE_NAME`, " / ", `KEY_COLUMN_USAGE`.`COLUMN_NAME`) AS `identifier`,                                                                                                              
                                                 `KEY_COLUMN_USAGE`.`TABLE_SCHEMA`                                                                                          AS `source_database`,     
                                                 `KEY_COLUMN_USAGE`.`TABLE_NAME`                                                                                            AS `source_table`,     
                                                 `KEY_COLUMN_USAGE`.`COLUMN_NAME`                                                                                           AS `source_column`,     
                                                 `KEY_COLUMN_USAGE`.`CONSTRAINT_NAME`                                                                                       AS `foreign_key_name`,     
                                                 `KEY_COLUMN_USAGE`.`REFERENCED_TABLE_NAME`                                                                                 AS `target_table`,     
                                                 `KEY_COLUMN_USAGE`.`REFERENCED_COLUMN_NAME`                                                                                AS `target_column` 
                                        FROM     `information_schema`.`KEY_COLUMN_USAGE`                                         
                                        WHERE    `KEY_COLUMN_USAGE`.`REFERENCED_TABLE_SCHEMA` = :schema 
                                        AND      `KEY_COLUMN_USAGE`.`REFERENCED_TABLE_NAME`   = :table     
                                        AND      `KEY_COLUMN_USAGE`.`REFERENCED_COLUMN_NAME`  = :column 
                                        ORDER BY `KEY_COLUMN_USAGE`.`TABLE_NAME`, `KEY_COLUMN_USAGE`.`COLUMN_NAME`', [
            ':schema' => $this->getName(),
            ':table'  => $this->name,
            ':column' => $column,
        ]);
    }


    /**
     * Returns the table foreign_keys
     *
     * @param bool $cache
     *
     * @return IteratorInterface
     */
    public function getForeignKeys(bool $cache = false): IteratorInterface
    {
        if (!$cache) {
            unset($this->foreign_keys);
        }

        if (empty($this->foreign_keys)) {
            $results = sql()->listKeyValues('SHOW CREATE TABLE `' . $this->name . '`');
            $results = $results[$this->name]["create table"];

            // Parse all foreign keys from the resulting query
            do {
                $foreign_key = Strings::from($results, "CONSTRAINT ");
                $foreign_key = Strings::until($foreign_key, ",");
                $foreign_key = Strings::until($foreign_key, ") ENGINE");

                if (!$foreign_key) {
                    break;
                }

                preg_match_all("/`?([a-z_]+)`?\s+FOREIGN\s+KEY\s+\(`?([a-z_]+)`?\)\s+REFERENCES\s+`?([a-z_]+)`?\s+\(`?([a-z_]+)`?\)/i", $foreign_key, $matches);

                $this->foreign_keys[$matches[1][0]] = [
                    "key"              => $matches[1][0],
                    "column"           => $matches[2][0],
                    "reference_table"  => $matches[3][0],
                    "reference_column" => $matches[4][0]
                ];

                $results = Strings::from($results, "CONSTRAINT ");
                $results = Strings::from($results, ",", needle_required: true);
            } while (true);
        }

        return Iterator::new($this->foreign_keys);
    }


    /**
     * Returns true if the specified index exists in this table
     *
     * @param string $key
     * @param bool   $cache
     *
     * @return bool
     */
    public function indexExists(string $key, bool $cache = false): bool
    {
        return $this->getIndices($cache)->keyExists(Strings::cut($key, '`', '`', needles_required: false));
    }


    /**
     * Returns the table indices
     *
     * @param bool $cache
     *
     * @return IteratorInterface
     */
    public function getIndices(bool $cache = false): IteratorInterface
    {
        if (!$cache) {
            unset($this->indices);
        }

        if (empty($this->indices)) {
            $indices = [];
            $results = sql()->listKeyValues("SHOW CREATE TABLE  `" . $this->name . "`");
            $results = array_pop($results);
            $results = array_pop($results);
            $results = explode(PHP_EOL, $results);

            foreach ($results as $result) {
                if (preg_match_all("/(?:KEY|UNIQUE) `(.+?)` \((.+?)\)/i", $result, $matches)) {
                    $indices[$matches[1][0]] = str_replace("`", "", $matches[2][0]);
                }
            }

            $this->indices = $indices;
        }

        return Iterator::new($this->indices);
    }
}

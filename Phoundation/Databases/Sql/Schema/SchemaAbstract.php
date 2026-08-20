<?php

/**
 * SchemaAbstract class
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

use Phoundation\Data\Traits\TraitDataStringName;
use Phoundation\Databases\Sql\Interfaces\SqlInterface;
use Phoundation\Databases\Sql\Schema\Interfaces\SchemaAbstractInterface;
use Phoundation\Databases\Sql\Schema\Interfaces\SchemaInterface;
use Phoundation\Databases\Sql\Sql;


abstract class SchemaAbstract implements SchemaAbstractInterface
{
    use TraitDataStringName {
        setName as protected __setName;
    }


    /**
     * The database interface for this schema
     *
     * @var Sql $_sql
     */
    protected Sql $_sql;

    /**
     * The SQL configuration
     *
     * @var array $configuration
     */
    protected array $configuration;

    /**
     * The parent object
     *
     * @var SchemaAbstractInterface|SchemaInterface $_parent
     */
    protected SchemaAbstractInterface|SchemaInterface $_parent;


    /**
     * SchemaAbstract class constructor
     *
     * @param string                                  $name
     * @param SqlInterface                            $_sql
     * @param SchemaAbstractInterface|SchemaInterface $_parent
     */
    public function __construct(string $name, SqlInterface $_sql, SchemaAbstractInterface|SchemaInterface $_parent)
    {
        $this->_sql                      = $_sql;
        $this->name                      = $name;
        $this->_parent                   = $_parent;
        $this->configuration             = $_sql->getConfiguration();
        $this->configuration['database'] = $name;
    }


    /**
     * Returns a new SchemaAbstract type object
     *
     * @param string                                  $name
     * @param Sql                                     $_sql
     * @param SchemaAbstractInterface|SchemaInterface $_parent
     *
     * @return static
     */
    public static function new(string $name, Sql $_sql, SchemaAbstractInterface|SchemaInterface $_parent): static
    {
        return new static($name, $_sql, $_parent);
    }


    /**
     * Returns the SQL object for this schema
     *
     * @return SqlInterface
     */
    public function getSqlObject(): SqlInterface
    {
        return $this->_sql;
    }


    /**
     * Reload the table schema
     *
     * @return void
     */
    public function reload(): void
    {
        $this->load();
    }


    /**
     * Returns the parent for this Schema object
     *
     * @return SchemaAbstractInterface|SchemaInterface
     */
    public function getParentObject(): SchemaAbstractInterface|SchemaInterface
    {
        return $this->_parent;
    }


    /**
     * Returns the size in bytes for this table
     *
     * @return int
     */
    abstract public function getSize(): int;


    /**
     * Returns the amount of rows in this table
     *
     * @return int
     */
    abstract public function getCount(): int;
}

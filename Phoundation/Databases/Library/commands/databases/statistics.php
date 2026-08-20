<?php

/**
 * Command databases import
 *
 * This command will import the specified database file into the specified database
 *
 * @author    Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @license   http://opensource.org/licenses/GPL-2.0 GNU Public License, Version 2
 * @copyright Copyright © 2022 Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @package   Phoundation\Scripts
 */


declare(strict_types=1);

use Phoundation\Cli\CliDocumentation;
use Phoundation\Core\Log\Log;
use Phoundation\Data\Validator\ArgvValidator;
use Phoundation\Databases\Connectors\Connectors;
use Phoundation\Databases\Databases;
use Phoundation\Databases\Import;
use Phoundation\Filesystem\Exception\FileNotExistException;
use Phoundation\Filesystem\PhoDirectory;
use Phoundation\Os\Processes\Commands\Pho;


CliDocumentation::setUsage('./pho databases statistics
./pho databases statistics -d DATABASENAME
./pho databases statistics -h HOSTNAME -d DATABASENAME');

CliDocumentation::setHelp('This command will dump basic statistics about the specified database


ARGUMENTS


-c / --connector CONNECTOR              The database connector to use. Must either exist in configuration or in the
                                        system database as a database connector. If not specified, the system connector 
                                        will be assumed

-b / --database DATABASE                The database to use. Must exist on the selected database.

-t / --table TABLENAME                  The name of the table that should have its statistics displayed');

CliDocumentation::setAutoComplete([
    'arguments' => [
        '-c,--connector' => function ($word) {
            return Connectors::new()->load()->autoCompleteFind($word);
        },
        '-b,--database'  => function ($word) {
            return Databases::new()->load()->autoCompleteFind($word);
        },
        '-t,--table'     => function ($word) {
            return Tables::new()->load()->autoCompleteFind($word);
        },
    ],
]);


// Validate arguments
$argv = ArgvValidator::new()
                     ->select('-c,--connector', true)->isOptional('system')->sanitizeLowercase()->isInArray(Connectors::new()->load(null, false, true)->getAllRowsSingleColumn('name'))
                     ->select('-b,--database', true)->isOptional()->isVariable()
                     ->select('-t,--table', true)->isOptional()->isVariable()
                     ->validate();


if ($argv['table']) {
    Log::cli(sql($argv['connector'])->getSchemaObject($argv['database'])->getDatabaseObject()->getSize());

} else {
    Log::cli(sql($argv['connector'])->getSchemaObject($argv['database'])->getDatabaseObject()->getSize());
}

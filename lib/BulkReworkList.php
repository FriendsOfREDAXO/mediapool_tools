<?php

namespace FriendsOfRedaxo\MediapoolTools;

/**
 * Class BulkReworkList
 *
 * @package mediapool_tools\lib
 */
class BulkReworkList extends \rex_list
{
    /**
     * getting current sql query
     *
     * @return \rex_sql
     */
    public function getSql()
    {
        return $this->sql;
    }

    /**
     * setting custom sql query
     *
     * @param string $query
     * @return void
     * @throws \rex_sql_exception
     */
    public function setCustomQuery(string $query)
    {
        $this->sql->setQuery($query);
    }
}

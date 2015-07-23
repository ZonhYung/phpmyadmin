<?php

namespace PMA\Controllers\Table;

use PMA\Template;
use PMA\Controllers\TableController;
use PMA_DatabaseInterface;
use PMA_Util;

require_once 'libraries/common.inc.php';
include_once 'libraries/tbl_info.inc.php';
require_once 'libraries/Template.class.php';
require_once 'libraries/mysql_charsets.inc.php';
require_once 'libraries/sql.lib.php';
require_once 'libraries/bookmark.lib.php';
require_once 'libraries/controllers/TableController.class.php';



class TableSearchController extends TableController {

    /**
     * Normal search or Zoom search
     *
     * @access private
     * @var string
     */
    private $_searchType;
    /**
     * Names of columns
     *
     * @access private
     * @var array
     */
    private $_columnNames;
    /**
     * Types of columns
     *
     * @access private
     * @var array
     */
    private $_columnTypes;
    /**
     * Collations of columns
     *
     * @access private
     * @var array
     */
    private $_columnCollations;
    /**
     * Null Flags of columns
     *
     * @access private
     * @var array
     */
    private $_columnNullFlags;
    /**
     * Whether a geometry column is present
     *
     * @access private
     * @var boolean
     */
    private $_geomColumnFlag;
    /**
     * Foreign Keys
     *
     * @access private
     * @var array
     */
    private $_foreigners;
    /**
     * Connection charset
     *
     * @access private
     * @var string
     */
    private $_connectionCharSet;

    protected $url_query;

    public function __construct($db, $table, $searchType, $url_query)
    {
        parent::__construct();

        $this->url_query = $url_query;
        $this->_searchType = $searchType;
        $this->_columnNames = array();
        $this->_columnNullFlags = array();
        $this->_columnTypes = array();
        $this->_columnCollations = array();
        $this->_geomColumnFlag = false;
        $this->_foreigners = array();
        // Loads table's information
        $this->_loadTableInfo();
        $this->_connectionCharSet = $this->dbi->fetchValue(
            "SHOW VARIABLES LIKE 'character_set_connection'", 0, 1
        );
    }

    /**
     * Gets all the columns of a table along with their types, collations
     * and whether null or not.
     *
     * @return void
     */
    private function _loadTableInfo()
    {
        // Gets the list and number of columns
        $columns = $this->dbi->getColumns(
            $this->db, $this->table, null, true
        );
        // Get details about the geometry functions
        $geom_types = PMA_Util::getGISDatatypes();

        foreach ($columns as $row) {
            // set column name
            $this->_columnNames[] = $row['Field'];

            $type = $row['Type'];
            // check whether table contains geometric columns
            if (in_array($type, $geom_types)) {
                $this->_geomColumnFlag = true;
            }
            // reformat mysql query output
            if (strncasecmp($type, 'set', 3) == 0
                || strncasecmp($type, 'enum', 4) == 0
            ) {
                $type = str_replace(',', ', ', $type);
            } else {
                // strip the "BINARY" attribute, except if we find "BINARY(" because
                // this would be a BINARY or VARBINARY column type
                if (! preg_match('@BINARY[\(]@i', $type)) {
                    $type = preg_replace('@BINARY@i', '', $type);
                }
                $type = preg_replace('@ZEROFILL@i', '', $type);
                $type = preg_replace('@UNSIGNED@i', '', $type);
                $type = /*overload*/mb_strtolower($type);
            }
            if (empty($type)) {
                $type = '&nbsp;';
            }
            $this->_columnTypes[] = $type;
            $this->_columnNullFlags[] = $row['Null'];
            $this->_columnCollations[]
                = ! empty($row['Collation']) && $row['Collation'] != 'NULL'
                ? $row['Collation']
                : '';
        } // end for

        // Retrieve foreign keys
        $this->_foreigners = PMA_getForeigners($this->db, $this->table);
    }

    public function indexAction()
    {
        switch ($this->_searchType) {
        case 'replace':
            if (isset($_POST['find'])) {
                $this->findAction();

                return;
            }
            $this->response
                ->getHeader()
                ->getScripts()
                ->addFile('tbl_find_replace.js');

            // Show secondary level of tabs
            $this->response->addHTML(
                Template::get('table/secondary_tabs')
                    ->render(
                        array(
                            'url_params' => array(
                                'db'    => $this->db,
                                'table' => $this->table,
                            ),
                            'sub_tabs'   => $this->_getSubTabs(),
                        )
                    )
            );

            if (isset($_POST['replace'])) {
                $this->replaceAction();
            }

            if (!isset($goto)) {
                $goto = PMA_Util::getScriptNameForOption(
                    $GLOBALS['cfg']['DefaultTabTable'], 'table'
                );
            }
            // Defines the url to return to in case of error in the next sql
            // statement
            $params = array('db' => $this->db, 'table' => $this->table);
            $err_url = $goto . '?' . PMA_URL_getCommon($params);
            // Displays the find and replace form
            $this->response->addHTML(
                Template::get('table/selection_form')
                    ->render(
                        array(
                            'searchType'       => $this->_searchType,
                            'db'               => $this->db,
                            'table'            => $this->table,
                            'goto'             => $goto,
                            'self'             => $this,
                            'geomColumnFlag'   => $this->_geomColumnFlag,
                            'columnNames'      => $this->_columnNames,
                            'columnTypes'      => $this->_columnTypes,
                            'columnCollations' => $this->_columnCollations,
                            'dataLabel'        => null,
                        )
                    )
            );
            break;

        case 'normal':
            $this->response->getHeader()
                ->getScripts()
                ->addFiles(
                    array(
                        'makegrid.js',
                        'sql.js',
                        'tbl_select.js',
                        'tbl_change.js',
                        'jquery/jquery-ui-timepicker-addon.js',
                        'jquery/jquery.uitablefilter.js',
                        'gis_data_editor.js',
                    )
                );

            if (isset($_REQUEST['range_search'])) {
                $this->rangeSearchAction();

                return;
            }

            /**
             * No selection criteria received -> display the selection form
             */
            if (!isset($_POST['columnsToDisplay'])
                && !isset($_POST['displayAllColumns'])
            ) {
                $this->displaySelectionFormAction();
            } else {
                $this->doSelectionAction();
            }
            break;

        case 'zoom':
            $this->response->getHeader()
                ->getScripts()
                ->addFiles(
                    array(
                        'makegrid.js',
                        'sql.js',
                        'jqplot/jquery.jqplot.js',
                        'jqplot/plugins/jqplot.canvasTextRenderer.js',
                        'jqplot/plugins/jqplot.canvasAxisLabelRenderer.js',
                        'jqplot/plugins/jqplot.dateAxisRenderer.js',
                        'jqplot/plugins/jqplot.highlighter.js',
                        'jqplot/plugins/jqplot.cursor.js',
                        'canvg/canvg.js',
                        'jquery/jquery-ui-timepicker-addon.js',
                        'tbl_zoom_plot_jqplot.js',
                        'tbl_change.js',
                    )
                );

            /**
             * Handle AJAX request for data row on point select
             *
             * @var post_params Object containing parameters for the POST request
             */
            if (isset($_REQUEST['get_data_row'])
                && $_REQUEST['get_data_row'] == true
            ) {
                $this->getDataRowAction();

                return;
            }
            /**
             * Handle AJAX request for changing field information
             * (value,collation,operators,field values) in input form
             *
             * @var post_params Object containing parameters for the POST request
             */
            if (isset($_REQUEST['change_tbl_info'])
                && $_REQUEST['change_tbl_info'] == true
            ) {
                $this->changeTableInfoAction();

                return;
            }

            $this->url_query .= '&amp;goto=tbl_select.php&amp;back=tbl_select.php';

            // Gets tables information
            require_once './libraries/tbl_info.inc.php';

            if (!isset($goto)) {
                $goto = PMA_Util::getScriptNameForOption(
                    $GLOBALS['cfg']['DefaultTabTable'], 'table'
                );
            }
            // Defines the url to return to in case of error in the next sql
            // statement
            $err_url = $goto . PMA_URL_getCommon(
                    array('db' => $this->db, 'table' => $this->table)
                );

            //Set default datalabel if not selected
            if (!isset($_POST['zoom_submit']) || $_POST['dataLabel'] == '') {
                $dataLabel = PMA_getDisplayField($this->db, $this->table);
            } else {
                $dataLabel = $_POST['dataLabel'];
            }

            // Displays the zoom search form
            $this->response->addHTML(
                Template::get('table/secondary_tabs')
                    ->render(
                        array(
                            'url_params' => array(
                                'db'    => $this->db,
                                'table' => $this->table,
                            ),
                            'sub_tabs'   => $this->_getSubTabs(),
                        )
                    )
            );
            $this->response->addHTML(
                Template::get('table/selection_form')
                    ->render(
                        array(
                            'searchType'       => $this->_searchType,
                            'db'               => $this->db,
                            'table'            => $this->table,
                            'goto'             => $goto,
                            'self'             => $this,
                            'geomColumnFlag'   => $this->_geomColumnFlag,
                            'columnNames'      => $this->_columnNames,
                            'columnTypes'      => $this->_columnTypes,
                            'columnCollations' => $this->_columnCollations,
                            'dataLabel'        => $dataLabel,
                        )
                    )
            );

            /*
             * Handle the input criteria and generate the query result
             * Form for displaying query results
             */
            if (isset($_POST['zoom_submit'])
                && $_POST['criteriaColumnNames'][0] != 'pma_null'
                && $_POST['criteriaColumnNames'][1] != 'pma_null'
                && $_POST['criteriaColumnNames'][0]
                != $_POST['criteriaColumnNames'][1]
            ) {
                $this->zoomSubmitAction($dataLabel, $goto);
            }
            break;
        }
    }

    public function zoomSubmitAction($dataLabel, $goto)
    {
        //Query generation part
        $sql_query = $this->buildSqlQuery();
        $sql_query .= ' LIMIT ' . $_POST['maxPlotLimit'];

        //Query execution part
        $result = $this->dbi->query(
            $sql_query . ";", null, PMA_DatabaseInterface::QUERY_STORE
        );
        $fields_meta = $this->dbi->getFieldsMeta($result);
        $data = array();
        while ($row = $this->dbi->fetchAssoc($result)) {
            //Need a row with indexes as 0,1,2 for the getUniqueCondition
            // hence using a temporary array
            $tmpRow = array();
            foreach ($row as $val) {
                $tmpRow[] = $val;
            }
            //Get unique condition on each row (will be needed for row update)
            $uniqueCondition = PMA_Util::getUniqueCondition(
                $result, // handle
                count($this->_columnNames), // fields_cnt
                $fields_meta, // fields_meta
                $tmpRow, // row
                true, // force_unique
                false, // restrict_to_table
                null // analyzed_sql_results
            );
            //Append it to row array as where_clause
            $row['where_clause'] = $uniqueCondition[0];

            $tmpData = array(
                $_POST['criteriaColumnNames'][0] =>
                    $row[$_POST['criteriaColumnNames'][0]],
                $_POST['criteriaColumnNames'][1] =>
                    $row[$_POST['criteriaColumnNames'][1]],
                'where_clause' => $uniqueCondition[0]
            );
            $tmpData[$dataLabel] = ($dataLabel) ? $row[$dataLabel] : '';
            $data[] = $tmpData;
        }
        unset($tmpData);

        //Displays form for point data and scatter plot
        $titles = array(
            'Browse' => PMA_Util::getIcon(
                'b_browse.png',
                __('Browse foreign values')
            )
        );
        $this->response->addHTML(Template::get('table/zoom_result_form')->render(
            array(
                '_db' => $this->db,
                '_table' => $this->table,
                '_columnNames' => $this->_columnNames,
                '_foreigners' => $this->_foreigners,
                '_columnNullFlags' => $this->_columnNullFlags,
                '_columnTypes' => $this->_columnTypes,
                'titles' => $titles,
                'goto' => $goto,
                'data' => $data
            )
        ));
    }

    public function changeTableInfoAction()
    {
        $field = $_REQUEST['field'];
        if ($field == 'pma_null') {
            $this->response->addJSON('field_type', '');
            $this->response->addJSON('field_collation', '');
            $this->response->addJSON('field_operators', '');
            $this->response->addJSON('field_value', '');
            return;
        }
        $key = array_search($field, $this->_columnNames);
        $properties = $this->getColumnProperties($_REQUEST['it'], $key);
        $this->response->addJSON('field_type', htmlspecialchars($properties['type']));
        $this->response->addJSON('field_collation', $properties['collation']);
        $this->response->addJSON('field_operators', $properties['func']);
        $this->response->addJSON('field_value', $properties['value']);
    }

    public function getDataRowAction()
    {
        $extra_data = array();
        $row_info_query = 'SELECT * FROM `' . $_REQUEST['db'] . '`.`'
            . $_REQUEST['table'] . '` WHERE ' .  $_REQUEST['where_clause'];
        $result = $this->dbi->query(
            $row_info_query . ";", null, PMA_DatabaseInterface::QUERY_STORE
        );
        $fields_meta = $this->dbi->getFieldsMeta($result);
        while ($row = $this->dbi->fetchAssoc($result)) {
            // for bit fields we need to convert them to printable form
            $i = 0;
            foreach ($row as $col => $val) {
                if ($fields_meta[$i]->type == 'bit') {
                    $row[$col] = PMA_Util::printableBitValue(
                        $val, $fields_meta[$i]->length
                    );
                }
                $i++;
            }
            $extra_data['row_info'] = $row;
        }
        $this->response->addJSON($extra_data);
    }

    public function doSelectionAction()
    {
        /**
         * Selection criteria have been submitted -> do the work
         */
        $sql_query = $this->buildSqlQuery();

        /**
         * Add this to ensure following procedures included running correctly.
         */
        $db = $this->db;
        $table = $this->table;
        /**
         * Parse and analyze the query
         */
        include_once 'libraries/parse_analyze.inc.php';

        PMA_executeQueryAndSendQueryResponse(
            $analyzed_sql_results, // analyzed_sql_results
            false, // is_gotofile
            $this->db, // db
            $this->table, // table
            null, // find_real_end
            null, // sql_query_for_bookmark
            null, // extra_data
            null, // message_to_show
            null, // message
            null, // sql_data
            $GLOBALS['goto'], // goto
            $GLOBALS['pmaThemeImage'], // pmaThemeImage
            null, // disp_query
            null, // disp_message
            null, // query_type
            $sql_query, // sql_query
            null, // selectedTables
            null // complete_query
        );
    }

    public function displaySelectionFormAction()
    {
        //$err_url   = 'tbl_select.php' . $err_url;
        $this->url_query .= '&amp;goto=tbl_select.php&amp;back=tbl_select.php';

        include_once 'libraries/tbl_info.inc.php';
        if (! isset($goto)) {
            $goto = PMA_Util::getScriptNameForOption(
                $GLOBALS['cfg']['DefaultTabTable'], 'table'
            );
        }
        // Defines the url to return to in case of error in the next sql statement
        $err_url = $goto . PMA_URL_getCommon(array('db' => $this->db, 'table' => $this->table));
        // Displays the table search form
        $this->response->addHTML(Template::get('table/secondary_tabs')->render(
            array(
                'url_params' => array(
                    'db' => $this->db,
                    'table' => $this->table
                ),
                'sub_tabs' => $this->_getSubTabs()
            )
        ));
        $this->response->addHTML(Template::get('table/selection_form')->render(
            array(
                'searchType' => $this->_searchType,
                'db' => $this->db,
                'table' => $this->table,
                'goto' => $goto,
                'self' => $this,
                'geomColumnFlag' => $this->_geomColumnFlag,
                'columnNames' => $this->_columnNames,
                'columnTypes' => $this->_columnTypes,
                'columnCollations' => $this->_columnCollations,
                'dataLabel' => null
            )
        ));
    }

    public function rangeSearchAction()
    {
        $min_max = $this->getColumnMinMax($_REQUEST['column']);
        $this->response->addJSON('column_data', $min_max);
    }

    public function findAction()
    {
        $preview = $this->getReplacePreview(
            $_POST['columnIndex'],
            $_POST['find'],
            $_POST['replaceWith'],
            $_POST['useRegex'],
            $this->_connectionCharSet
        );
        $this->response->addJSON('preview', $preview);
    }

    public function replaceAction()
    {
        $this->replace(
            $_POST['columnIndex'],
            $_POST['findString'],
            $_POST['replaceWith'],
            $_POST['useRegex'],
            $this->_connectionCharSet);
        $this->response->addHTML(PMA_Util::getMessage(
            __('Your SQL query has been executed successfully.'),
            null, 'success'
        ));
    }

    /**
     * Returns HTML for previewing strings found and their replacements
     *
     * @param int     $columnIndex index of the column
     * @param string  $find        string to find in the column
     * @param string  $replaceWith string to replace with
     * @param boolean $useRegex    to use Regex replace or not
     * @param string  $charSet     character set of the connection
     *
     * @return string HTML for previewing strings found and their replacements
     */
    function getReplacePreview($columnIndex, $find, $replaceWith, $useRegex,
                               $charSet
    ) {
        $column = $this->_columnNames[$columnIndex];
        if ($useRegex) {
            $result = $this->_getRegexReplaceRows(
                $columnIndex, $find, $replaceWith, $charSet
            );
        } else {
            $sql_query = "SELECT "
                . PMA_Util::backquote($column) . ","
                . " REPLACE("
                . PMA_Util::backquote($column) . ", '" . $find . "', '"
                . $replaceWith
                . "'),"
                . " COUNT(*)"
                . " FROM " . PMA_Util::backquote($this->db)
                . "." . PMA_Util::backquote($this->table)
                . " WHERE " . PMA_Util::backquote($column)
                . " LIKE '%" . $find . "%' COLLATE " . $charSet . "_bin"; // here we
            // change the collation of the 2nd operand to a case sensitive
            // binary collation to make sure that the comparison
            // is case sensitive
            $sql_query .= " GROUP BY " . PMA_Util::backquote($column)
                . " ORDER BY " . PMA_Util::backquote($column) . " ASC";

            $result = $this->dbi->fetchResult($sql_query, 0);
        }

        return Template::get('table/replace_preview')->render(
            array(
                'db' => $this->db,
                'table' => $this->table,
                'columnIndex' => $columnIndex,
                'find' => $find,
                'replaceWith' => $replaceWith,
                'useRegex' => $useRegex,
                'result' => $result
            )
        );
    }

    /**
     * Finds and returns Regex pattern and their replacements
     *
     * @param int    $columnIndex index of the column
     * @param string $find        string to find in the column
     * @param string $replaceWith string to replace with
     * @param string $charSet     character set of the connection
     *
     * @return array Array containing original values, replaced values and count
     */
    private function _getRegexReplaceRows($columnIndex, $find, $replaceWith, $charSet)
    {
        $column = $this->_columnNames[$columnIndex];
        $sql_query = "SELECT "
            . PMA_Util::backquote($column) . ","
            . " 1," // to add an extra column that will have replaced value
            . " COUNT(*)"
            . " FROM " . PMA_Util::backquote($this->db)
            . "." . PMA_Util::backquote($this->table)
            . " WHERE " . PMA_Util::backquote($column)
            . " RLIKE '" . PMA_Util::sqlAddSlashes($find) . "' COLLATE "
            . $charSet . "_bin"; // here we
        // change the collation of the 2nd operand to a case sensitive
        // binary collation to make sure that the comparison is case sensitive
        $sql_query .= " GROUP BY " . PMA_Util::backquote($column)
            . " ORDER BY " . PMA_Util::backquote($column) . " ASC";

        $result = $this->dbi->fetchResult($sql_query, 0);

        if (is_array($result)) {
            foreach ($result as $index=>$row) {
                $result[$index][1] = preg_replace(
                    "/" . $find . "/",
                    $replaceWith,
                    $row[0]
                );
            }
        }
        return $result;
    }

    /**
     * Replaces a given string in a column with a give replacement
     *
     * @param int     $columnIndex index of the column
     * @param string  $find        string to find in the column
     * @param string  $replaceWith string to replace with
     * @param boolean $useRegex    to use Regex replace or not
     * @param string  $charSet     character set of the connection
     *
     * @return void
     */
    function replace($columnIndex, $find, $replaceWith, $useRegex, $charSet)
    {
        $column = $this->_columnNames[$columnIndex];
        if ($useRegex) {
            $toReplace = $this->_getRegexReplaceRows(
                $columnIndex, $find, $replaceWith, $charSet
            );
            $sql_query = "UPDATE " . PMA_Util::backquote($this->db)
                . "." . PMA_Util::backquote($this->table)
                . " SET " . PMA_Util::backquote($column) . " = CASE";
            if (is_array($toReplace)) {
                foreach ($toReplace as $row) {
                    $sql_query .= "\n WHEN " . PMA_Util::backquote($column)
                        . " = '" . PMA_Util::sqlAddSlashes($row[0])
                        . "' THEN '" . PMA_Util::sqlAddSlashes($row[1]) . "'";
                }
            }
            $sql_query .= " END"
                . " WHERE " . PMA_Util::backquote($column)
                . " RLIKE '" . PMA_Util::sqlAddSlashes($find) . "' COLLATE "
                . $charSet . "_bin"; // here we
            // change the collation of the 2nd operand to a case sensitive
            // binary collation to make sure that the comparison
            // is case sensitive
        } else {
            $sql_query = "UPDATE " . PMA_Util::backquote($this->db)
                . "." . PMA_Util::backquote($this->table)
                . " SET " . PMA_Util::backquote($column) . " ="
                . " REPLACE("
                . PMA_Util::backquote($column) . ", '" . $find . "', '"
                . $replaceWith
                . "')"
                . " WHERE " . PMA_Util::backquote($column)
                . " LIKE '%" . $find . "%' COLLATE " . $charSet . "_bin"; // here we
            // change the collation of the 2nd operand to a case sensitive
            // binary collation to make sure that the comparison
            // is case sensitive
        }
        $this->dbi->query(
            $sql_query, null, PMA_DatabaseInterface::QUERY_STORE
        );
        $GLOBALS['sql_query'] = $sql_query;
    }

    /**
     * Finds minimum and maximum value of a given column.
     *
     * @param string $column Column name
     *
     * @return array
     */
    public function getColumnMinMax($column)
    {
        $sql_query = 'SELECT MIN(' . PMA_Util::backquote($column) . ') AS `min`, '
            . 'MAX(' . PMA_Util::backquote($column) . ') AS `max` '
            . 'FROM ' . PMA_Util::backquote($this->db) . '.'
            . PMA_Util::backquote($this->table);

        $result = $this->dbi->fetchSingleRow($sql_query);

        return $result;
    }

    /**
     * Returns an array with necessary configurations to create
     * sub-tabs in the table_select page.
     *
     * @return array Array containing configuration (icon, text, link, id, args)
     * of sub-tabs
     */
    private function _getSubTabs()
    {
        $subtabs = array();
        $subtabs['search']['icon'] = 'b_search.png';
        $subtabs['search']['text'] = __('Table search');
        $subtabs['search']['link'] = 'tbl_select.php';
        $subtabs['search']['id'] = 'tbl_search_id';
        $subtabs['search']['args']['pos'] = 0;

        $subtabs['zoom']['icon'] = 'b_select.png';
        $subtabs['zoom']['link'] = 'tbl_zoom_select.php';
        $subtabs['zoom']['text'] = __('Zoom search');
        $subtabs['zoom']['id'] = 'zoom_search_id';

        $subtabs['replace']['icon'] = 'b_find_replace.png';
        $subtabs['replace']['link'] = 'tbl_find_replace.php';
        $subtabs['replace']['text'] = __('Find and replace');
        $subtabs['replace']['id'] = 'find_replace_id';

        return $subtabs;
    }

    /**
     * Builds the sql search query from the post parameters
     *
     * @return string the generated SQL query
     */
    private function buildSqlQuery()
    {
        $sql_query = 'SELECT ';

        // If only distinct values are needed
        $is_distinct = (isset($_POST['distinct'])) ? 'true' : 'false';
        if ($is_distinct == 'true') {
            $sql_query .= 'DISTINCT ';
        }

        // if all column names were selected to display, we do a 'SELECT *'
        // (more efficient and this helps prevent a problem in IE
        // if one of the rows is edited and we come back to the Select results)
        if (isset($_POST['zoom_submit']) || ! empty($_POST['displayAllColumns'])) {
            $sql_query .= '* ';
        } else {
            $sql_query .= implode(
                ', ',
                PMA_Util::backquote($_POST['columnsToDisplay'])
            );
        } // end if

        $sql_query .= ' FROM '
            . PMA_Util::backquote($_POST['table']);
        $whereClause = $this->_generateWhereClause();
        $sql_query .= $whereClause;

        // if the search results are to be ordered
        if (isset($_POST['orderByColumn']) && $_POST['orderByColumn'] != '--nil--') {
            $sql_query .= ' ORDER BY '
                . PMA_Util::backquote($_POST['orderByColumn'])
                . ' ' . $_POST['order'];
        } // end if
        return $sql_query;
    }

    /**
     * Provides a column's type, collation, operators list, and criteria value
     * to display in table search form
     *
     * @param integer $search_index Row number in table search form
     * @param integer $column_index Column index in ColumnNames array
     *
     * @return array Array containing column's properties
     */
    public function getColumnProperties($search_index, $column_index)
    {
        $selected_operator = (isset($_POST['criteriaColumnOperators'])
            ? $_POST['criteriaColumnOperators'][$search_index] : '');
        $entered_value = (isset($_POST['criteriaValues'])
            ? $_POST['criteriaValues'] : '');
        $titles = array(
            'Browse' => PMA_Util::getIcon(
                'b_browse.png', __('Browse foreign values')
            )
        );
        //Gets column's type and collation
        $type = $this->_columnTypes[$column_index];
        $collation = $this->_columnCollations[$column_index];
        //Gets column's comparison operators depending on column type
        $func = Template::get('table/column_comparison_operators')->render(
            array(
                'search_index' => $search_index,
                'columnTypes' => $this->_columnTypes,
                'column_index' => $column_index,
                'columnNullFlags' => $this->_columnNullFlags,
                'selected_operator' => $selected_operator
            )
        );
        //Gets link to browse foreign data(if any) and criteria inputbox
        $foreignData = PMA_getForeignData(
            $this->_foreigners, $this->_columnNames[$column_index], false, '', ''
        );
        $value = Template::get('table/input_box')->render(
            array(
                'str' => '',
                'column_type' => (string) $type,
                'column_id' => 'fieldID_',
                'in_zoom_search_edit' => false,
                '_foreigners' => $this->_foreigners,
                'column_name' => $this->_columnNames[$column_index],
                'foreignData' => $foreignData,
                'table' => $this->table,
                'column_index' => $search_index,
                'foreignMaxLimit' => $GLOBALS['cfg']['ForeignKeyMaxLimit'],
                'criteriaValues' => $entered_value,
                'db' => $this->db,
                'titles' => $titles,
                'in_fbs' => false
            )
        );
        return array(
            'type' => $type,
            'collation' => $collation,
            'func' => $func,
            'value' => $value
        );
    }

    /**
     * Generates the where clause for the SQL search query to be executed
     *
     * @return string the generated where clause
     */
    private function _generateWhereClause()
    {
        if (isset($_POST['customWhereClause'])
            && trim($_POST['customWhereClause']) != ''
        ) {
            return ' WHERE ' . $_POST['customWhereClause'];
        }

        // If there are no search criteria set or no unary criteria operators,
        // return
        if (! isset($_POST['criteriaValues'])
            && ! isset($_POST['criteriaColumnOperators'])
        ) {
            return '';
        }

        // else continue to form the where clause from column criteria values
        $fullWhereClause = array();
        reset($_POST['criteriaColumnOperators']);
        while (list($column_index, $operator) = each(
            $_POST['criteriaColumnOperators']
        )) {

            $unaryFlag =  $GLOBALS['PMA_Types']->isUnaryOperator($operator);
            $tmp_geom_func = isset($geom_func[$column_index])
                ? $geom_func[$column_index] : null;

            $whereClause = $this->_getWhereClause(
                $_POST['criteriaValues'][$column_index],
                $_POST['criteriaColumnNames'][$column_index],
                $_POST['criteriaColumnTypes'][$column_index],
                $operator,
                $unaryFlag,
                $tmp_geom_func
            );

            if ($whereClause) {
                $fullWhereClause[] = $whereClause;
            }
        } // end while

        if ($fullWhereClause) {
            return ' WHERE ' . implode(' AND ', $fullWhereClause);
        }
        return '';
    }

    /**
     * Return the where clause in case column's type is ENUM.
     *
     * @param mixed  $criteriaValues Search criteria input
     * @param string $func_type      Search function/operator
     *
     * @return string part of where clause.
     */
    private function _getEnumWhereClause($criteriaValues, $func_type)
    {
        if (! is_array($criteriaValues)) {
            $criteriaValues = explode(',', $criteriaValues);
        }
        $enum_selected_count = count($criteriaValues);
        if ($func_type == '=' && $enum_selected_count > 1) {
            $func_type    = 'IN';
            $parens_open  = '(';
            $parens_close = ')';

        } elseif ($func_type == '!=' && $enum_selected_count > 1) {
            $func_type    = 'NOT IN';
            $parens_open  = '(';
            $parens_close = ')';

        } else {
            $parens_open  = '';
            $parens_close = '';
        }
        $enum_where = '\''
            . PMA_Util::sqlAddSlashes($criteriaValues[0]) . '\'';
        for ($e = 1; $e < $enum_selected_count; $e++) {
            $enum_where .= ', \''
                . PMA_Util::sqlAddSlashes($criteriaValues[$e]) . '\'';
        }

        return ' ' . $func_type . ' ' . $parens_open
        . $enum_where . $parens_close;
    }

    /**
     * Return the where clause for a geometrical column.
     *
     * @param mixed  $criteriaValues Search criteria input
     * @param string $names          Name of the column on which search is submitted
     * @param string $func_type      Search function/operator
     * @param string $types          Type of the field
     * @param bool   $geom_func      Whether geometry functions should be applied
     *
     * @return string part of where clause.
     */
    private function _getGeomWhereClause($criteriaValues, $names,
                                         $func_type, $types, $geom_func = null
    ) {
        $geom_unary_functions = array(
            'IsEmpty' => 1,
            'IsSimple' => 1,
            'IsRing' => 1,
            'IsClosed' => 1,
        );
        $where = '';

        // Get details about the geometry functions
        $geom_funcs = PMA_Util::getGISFunctions($types, true, false);
        // New output type is the output type of the function being applied
        $types = $geom_funcs[$geom_func]['type'];

        // If the function takes a single parameter
        if ($geom_funcs[$geom_func]['params'] == 1) {
            $backquoted_name = $geom_func . '(' . PMA_Util::backquote($names) . ')';
        } else {
            // If the function takes two parameters
            // create gis data from the criteria input
            $gis_data = PMA_Util::createGISData($criteriaValues);
            $where = $geom_func . '(' . PMA_Util::backquote($names)
                . ',' . $gis_data . ')';
            return $where;
        }

        // If the where clause is something like 'IsEmpty(`spatial_col_name`)'
        if (isset($geom_unary_functions[$geom_func])
            && trim($criteriaValues) == ''
        ) {
            $where = $backquoted_name;

        } elseif (in_array($types, PMA_Util::getGISDatatypes())
            && ! empty($criteriaValues)
        ) {
            // create gis data from the criteria input
            $gis_data = PMA_Util::createGISData($criteriaValues);
            $where = $backquoted_name . ' ' . $func_type . ' ' . $gis_data;
        }
        return $where;
    }

    /**
     * Return the where clause for query generation based on the inputs provided.
     *
     * @param mixed  $criteriaValues Search criteria input
     * @param string $names          Name of the column on which search is submitted
     * @param string $types          Type of the field
     * @param string $func_type      Search function/operator
     * @param bool   $unaryFlag      Whether operator unary or not
     * @param bool   $geom_func      Whether geometry functions should be applied
     *
     * @return string generated where clause.
     */
    private function _getWhereClause($criteriaValues, $names, $types,
                                     $func_type, $unaryFlag, $geom_func = null
    ) {
        // If geometry function is set
        if ($geom_func != null && trim($geom_func) != '') {
            return $this->_getGeomWhereClause(
                $criteriaValues, $names, $func_type, $types, $geom_func
            );
        }

        $backquoted_name = PMA_Util::backquote($names);
        $where = '';
        if ($unaryFlag) {
            $where = $backquoted_name . ' ' . $func_type;

        } elseif (strncasecmp($types, 'enum', 4) == 0 && ! empty($criteriaValues)) {
            $where = $backquoted_name;
            $where .= $this->_getEnumWhereClause($criteriaValues, $func_type);

        } elseif ($criteriaValues != '') {
            // For these types we quote the value. Even if it's another type
            // (like INT), for a LIKE we always quote the value. MySQL converts
            // strings to numbers and numbers to strings as necessary
            // during the comparison
            if (preg_match('@char|binary|blob|text|set|date|time|year@i', $types)
                || /*overload*/mb_strpos(' ' . $func_type, 'LIKE')
            ) {
                $quot = '\'';
            } else {
                $quot = '';
            }

            // LIKE %...%
            if ($func_type == 'LIKE %...%') {
                $func_type = 'LIKE';
                $criteriaValues = '%' . $criteriaValues . '%';
            }
            if ($func_type == 'REGEXP ^...$') {
                $func_type = 'REGEXP';
                $criteriaValues = '^' . $criteriaValues . '$';
            }

            if ('IN (...)' != $func_type
                && 'NOT IN (...)' != $func_type
                && 'BETWEEN' != $func_type
                && 'NOT BETWEEN' != $func_type
            ) {
                if ($func_type == 'LIKE %...%' || $func_type == 'LIKE') {
                    $where = $backquoted_name . ' ' . $func_type . ' ' . $quot
                        . PMA_Util::sqlAddSlashes($criteriaValues, true) . $quot;
                } else {
                    $where = $backquoted_name . ' ' . $func_type . ' ' . $quot
                        . PMA_Util::sqlAddSlashes($criteriaValues) . $quot;
                }
                return $where;
            }
            $func_type = str_replace(' (...)', '', $func_type);

            //Don't explode if this is already an array
            //(Case for (NOT) IN/BETWEEN.)
            if (is_array($criteriaValues)) {
                $values = $criteriaValues;
            } else {
                $values = explode(',', $criteriaValues);
            }
            // quote values one by one
            $emptyKey = false;
            foreach ($values as $key => &$value) {
                if ('' === $value) {
                    $emptyKey = $key;
                    $value = 'NULL';
                    continue;
                }
                $value = $quot . PMA_Util::sqlAddSlashes(trim($value))
                    . $quot;
            }

            if ('BETWEEN' == $func_type || 'NOT BETWEEN' == $func_type) {
                $where = $backquoted_name . ' ' . $func_type . ' '
                    . (isset($values[0]) ? $values[0] : '')
                    . ' AND ' . (isset($values[1]) ? $values[1] : '');
            } else { //[NOT] IN
                if (false !== $emptyKey) {
                    unset($values[$emptyKey]);
                }
                $wheres = array();
                if (!empty($values)) {
                    $wheres[] = $backquoted_name . ' ' . $func_type
                        . ' (' . implode(',', $values) . ')';
                }
                if (false !== $emptyKey) {
                    $wheres[] = $backquoted_name . ' IS NULL';
                }
                $where = implode(' OR ', $wheres);
                if (1 < count($wheres)) {
                    $where = '(' . $where . ')';
                }
            }
        } // end if

        return $where;
    }
}
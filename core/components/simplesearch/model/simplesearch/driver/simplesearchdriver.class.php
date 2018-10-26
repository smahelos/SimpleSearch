<?php

/**
 * Abstract class for providing custom search driver implementations.
 * 
 * @package simplesearch
 */
abstract class SimpleSearchDriver
{
    /** @var SimpleSearch A reference to the SimpleSearch class */
    public $search;
    /** @var modX A reference to the modX class */
    public $modx;
    /** @var array An array of configuration properties */
    public $config;
    /** @var array An array of search scores. Optionally used. */
    public $searchScores = array();
    /** @var array The IDs of the search results */
    public $ids = array();

    /**
     * Construct and return the driver object, and run the initialize method.
     * This method may be extended in your driver implementations, but
     * SimpleSearchDriver may not be instantiated by itself - it must
     * be extended.
     * 
     * @param SimpleSearch $search
     * @param array $config
     */
    function __construct(SimpleSearch &$search, array $config = array()) {
        $this->search =& $search;
        $this->modx   =& $search->modx;
        $this->config = array_merge($config, array());

        $this->initialize();
    }

    /**
     * Initialize the driver after loading it.
     * 
     * @abstract
     * @return void
     */
    abstract public function initialize();

    /**
     * Run the search against a sanitized query string. Must return an array in this format:
     * array(
     *   'results' => $arrayOfResourceArrays
     *   'total' => $countOfTotalNumberOfResults
     * )
     *
     * Note that the results index must contain an array of arrays (not Resource Objects).     *
     *
     * @abstract
     * @param string $string
     * @param array $scriptProperties The scriptProperties array from the SimpleSearch snippet
     * @return array
     */
    abstract public function search($string, array $scriptProperties = array());

    /**
     * Index a Resource.
     *
     * @abstract
     * @param array $fields
     * @return boolean
     */
    abstract public function index(array $fields);

    /**
     * Remove a Resource from the index.
     *
     * @abstract
     * @param string|int $id
     * @return boolean
     */
    abstract public function removeIndex($id);

    /**
     * Scores and sorts the results based on 'fieldPotency'
     *
     * @param array $resources
     * @param array $scriptProperties The $scriptProperties array
     * @return array Scored and sorted search results
     */
    protected function sortResults(array $resources, array $scriptProperties) {
        /* Vars */
        $searchStyle  = $this->modx->getOption('searchStyle', $scriptProperties, 'partial');
        $docFields    = explode(',', $this->modx->getOption('docFields', $scriptProperties, 'pagetitle,longtitle,alias,description,introtext,content'));
        $fieldPotency = array_map('trim', explode(',', $this->modx->getOption('fieldPotency', $scriptProperties, '')));
        foreach ($fieldPotency as $key => $field) {
            unset($fieldPotency[$key]);
            $arr = explode(':', $field);
            if (!empty($arr[1])) {
                $fieldPotency[$arr[0]] = $arr[1];
            }
        }

        /* Score */
        /** @var modResource $resource */
        foreach ($resources as $resourceId => $resource) {
            foreach ($docFields as $field) {
                $potency = (array_key_exists($field, $fieldPotency)) ? (int) $fieldPotency[$field] : 1;
                foreach ($this->search->searchArray as $term) {
                    $queryTerm       = preg_quote($term,'/');
                    $regex           = ($searchStyle == 'partial') ? "/{$queryTerm}/i" : "/\b{$queryTerm}\b/i";
                    $numberOfMatches = preg_match_all($regex, $resource->{$field}, $matches);

                    if (empty($this->searchScores[$resourceId])) {
                        $this->searchScores[$resourceId] = 0;
                    }

                    $this->searchScores[$resourceId] += $numberOfMatches * $potency;
                }
            }
        }

        $includeTVList = array_map('trim', explode(',', $this->modx->getOption('includeTVList', $scriptProperties, '')));
        if ((int) $scriptProperties['includeTVs'] === 1 && count($includeTVList) > 0) {
            foreach ($includeTVList as $field) {
                $potency = (array_key_exists($field, $fieldPotency)) ? (int) $fieldPotency[$field] : 1;

                foreach ($resources as $resourceId => $resource) {
                    foreach ($this->search->searchArray as $term) {
                        $queryTerm       = preg_quote($term, '/');
                        $regex           = ($searchStyle === 'partial') ? "/{$queryTerm}/i" : "/\b{$queryTerm}\b/i";
                        $numberOfMatches = preg_match_all($regex, $resource->getTVValue($field), $matches);

                        if (empty($this->searchScores[$resourceId])) {
                            $this->searchScores[$resourceId] = 0;
                        }

                        $this->searchScores[$resourceId] += $numberOfMatches * $potency;
                    }
                }
            }
        }

        /* Sort */
        arsort($this->searchScores);

        $list = array();
        foreach ($this->searchScores as $resourceId => $score) {
            array_push($list, $resources[$resourceId]);
        }

        return $list;
    }

    /**
     * Process the passed IDs
     *
     * @param string $ids The IDs to search
     * @param string $type The type of id filter
     * @param integer $depth The depth in the Resource tree to filter by
     * @return string Comma delimited string of the IDs
     */
    protected function processIds($ids = '', $type = 'parents', $depth = 10) {
        if ($ids === '') {
            return '';
        }

        $ids = $this->cleanIds($ids);
        switch ($type) {
            case 'parents':
                $idArray = explode(',', $ids);
                $ids     = $idArray;

                foreach ($idArray as $id) {
                    $ids = array_merge($ids, $this->modx->getChildIds($id, $depth));
                }

                $ids = array_unique($ids);

                sort($ids);
                break;
        }

        $this->ids = $ids;

        return $this->ids;
    }

    /**
     * Clean IDs
     *
     * @param string $ids Comma delimited string of IDs
     * @return string Cleaned comma delimited string of IDs
     */
    public function cleanIds($ids) {
        return preg_replace(
            array(
                '`(,)+`',  //Multiple commas
                '`^(,)`',  //Comma on first position
                '`(,)$`'   //Comma on last position
            ),
            array(
                ',',
                '',
                ''
            ),
            $ids
        );
    }
}

<?php
/**
 * User: quant61
 * Date: 2016-07-25
 *
 *
 *
 * correct pagination needs to know total number of objects
 * the're no need to retrieve all objects in db
 * but pagination needs to know total number of objects
 * $repository->findBy() can get objects, but it cannot get total count without getting all objects
 * it also cannot use complex conditions(joins, for example)
 *
 * so We need to use queryBuilder
 * objects and total count cannot be retreived with one query,
 * so 2 db queries are used: dataQuery and countQuery
 *
 * dataQuery retrieves objects themselves
 * it also uses offset and limit
 * countQuery gets total number of objects
 *
 * both queries should use identical search condition
 */



namespace WebAnt\CoreBundle\Component;

use Symfony\Component\HttpKernel\Exception\HttpException;
use WebAnt\CoreBundle\Component\Helpers;

/**
 * Class QueryBuilders
 * @deprecated use getPaginatedList instead;
 *             knp_paginator do the work this class is designed for
 *
 * @package WebAnt\CoreBundle\Component
 *
 */
class QueryBuilders
{
    /** @var $good */
    private $dataQuery;
    private $countQuery;

    public function getDataQuery(){
        return $this->dataQuery;
    }
    public function getCountQuery(){
        return $this->countQuery;
    }

    public function __construct($objectClass = null, $search = []) {
        /**
         * !!!!!
         * I didn't find a good way to get entityManager from here
         * All found solutions are tricky and are bad codestyle
         * from http://stackoverflow.com/a/14877982/6076531
         */
        global $kernel;
        if ('AppCache' == get_class($kernel)) {
            $kernel = $kernel->getKernel();
        }
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        /** @var $em \Doctrine\ORM\EntityManager */


        $this->dataQuery = $em->createQueryBuilder();
        $this->countQuery = $em->createQueryBuilder();

        if($search instanceof \Symfony\Component\HttpFoundation\Request){
            $search = $search->query->all();
        }

        if(is_string($objectClass)){
            $this->setObjectClass($objectClass);
            $this->appendSearch($search);
        }

    }

    /**
     * @param string $objectClass - object class of requested object
     * @param string $alias - alias of object for using in DQL
     * @return $this
     */
    public function setObjectClass($objectClass, $alias = 'x'){
        $this->objectClass = $objectClass;
        $this->alias = $alias;

        $this->dataQuery->select($alias);
        $this->dataQuery->from($objectClass, $alias);
        $this->countQuery->select("count($alias) as num");
        $this->countQuery->from($objectClass, $alias);
        return $this;
    }

    /**
     * @param array $search search array
     * @param array $config = [
     *   'objectClass' => class of requested entity(usable if search by subobject's properties)
     *   'alias' => alias of object (if search by subobject)
     * ]
     * @return $this
     */
    public function appendSearch($search, $config = []){

        $defaults = [
            'objectClass' => $this->objectClass,
            'alias' => $this->alias,
        ];

        $config = array_merge($defaults, $config);
        Helpers::appendSearchToQueryBuilder($this->dataQuery, $search, $config);
        Helpers::appendSearchToQueryBuilder($this->countQuery, $search, $config);

        $start = Helpers::getKeyIfExists($search, 'start');
        if(is_numeric($start)){
            $this->dataQuery->setFirstResult( (int)$start );
        }
        $limit = Helpers::getKeyIfExists($search, 'limit');
        if(is_numeric($limit)){
            $this->dataQuery->setMaxResults( (int)$limit );
        }
        return $this;
    }

    // example: $queryBuilders->modify('andWhere', ['x.id > 500']);

    /**
     * modify both queryBuilders
     * example:
     *   $queryBuilders->modify('join', ['x.shop', 'shop']);
     *
     * @param string $func - function name
     * @param array $args - arguments passed to the function
     *
     * @return $this
     */
    public function modify($func, $args = []){
        call_user_func_array(array($this->dataQuery, $func), $args);
        call_user_func_array(array($this->countQuery, $func), $args);
        return $this;
    }


    /**
     * execute queries
     * @return array
     */
    public function exec(){
        $start = microtime(true);
        $response['items'] = $this->dataQuery->getQuery()->getResult();
        $response['_query_time'] = microtime(true) - $start;
        $start = microtime(true);
        $response['count'] = (int)$this->countQuery->getQuery()->getSingleScalarResult();
        $response['_count_query_time'] = microtime(true) - $start;

        return $response;
    }

    public function __invoke(){
        return $this->exec();
    }

} 
<?php
/**
 * This file is part of the WebAnt CoreBundle package.
 *
 * Yuri Kovalev <u@webant.ru>
 * Vladimir Daron <v@webant.ru>
 *
 */

namespace WebAnt\CoreBundle\Controller;

use WebAnt\CoreBundle\Util\CamelCase;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManager;
use FOS\RestBundle\Controller\Annotations\View;
use FOS\RestBundle\Controller\FOSRestController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use JMS\Serializer\SerializationContext;


abstract class AbstractController extends FOSRestController
{
    protected $objectClass;
    protected $objectKey = 'id';

    /**
     * Создание обьекта
     *
     * @param   array $requestArray
     *
     * @return  Object
     */
    public function createObject($requestArray)
    {
        $em     = $this->getDoctrine()->getManager();
        $object = $this->arrayToObject($requestArray);

        try {
            $em->flush();
        } catch
        (\Exception $e) {
            throw new HttpException(400, 'Error create: ' . $e->getMessage());
        }

        return $object;
    }

    /**
     * Получить объект по ключу
     *
     * @param   integer $keyValue
     * @param   array   $findArray
     *
     * @return  Object
     */
    public function getObject($keyValue = false, $findArray = [])
    {
        $repository   = $this->getObjectRepository();
        $findFunction = 'findOneBy';
        ($keyValue === false) ?: $findArray[$this->objectKey] = $keyValue;
        $object = $repository->$findFunction($findArray);
        if (!$object) {
            throw new HttpException(404, 'No object this key (' . $keyValue . ').');
        }

        return $object;
    }

    public function getKeyIfExists($array, $key, $default = null){
        // missing key is casted to default, but null key isn't
        if(array_key_exists($key, $array)){
            return $array[$key];
        }
        return $default;
    }

    /**
     * получить queryBuilderы для запроса
     *
     * maybe, use new class instance instead?
     *
     * @param Request|array $search - параметры поиска
     * @param $defaults - поиск по умолчанию // do we need it?
     *
     * @return  array - [countQuery, dataQuery: QueryBuilder]
     */
    public function getQueryBuilders($search, $defaults = []){

        // fix search
        if($search instanceof Request){
            $search = $search->query->all();
        }
        $search = array_merge($defaults, $search);

        // init query builders
        $em      = $this->getDoctrine()->getManager();
        // data query retrieves objects
        $dataQuery  = $em->createQueryBuilder();
        $dataQuery->select('x');
        $dataQuery->from($this->objectClass, 'x');

        // count query gets total number of objects so pagination may work well
        $countQuery = $em->createQueryBuilder();
        $countQuery->select('count(x) as num');
        $countQuery->from($this->objectClass, 'x');

        // append pagination
        $limit = $this->getKeyIfExists($search, 'limit');
        if(is_numeric($limit)){
            $dataQuery->setMaxResults( (int)$limit );
        }
        $start = $this->getKeyIfExists($search, 'start');
        if(is_numeric($start)){
            $dataQuery->setFirstResult( (int)$start );
        }

        // append query
        $this->appendSearchToQueryBuilder($dataQuery, $search);
        $this->appendSearchToQueryBuilder($countQuery, $search);

        // maybe, turn it into class?
        return [
            'dataQuery' => $dataQuery,
            'countQuery' => $countQuery,
        ];
    }


    /**
     * appends search from array or request to queryBuilder.
     * it can also append params of subobject
     *
     * @param $qb
     * @param array|Request $search
     * @param array $config
     *    $config = [
     *      'alias' => (string) alias of object or subobject. could be used for joined
     *      'objectClass' => (string) objectClass of checked object
     *    ]
     */
    public function appendSearchToQueryBuilder($qb, $search, $config = []){
        $objectClass = $this->getKeyIfExists($config, 'objectClass', $this->objectClass);
        $alias = $this->getKeyIfExists($config, 'alias', 'x');

        if($search instanceof Request){
            $search = $search->query->all();
        }

        // TODO: add support multiple orderby's
        $orderby     = $this->getKeyIfExists($search, 'orderby');
        $orderbydesc = $this->getKeyIfExists($search, 'orderbydesc');

        $reflect = new \ReflectionClass($objectClass);
        $properties = $reflect->getProperties();

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $fullPropertyName = "${alias}.$propertyName";
            $parameterName  = "${alias}___$propertyName";

            if(array_key_exists($propertyName, $search)){
                $value = $search[$propertyName];

                if(is_string($value)){
                    $value = explode("|", $value); // check if it uses "1|2|3" syntax
                    if(count($value) == 1){ // otherwise
                        $value = $value[0]; // convert to string again
                    }
                }

                if(is_null($value)){
                    // you can search by null too. that is why I use array_key_exists instead of isset
                    $andWhere = "$fullPropertyName IS NULL";
                } else if(is_array($value)){
                    $andWhere = "$fullPropertyName IN(:$parameterName)";
                } else { // string or number or bool
                    $andWhere = "$fullPropertyName  = :$parameterName ";
                }

                $qb->andWhere($andWhere);

                if(isset($value)){
                    $qb->setParameter($parameterName, $value);
                }
            } // end if exist

            if ($orderby == $propertyName) {
                $qb->orderBy("$fullPropertyName", 'ASC');
            } elseif ($orderbydesc == $propertyName) {
                $qb->orderBy("$fullPropertyName", 'DESC');
            }
        } // end foreach possible properties

        return $qb;

    }

    public function execQueryBuildersPair($pair){
        return [
            'items' => $pair['dataQuery']->getQuery()->getResult(),
            'count' => (int)$pair['countQuery']->getQuery()->getSingleScalarResult(),
        ];
    }


    /**
     * Получить список объектов
     *
     * @param   Request $request   - Запрос
     * @param   array   $findArray - Массив параметров поиска
     *
     * @return  array
     */
    public function getListObject(Request $request, $findArray = [])
    {
        $repository = $this->getObjectRepository();

        $reflect    = new \ReflectionClass($this->objectClass);
        $properties = $reflect->getProperties();
        $orderArray = [];
        $limit      = null;
        $offset     = null;

        $em         = $this->getDoctrine()->getManager();
        $countQuery = $em->createQueryBuilder();
        $countQuery->select('count(x) as num');
        $countQuery->from($this->objectClass, 'x');


        // append find from $findArray to countQuery
        foreach ($findArray as $key => $value) {
            if(is_array($value)){
                $countQuery->andWhere("x.$key IN(:$key)");
            } else {
                $countQuery->andWhere("x.$key  = :$key ");
            }

            $countQuery->setParameter($key, $value);
        }

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            if ($request->query->get($propertyName, false)) {
                $findArray[$propertyName] = explode("|", $request->query->get($propertyName));
                if(is_array($findArray[$propertyName])){
                    $countQuery->andWhere("x.$propertyName IN(:$propertyName)");
                } else {
                    $countQuery->andWhere("x.$propertyName  = :$propertyName ");
                }

                $countQuery->setParameter($propertyName, $findArray[$propertyName]);
            }
            if ($request->query->get("orderby") == $propertyName) {
                $orderArray[$propertyName] = 'ASC';
            } elseif ($request->query->get("orderbydesc") == $propertyName) {
                $orderArray[$propertyName] = 'DESC';
            }
        }
        if (preg_match('/^[0-9]+$/', $request->query->get("limit"))) {
            $limit = (int)$request->query->get("limit");
        }
        if (
            preg_match('/^[0-9]+$/', $request->query->get("start")) &&
            preg_match('/^[0-9]+$/', $request->query->get("limit"))
        ) {
            $offset = (int)$request->query->get("start");
        }
        $start = microtime(true);
        $objects = $repository->findBy($findArray, $orderArray, $limit, $offset);
        $response['_query_time'] = microtime(true) - $start;

        $start = microtime(true);
        $response['count'] = +$countQuery->getQuery()->getResult()[0]['num'];
        $response['_count_query_time'] = microtime(true) - $start;

        $response['items'] = $objects;


        return $response;
    }
    /**
     * Обновление обьекта
     *
     * @param      $requestArray
     * @param      $keyValue
     * @param null $beforeFunction
     * @param null $afterFunction
     *
     * @return Object
     *
     * @throws HttpException
     */
    public function updateObject(
        $requestArray,
        $keyValue,
        $beforeFunction = null,
        $afterFunction = null
    ) {

        $em         = $this->getDoctrine()->getManager();
        $repository = $this->getObjectRepository();

        if (is_string($this->objectKey)) {
            $findFunction = 'findOneBy' . ucfirst($this->objectKey);
            $object       = $repository->$findFunction($keyValue);
        } else if (is_array($this->objectKey)) {
            // @from http://stackoverflow.com/a/21524085/6076531
            $object = $repository->findOneBy($this->objectKey);
        }


        if (!is_object($object)) {
            throw new HttpException(404, 'No object this key (' . $keyValue . ').');
        }

        $object = $this->arrayToObject($requestArray, $object);

        if (!is_null($beforeFunction)) {
            call_user_func($beforeFunction, $object);
        }

        try {
            $em->flush();

            if (!is_null($afterFunction)) {
                call_user_func($afterFunction, $object);
            }
        } catch
        (\Exception $e) {
            throw new HttpException(400, 'Error update: ' . $e->getMessage());
        }

        return $object;
    }

    /**
     * Обновление или создание обьекта
     *
     * @param      $requestArray
     * @param      $keyValue
     * @param null $beforeFunction
     * @param null $afterFunction
     *
     * @return Object
     *
     * @throws HttpException
     */
    public function updateOrCreateObject(
        $requestArray,
        $keyValue,
        $beforeFunction = null,
        $afterFunction = null
    ) {
        $em         = $this->getDoctrine()->getManager();
        $repository = $this->getObjectRepository();

        if (is_string($this->objectKey)) {
            $findFunction = 'findOneBy' . ucfirst($this->objectKey);
            $object       = $repository->$findFunction($keyValue);
        } else if (is_array($this->objectKey)) {
            // @from http://stackoverflow.com/a/21524085/6076531
            $object = $repository->findOneBy($this->objectKey);
        }

        if (!is_null($beforeFunction)) {
            call_user_func($beforeFunction, $object);
        }

        if (!is_object($object)) {
            $object = $this->arrayToObject($requestArray);
        } else {
            $object = $this->arrayToObject($requestArray, $object);
        }

        try {
            $em->flush();

            if (!is_null($afterFunction)) {
                call_user_func($afterFunction, $object);
            }
        } catch
        (\Exception $e) {
            throw new HttpException(400, 'Error create or update: ' . $e->getMessage());
        }

        return $object;
    }

    /**
     * Удаление обьекта
     *
     * @param         $keyValue
     * @param   array $arrayClass
     * @param   array $arrayField
     * @param   array $arrayCallBack
     * @param   null  $beforeFunction
     * @param   null  $afterFunction
     *
     * @return  array
     *
     * @throws  \HttpException
     */
    public function  deleteObject(
        $keyValue,
        $arrayClass = [],
        $arrayField = [],
        $arrayCallBack = [],
        $beforeFunction = null,
        $afterFunction = null
    ) {
        $em           = $this->getDoctrine()->getManager();
        $repository   = $this->getObjectRepository();
        $findFunction = 'findOneBy' . ucfirst($this->objectKey);// Зачем конкатанация objectKey ?
        $object       = $repository->$findFunction((int)$keyValue);

        if (!is_object($object)) {
            throw new HttpException(404, 'No object this key (' . $keyValue . ').');
        }
        if (!is_null($beforeFunction)) {
            call_user_func($beforeFunction, $object);
        }
        $object->Del = true;

        //ищем зависимые объекты
        $countClass = count($arrayClass);
        for ($i = 0; $i < $countClass; $i++) {
            $items = $em->getRepository($arrayClass[$i])->findBy([$arrayField[$i] => $keyValue]);
            if (count($items) > 0) {
                $callBackFunction = $arrayCallBack[$i];
                foreach ($items as $item) {
                    call_user_func($callBackFunction, $em, $item, $object);
                }
            }
        }

        if ($object->Del) {
            $em->remove($object);
        } else {
            throw new \HttpException(423, 'Deleting canceled');
        }

        try {
            $em->flush();

            if (!is_null($afterFunction)) {
                call_user_func($afterFunction);
            }
        } catch (\Exception $e) {
            throw new HttpException(409, 'Error delete: ' . $e->getMessage());
        }

        return ['ms' => 'ok'];
    }

    /**
     * Заполнение объекта из массива
     *
     * @param   array          $requestArray
     * @param   boolean|Object $object
     *
     * @return  Object
     */
    public function arrayToObject($requestArray, $object = false)
    {
        if (!is_array($requestArray)) {
            throw new HttpException(400, 'Error object create');
        }

        $em         = $this->getDoctrine()->getManager();
        $reflect    = new \ReflectionClass($this->objectClass);
        $namespace  = $reflect->getNamespaceName();
        $properties = $reflect->getProperties();

        if ($object === false) {
            $object = new $this->objectClass();
        }

        //устанавливаем значения
        foreach ($properties as $property) {
            $propertyName = CamelCase::fromCamelCase($property->getName());
            if(!array_key_exists($propertyName, $requestArray)){
                continue;
            }
            $value        = $requestArray[$propertyName];
            $property->setAccessible(true);

            if (preg_match('/@var\s+([^\s]+)/', $property->getDocComment(), $matches)) {
                list(, $type) = $matches;

                $arrayCollection = null;
                if (preg_match('/@todo\s+([^\s]+)/', $property->getDocComment(), $todo)) {
                    list(, $arrayCollection) = $matches;
                }

                if (class_exists($namespace . "\\" . $type)) {
                    $type = $namespace . "\\" . $type;
                }
                if(is_null($value)){
                    $property->setValue($object, $value);
                }

                if ($type == '\DateTime' && !is_null($value) && !is_object($value)) {
                    $value = new \DateTime($value);
                }

                /**
                 * Для каждого property который указан как ArrayCollection
                 * должен быть setter который вызывается с массивом объектов в качестве параметра
                 */
                if (!is_null($arrayCollection) && is_array($value) && count($value) > 0) {
                    $targetReflect    = new \ReflectionClass($type);
                    $targetProperties = $targetReflect->getProperties();
                    // ищем нужное property
                    foreach ($targetProperties as $targetProperty) {
                        if (preg_match('/@var\s+([^\s]+)/', $targetProperty->getDocComment(), $ownerMatches)) {
                            $ownerType = $ownerMatches[1];
                            if ($ownerType == '\\' . $reflect->getName()) {
                                $this->objectClass = $type;
                                $targetSetterName  = ucfirst($property->getName());

                                //заполняем массив объектами
                                $itemsObjects = [];
                                foreach ($value as $itemId) {
                                    $itemsObjects[] = $this->getObject($itemId);
                                }
                                try {
                                    // вызываем setter
                                    $method = $reflect->getMethod('set' . $targetSetterName);
                                    $method->invoke($object, $itemsObjects);
                                } catch (\ReflectionException $e) {
                                    throw new HttpException(400, 'Mistake when filling object');
                                }
                            }
                        }
                    }
                } else {
                    //если свойство объекта является объектом, то проверяем его существование
                    if (class_exists($type) && !is_null($value) && $value != [] && !is_object($value)) {
                        $repository   = $em->getRepository($type);
                        $findFunction = 'findOneById';
                        $subObject    = $repository->$findFunction($value);
                        if (!$subObject) {
                            throw new HttpException(400, 'Not found object (' . $type . ').');
                        }

                        $property->setValue($object, $subObject);
                    } else if (!is_null($value)) {
                        $property->setValue($object, $value);
                    }
                }
            }
        }
        //запуск валидатора
        $this->validate($object);

        $em->persist($object);

        return $object;
    }

    /**
     * @deprecated deprecated since version 2
     *
     * Заполнение временного обьекта из массива
     *
     * @param   $requestArray
     *
     * @return  Object
     */
    public function tempObject($requestArray)
    {
        return $this->arrayToObject($requestArray);
    }

    /**
     * Валидация обьекта (Entity)
     *
     * @param   Object $object
     *
     * @return  boolean
     */
    protected function validate($object)
    {
        $validator = $this->get('validator');
        $errors    = $validator->validate($object);
        if (count($errors) > 0) {
            throw new HttpException(400, 'Bad request (' . print_r($errors->get(0)->getMessage(), true) . ').');
        }

        return true;
    }

    /**
     * Получить отображание из группы $group
     *
     * @param   object $object
     * @param   string $group
     *
     * @return  view
     *
     */
    public function getObjectGroup($object, $group)
    {
        $view    = $this->view();
        $context = SerializationContext::create()->enableMaxDepthChecks();
        $context->setGroups([$group]);
        $view->setSerializationContext($context);
        $view->setData($object);

        return $view;
    }

    /**
     * Получение репозитория
     *
     * @return  ObjectRepository
     */
    protected function getObjectRepository()
    {
        /**
         * @var EntityManager
         */
        $em = $this->getDoctrine()->getManager();

        return $em->getRepository($this->objectClass);
    }

    /**
     * Проверка на валидность JSON
     *
     * @param   Request $request
     *
     * @return  array
     */
    protected function checkJson(Request $request)
    {
        if ('json' !== $request->getContentType()) {
            throw new HttpException(400, 'Invalid content type');
        }
        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE || $request->getContent() == '') {
            throw new HttpException(400, 'Invalid content type');
        }

        return $data;
    }
}

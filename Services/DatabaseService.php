<?php
/**
 * Created by PhpStorm.
 * User: kwant
 * Date: 28.09.17
 * Time: 14:45
 */

namespace WebAnt\CoreBundle\Services;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManager;
use WebAnt\CoreBundle\Component\Helpers;
use WebAnt\CoreBundle\Util\CamelCase;
use Doctrine\ORM\Mapping as ORM;


class DatabaseService
{
    /** @var EntityManager */
    protected $em;

    public function __construct(EntityManager $entityManager)
    {
        $this->em = $entityManager;
    }


    /**
     * @param string $class
     * @param array|integer $criteria
     * @param array|null $orderBy
     * @return null|object
     */
    public function getObject($class, $criteria, $orderBy = null){
        if(is_array($criteria)) {
            return $this->em->getRepository($class)->findOneBy($criteria, $orderBy);
        } else {
            return $this->em->find($class, $criteria);
        }
    }

    // TODO function getQueryBuilder
    // TODO function getList(){}


    /**
     * @param object|string|array $object
     *    if object - that object will be updated
     *    if string - new object of that class will be created
     *    if array - format [$class, $id] is used - object of $class with $id will be found and updated
     * @param array $data - data to append
     * @param array $config - additional config
     * @return mixed
     */
    public function saveObject($object, array $data, array $config = []) {
        if(is_string($object)) {
            $object = new $object;
        }
        if(is_array($object)){
            $object = call_user_func_array([$this->em, 'find'], $object);
        }

        $object = $this->appendDataToObject($object, $data, $config);

        $this->em->persist($object);
        $this->em->flush($object);

        return $object;
    }

    /**
     * @param $class
     * @param array $data
     * @param array $config
     * @return object
     */
    public function createObject($class, array $data, array $config = []) {
        $object = $this->appendDataToObject($class, $data, $config);

        $this->em->persist($object);
        $this->em->flush($object);

        return $object;
    }

    /**
     * @param $object
     * @param array $data
     * @param array $config
     * @return mixed
     */
    public function updateObject($object, array $data, array $config = []) {
        $this->appendDataToObject($object, $data, $config);

        $this->em->persist($object);
        $this->em->flush($object);

        return $object;
    }

    /**
     * @param string $class
     * @param integer $id
     * @param array $data
     * @param array $config
     * @return null|object
     */
    public function updateObjectById($class, $id, array $data, array $config = []) {
        $object = $this->getObject($class, $id);
        if(!$object){
            return null;
        }
        $this->appendDataToObject($object, $data, $config);

        $this->em->persist($object);
        $this->em->flush($object);

        return $object;
    }


    /**
     * @param object|string $object - object to update or className to create
     * @param array $data - new data to be appended to object
     * @param array $config
     * @return object object
     */
    public function appendDataToObject($object, $data, $config = []) {
        if(is_string($object)){
            $object = new $object();
        }

        $excluded = Helpers::getKeyIfExists($config, 'excluded', ['id', 'date_create']);

        $reflect = new \ReflectionClass($object);
        $properties = $reflect->getProperties();

        foreach ($properties as $property){
            $fieldMetaData = $this->parsePropertyAnnotation($property);
            $serializedName = $fieldMetaData['serializedName'];

            if(in_array($serializedName, $excluded,true)){
                continue;
            }

            if(!array_key_exists($serializedName, $data)){
                continue;
            }
            $value = $data[$serializedName];

            /** @var callable $setter - dummy setter for now, real setter in future */
            $setter = function ($value) {};

            if(isset($fieldMetaData['setterName'])){
                $setter = [$object, $fieldMetaData['setterName']];
            } else {
                continue;
            }

            if($fieldMetaData['isPrimitive']){
                if(is_null($value) && @$fieldMetaData['nullable']){
                    $setter(null);
                }
                $type = $fieldMetaData['type'];
                if($type === 'datetime'){
                    $fixedDate = Helpers::fixDate($value);
                    if($fixedDate){
                        $setter($fixedDate);
                    }
                } else {
                    $setter($value);
                }
            } else if($fieldMetaData['isAssociation']){
                $targetEntity = $fieldMetaData['targetEntity'];
                $isSingleObject = Helpers::getKeyIfExists($fieldMetaData, 'isSingleObject');
                // TODO: check for nullable here
                if(is_null($value) && $isSingleObject){
                    $setter(null);
                }
                // TODO: change object if needed
                // TODO: make new object if not exist
                if($isSingleObject){
                    if(is_integer($value)) {
                        $subObject = $this->getObject($targetEntity, $value);
                        $setter($subObject);
                    } if ($value instanceof $targetEntity){
                        $setter($value);
                    } else if(is_array($value) && isset($value['id'])){
                        $subObject = $this->getObject($targetEntity, $value['id']);
                        $setter($subObject);
                    }
                }
                // TODO: resolve objects array
            }
        }

        return $object;
    }

    /**
     * TODO: move it to separate class
     * TODO: cache properties mappings
     *
     * @param \ReflectionProperty $property
     * @return array
     */
    public function parsePropertyAnnotation(\ReflectionProperty $property) {
        $varName = $property->getName();
        $mapping = [
            'isPrimitive' => false,
            'isAssociation' => false,
            'varName' => $varName,
            'setterName' => 'set' . ucfirst($varName),
            // copied from \JMS\Serializer\Naming\CamelCaseNamingStrategy::translateName
            'serializedName' => strtolower(preg_replace('/[A-Z]/', '_\\0', $property->name)),
        ];

        $class = $property->getDeclaringClass();
        $metadata = $this->em->getClassMetadata($class->getName());

        if(!($class->hasMethod($mapping['setterName']) && $class->getMethod($mapping['setterName'])->isPublic())) {
            $mapping['setterName'] = null;
        }

        if(in_array($varName, $metadata->getFieldNames())){
            $mapping = array_merge($mapping, $metadata->getFieldMapping($varName));
            $mapping['isPrimitive'] = true;
        } else if(in_array($varName, $metadata->getAssociationNames())){
            $mapping = array_merge($mapping, $metadata->getAssociationMapping($varName));
            $mapping['isAssociation'] = true;
            $type = $mapping['type'];

            $mapping['isSingleObject'] = (bool)($type & ORM\ClassMetadataInfo::TO_ONE);
            $mapping['isObjectsArray'] = (bool)($type & ORM\ClassMetadataInfo::TO_MANY);

        } // else this property is not used or custom

//        $annotationReader = new AnnotationReader();
//        $propertyAnnotations = $annotationReader->getPropertyAnnotations($property);

        return $mapping;
    }


}
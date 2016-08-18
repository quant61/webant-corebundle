<?php
/**
 * User: quant61
 * Date: 2016-07-25
 */


namespace WebAnt\CoreBundle\Component;

use Symfony\Component\HttpFoundation\Request;

class Helpers{

    /**
     * get array key if it exists else return default value(null)
     *
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getKeyIfExists($array, $key, $default = null){
        // missing key is casted to default, but null key isn't
        if(array_key_exists($key, $array)){
            return $array[$key];
        }
        return $default;
    }

    /**
     * append search from array to queryBuilder
     *
     * @param QueryBuilder $qb
     * @param array|Request $search - search conditions
     * @param array $config
     *
     * @return mixed
     *
     */
    public static function appendSearchToQueryBuilder($qb, $search, $config){

        $alias = $config['alias'];

        if($search instanceof Request){
            $search = $search->query->all();
        }

        // TODO: add support multiple orderby's
        $orderby     = self::getKeyIfExists($search, 'orderby');
        $orderbydesc = self::getKeyIfExists($search, 'orderbydesc');


        // objectClass is used to get all possible properties
        $objectClass = $config['objectClass'];
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





}


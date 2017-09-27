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
        if(!is_array($array)){
            return $default;
        }
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


            $orderPropertyName = $fullPropertyName;
            // fix $orderPropertyName if type is object else 500
            if(in_array($propertyName, [$orderby, $orderbydesc])){
                preg_match('/@var\s+([^\s]+)/', $property->getDocComment(), $matches);
                $type = self::getKeyIfExists($matches, 1);
                if(substr($type, 0, 1) == '\\' && $type !== '\\DateTime'){ // if complex type
                    $orderPropertyName = "order__${alias}__${propertyName}";
                    $qb->addSelect("IDENTITY($fullPropertyName) as HIDDEN $orderPropertyName");
                };
            }

            if ($orderby == $propertyName) {
                $qb->orderBy("$orderPropertyName", 'ASC');
            } elseif ($orderbydesc == $propertyName) {
                $qb->orderBy("$orderPropertyName", 'DESC');
            }
        } // end foreach possible properties

        return $qb;

    }

    /**
     * fixes date input
     * - converts date to server's timezone
     * - supports both DateTime and DateTimeImmutable objects
     * - supports strings supported by DateTime constructor
     * - supports and treats numbers as unix timestamps
     * - return false for invalid values
     *
     * @param string|int|float|\DateTimeInterface $date
     * @return bool|\DateTime|null
     */
    public static function fixDate($date){
        $fixedDate = null;
        $tzName = date_default_timezone_get();
        $timeZone = new \DateTimeZone($tzName);

        if(is_string($date)){
            try {
                $fixedDate = new \DateTime($date);
                // force current timezone
                $fixedDate->setTimezone($timeZone);
            } catch (\Exception $e) {
                $fixedDate = false;
            }
        } else if(is_integer($date) || is_float($date)){
            $timestamp = floor($date);
            $fixedDate = new \DateTime("@$timestamp");
        } else if($date instanceof \DateTimeInterface){
            // force timezone and support both mutable and immutable
            $timestamp = $date->format('U');
            $fixedDate = new \DateTime("@$timestamp");
        } else {
            $fixedDate = false;
        }
        return $fixedDate;
    }


}


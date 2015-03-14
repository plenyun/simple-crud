<?php
namespace SimpleCrud;

use JsonSerializable;

/**
 * Stores the data of an entity row
 * 
 * @property mixed $id
 */
class Row extends BaseRow implements JsonSerializable
{
    private $values = [];
    private $changes = [];

    /**
     * Row constructor.
     *
     * @param Entity     $entity
     * @param array|null $data
     */
    public function __construct(Entity $entity, array $data = null)
    {
        $this->setEntity($entity);

        if (!empty($data)) {
            $this->values = $data;
        }

        $this->values += $entity->getDefaults();
    }

    /**
     * Magic method to execute 'get' functions and save the result in a property.
     *
     * @param string $name The property name
     */
    public function __get($name)
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        $method = "get$name";

        if (method_exists($this, $method) || $this->getEntity()->isRelated($name)) {
            return $this->values[$name] = $this->$method();
        }
    }

    /**
     * Magic method to execute 'set' function.
     *
     * @param string $name  The property name
     * @param mixed  $value The value
     */
    public function __set($name, $value)
    {
        $this->changes[$name] = true;
        $this->values[$name] = $value;
    }

    /**
     * Magic method to check if a property is defined or is empty.
     *
     * @param string $name Property name
     *
     * @return boolean
     */
    public function __isset($name)
    {
        return !empty($this->values[$name]);
    }

    /**
     * Magic method to execute get[whatever] and load automatically related stuff.
     *
     * @param string $name
     * @param string $arguments
     *
     * @throws SimpleCrudException
     */
    public function __call($name, $arguments)
    {
        if (strpos($name, 'get') === 0) {
            $name = lcfirst(substr($name, 3));

            if (!$arguments && array_key_exists($name, $this->values)) {
                return $this->values[$name];
            }

            if (($entity = $this->getAdapter()->$name)) {
                array_unshift($arguments, $this);

                return call_user_func_array([$entity, 'selectBy'], $arguments);
            }
        }

        throw new SimpleCrudException("The method $name does not exists");
    }

    /**
     * Magic method to print the row values (and subvalues).
     *
     * @return string
     */
    public function __toString()
    {
        return "\n".$this->getEntity()->name.":\n".print_r($this->toArray(), true)."\n";
    }

    /**
     * @see RowInterface
     * 
     * {@inheritdoc}
     */
    public function toArray($keysAsId = false, array $parentEntities = array())
    {
        if (!empty($parentEntities) && in_array($this->getEntity()->name, $parentEntities)) {
            return;
        }

        $parentEntities[] = $this->getEntity()->name;
        $data = $this->values;

        foreach ($data as &$value) {
            if ($value instanceof RowInterface) {
                $value = $value->toArray($keysAsId, $parentEntities);
            }
        }

        return $data;
    }

    /**
     * Return if the row values has been changed or not.
     *
     * @return boolean
     */
    public function changed()
    {
        return !empty($this->changes);
    }

    /**
     * Reload the row from the database.
     *
     * @throws SimpleCrudException
     *
     * @return $this
     */
    public function reload()
    {
        if (!$this->id || !($row = $this->getEntity()->selectBy($this->id))) {
            throw new SimpleCrudException("This row does not exist in database");
        }

        $this->changes = [];
        $this->values = $row->get();

        return $this;
    }

    /**
     * Relate 'has-one' elements with this row.
     *
     * @param RowInterface $row The row to relate
     *
     * @return $this
     */
    public function setRelation(RowInterface $row)
    {
        if (func_num_args() > 1) {
            foreach (func_get_args() as $row) {
                $this->setRelation($row);
            }

            return $this;
        }

        if ($this->getEntity()->getRelation($row->getEntity()) !== Entity::RELATION_HAS_ONE) {
            throw new SimpleCrudException("Not valid relation");
        }

        if (empty($row->id)) {
            throw new SimpleCrudException('Rows without id value cannot be related');
        }

        $this->{$row->getEntity()->foreignKey} = $row->id;

        return $this;
    }

    /**
     * Set new values to the row.
     *
     * @param array   $data               The new values
     * @param boolean $onlyDeclaredFields Set true to only set declared fields
     *
     * @return $this
     */
    public function set(array $data, $onlyDeclaredFields = false)
    {
        if ($onlyDeclaredFields === true) {
            $data = array_intersect_key($data, $this->getEntity()->fields);
        }

        foreach ($data as $name => $value) {
            $this->changes[$name] = true;
            $this->values[$name] = $value;
        }

        return $this;
    }

    /**
     * Return one or all values of the row.
     *
     * @param boolean|null|string $name              The value name to recover. If it's not defined, returns all values. If it's true, returns only the fields values.
     * @param boolean             $onlyChangedValues To return only the changed values instead all
     *
     * @return mixed
     */
    public function get($name = null, $onlyChangedValues = false)
    {
        $values = ($onlyChangedValues === true) ? array_intersect_key($this->values, $this->changes) : $this->values;

        if ($name === true) {
            return array_intersect_key($values, $this->getEntity()->fields);
        }

        if ($name === null) {
            return $values;
        }

        return isset($values[$name]) ? $values[$name] : null;
    }

    /**
     * Saves this row in the database.
     *
     * @param boolean $handleDuplications Set true to detect duplicates index
     * @param boolean $onlyChangedValues  Set false to save all values instead only the changed (only for updates)
     *
     * @return $this
     */
    public function save($handleDuplications = false, $onlyChangedValues = true)
    {
        $data = $this->get(true);

        if (empty($this->id)) {
            $data = $this->getEntity()->insert($data, $handleDuplications);
        } else {
            $data = $this->getEntity()->update($data, 'id = :id', [':id' => $this->id], 1, ($onlyChangedValues ? $this->changes : null));
        }

        $this->set($data);
        $this->changes = [];

        return $this;
    }

    /**
     * Deletes the row in the database.
     *
     * @return $this
     */
    public function delete()
    {
        if (!empty($this->id)) {
            $this->getEntity()->delete('id = :id', [':id' => $this->id], 1);
        }

        return $this;
    }
}

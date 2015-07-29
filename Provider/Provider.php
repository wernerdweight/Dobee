<?php

namespace WernerDweight\Dobee\Provider;

use WernerDweight\Dobee\Transformer\Transformer;
use WernerDweight\Dobee\Traits\ModelHelper;
use WernerDweight\Dobee\Exception\DatabaseException;
use WernerDweight\Dobee\Exception\UnknownOperationException;
use WernerDweight\Dobee\Exception\InvalidPropertyTypeException;
use WernerDweight\Dobee\LazyLoader\SingleLazyLoader;
use WernerDweight\Dobee\LazyLoader\MultipleLazyLoader;

class Provider {

	use ModelHelper;

	protected $connection;
	protected $entityNamespace;
	protected $model;

	public function __construct($connection,$entityNamespace,$model){
		$this->connection = $connection;
		$this->entityNamespace = $entityNamespace;
		$this->model = $model;
	}

	public function fetchOne($entityName,$primaryKey,$options = array()){
		$types = array();
		$params = array();

		/// select
		$select = $this->getSelect($entityName);
		/// joins (join, left join)
		$join = $this->getJoins($options);
		/// where
		$where = $this->getWhere($entityName,$options,$types,$params);
		/// order
		$order = $this->getOrderBy($options);
		/// limit
		$limit = " LIMIT 0,1";
		/// fetch result
		$result = $this->execute($select.$join.$where.$order.$limit,$types,$params);

		$entityData = $result->fetch_assoc();
		$entity = $this->hydrateEntity($entityName,$entityData);
		return $entity;
	}

	public function fetch($entityName,$options = array()){
		$types = array();
		$params = array();

		/// select
		$select = $this->getSelect($entityName);
		/// joins (join, left join)
		$join = $this->getJoins($options);
		/// where
		$where = $this->getWhere($entityName,$options,$types,$params);
		/// order
		$order = $this->getOrderBy($options);
		/// limit
		$limit = $this->getLimit($options);
		/// fetch result
		$result = $this->execute($select.$join.$where.$order.$limit,$types,$params);

		$results = array();
		while ($rowData = $result->fetch_assoc()) {
			$results[$rowData[Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))]] = $this->hydrateEntity($entityName,$rowData);
		}

		return $results;
	}

	public function save($entity){
		if(!is_null($entity->getPrimaryKey())){
			$this->doUpdate($entity);
		}
		else{
			$this->doInsert($entity);
		}
	}

	public function delete($entity){
		if(method_exists($entity,'delete')){
			$entity->delete();
			$this->update($entity);
		}
		else{
			$this->doDelete($entity);
		}
	}

	protected function doUpdate($entity){

	}

	protected function doInsert($entity){

	}

	protected function doDelete($entity){

	}

	protected function resolveOperation($operator){
		switch (strtolower($operator)) {
			case '=': case 'eq': return '=';
			case '!=': case 'neq': return '!=';
			case '>': case 'gt': return '>';
			case '>=': case 'gte': return '>=';
			case '<': case 'lt': return '<';
			case '<=': case 'lte': return '<=';
			case '%%': case 'like': return 'LIKE';
			case '~': case 'in': return 'IN';
			case '0': case 'null': return 'IS NULL';
			case '!0': case 'nn': return 'IS NOT NULL';
			default: throw new UnknownOperationException('Operator "'.$operator.'" is unknown!');
		}
	}

	protected function resolveValue($entityName,$property,$value,$type = null){
		if(isset($this->model[$entityName]['properties']) && isset($this->model[$entityName]['properties'][$property])){
			switch ($this->model[$entityName]['properties'][$property]['type']) {
				case 'bool': return intval($value);
				case 'int': return intval($value);
				case 'float': return doubleval($value);
				case 'string': return "'".$value."'";
				case 'text': return "'".$value."'";
				case 'datetime': return ($value == 'CURRENT_TIMESTAMP' ? "CURRENT_TIMESTAMP" : "'".$value."'");
				default: throw new InvalidPropertyTypeException('"'.$this->model[$entityName]['properties'][$property]['type'].'" is not a valid property type!');
			}
		}
		else if(isset($this->model[$entityName]['extends'])){
			return $this->resolveValue($this->model[$entityName]['extends'],$property,$value,$type);
		}
		else if(!is_null($type)){
			switch ($type) {
				case 'i': return intval($value);
				case 'd': return doubleval($value);
				case 's': return "'".$value."'";
				default: throw new InvalidPropertyTypeException('"'.$this->model[$entityName]['properties'][$property]['type'].'" is not a valid property type!');
			}
		}
		else return null;
	}

	protected function resolvePropertyStatementType($entityName,$property){
		if(isset($this->model[$entityName]['properties']) && isset($this->model[$entityName]['properties'][$property])){
			switch ($this->model[$entityName]['properties'][$property]['type']) {
				case 'bool': case 'int': return 'i';
				case 'float': return 'd';
				case 'string': case 'text': case 'datetime': return 's';
				default: throw new InvalidPropertyTypeException('"'.$this->model[$entityName]['properties'][$property]['type'].'" is not a valid property type!');
			}
		}
		else if(isset($this->model[$entityName]['extends'])){
			return $this->resolvePropertyStatementType($this->model[$entityName]['extends'],$property);
		}
		else{
			return null;
		}
	}

	protected function execute($query,$types,$params){
		/// prepare statement
		$statement = $this->connection->stmt_init();
		if(!$statement->prepare($query)){
			throw new DatabaseException("Query failed: (".$statement->errno.") ".$statement->error);
		}
		/// dynamically bind parameters
		if(is_array($types) && is_array($params) && count($types) && count($params)){
			call_user_func_array(
				array(
					$statement,
					'bind_param'
				),
				array_merge(
					array(implode('',$types)),
					$this->getArrayAsReferences($params)
				)
			);
		}
		/// execute statement
		if(!$statement->execute()){
			throw new DatabaseException("Query failed: (".$statement->errno.") ".$statement->error);
		}
		/// get result
		$result = $statement->get_result();
		/// close statement
		$statement->close();
		
		return $result;
	}

	protected function resolveOrderDirection($direction){
		switch (strtolower($direction)) {
			case 'desc': return 'DESC';
			default: return 'ASC';
		}
	}

	protected function getSelect($entityName){
		return "SELECT this.* FROM ".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))." this";
	}

	protected function getOrderBy($options){
		$order = "";

		if(isset($options['order']) && is_array($options['order'])){
			$orderClauses = array();
			foreach ($options['order'] as $property => $direction) {
				$orderClauses[] = $property." ".$this->resolveOrderDirection($direction);
			}
			$order .= " ORDER BY ".implode(", ",$orderClauses);
		}

		return $order;
	}

	protected function getJoins($options){
		$join = "";

		if(isset($options['join']) && is_array($options['join'])){
			foreach ($options['join'] as $relatedEntity => $name) {
				$join .= " JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntity))." ".$name." ON this.".Transformer::camelCaseToUnderscore($relatedEntity)."_id = ".$name.".".$this->getPrimaryKeyForEntity($relatedEntity);
			}
		}
		if(isset($options['leftJoin']) && is_array($options['leftJoin'])){
			foreach ($options['leftJoin'] as $relatedEntity => $name) {
				$join .= " LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntity))." ".$name." ON this.".Transformer::camelCaseToUnderscore($relatedEntity)."_id = ".$name.".".$this->getPrimaryKeyForEntity($relatedEntity);
			}
		}
		if(isset($options['plainJoin']) && is_array($options['plainJoin'])){
			foreach ($options['plainJoin'] as $relatedEntity => $settings) {
				$join .= " JOIN ".$relatedEntity." ".$settings['name']." ON this.".$settings['entityKey']." = ".$settings['name'].".".$settings['relatedEntityKey'];
			}
		}
		if(isset($options['plainLeftJoin']) && is_array($options['plainLeftJoin'])){
			foreach ($options['plainLeftJoin'] as $relatedEntity => $settings) {
				$join .= " LEFT JOIN ".$relatedEntity." ".$settings['name']." ON this.".$settings['entityKey']." = ".$settings['name'].".".$settings['relatedEntityKey'];
			}
		}

		return $join;
	}

	protected function getWhere($entityName,$options,&$types,&$params){
		$where = "";

		if(isset($options['where']) && is_array($options['where'])){
			$whereClauses = array();
			foreach ($options['where'] as $property => $settings) {
				$whereClauses[] = $property." ".$this->resolveOperation($settings['operator'])." ?";
				$types[] = isset($settings['type']) ? $settings['type'] : $this->resolvePropertyStatementType($entityName,$property);
				$params[] = $this->resolveValue($entityName,$property,$settings['value'],isset($settings['type']) ? $settings['type'] : null);
			}
			$where .= " WHERE ".implode(" AND ",$whereClauses);
		}

		return $where;
	}

	protected function getLimit($options){
		$limit = "";

		if(isset($options['limit']) && is_array($options['limit']) && isset($options['limit']['firstResult']) && isset($options['limit']['maxResults'])){
			$limit .= " LIMIT ".intval($options['limit']['firstResult']).",".intval($options['limit']['maxResults']);
		}

		return $limit;
	}

	protected function hydrateEntity($entityName,$entityData){
		if(is_array($entityData)){
			$entityClass = $this->entityNamespace.'\\'.ucfirst($entityName);
			$entity = new $entityClass;
			foreach ($entityData as $property => $value) {
				/// hydrate properties
				if($this->hasProperty($entityName,lcfirst(Transformer::underscoreToCamelCase($property))) === true){
					$entity->{'set'.Transformer::underscoreToCamelCase($property)}($value);
				}
				/// hydrate relations
				foreach ($this->model[$entityName]['relations'] as $relatedEntity => $cardinality) {
					switch ($cardinality) {
						case 'ONE_TO_ONE':
						case '<<ONE_TO_ONE':
						case 'MANY_TO_ONE':
							/// foreign key must be set and not null
							if(isset($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']) && !is_null($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id'])){
								/// set lazy-loader for single item
								$entity->{'set'.ucfirst($relatedEntity)}(
									new SingleLazyLoader(
										$this,
										$relatedEntity,
										$entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']
									)
								);
							}
							break;
						case 'ONE_TO_MANY':
							/// set lazy-loader for multiple items
							$entity->{'set'.ucfirst(Transformer::pluralize($relatedEntity))}(
								new MultipleLazyLoader(
									$this,
									$entity,
									$relatedEntity,
									array(
										'where' => array(
											'this.'.Transformer::camelCaseToUnderscore($entityName).'_id' => array(
												'operator' => 'eq',
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										)
									)
								)
							);
							break;
						case 'MANY_TO_MANY':
						case '<<MANY_TO_MANY':
							/// set lazy-loader for multiple items with many-to-many loading
							$entity->{'set'.ucfirst(Transformer::pluralize($relatedEntity))}(
								new MultipleLazyLoader(
									$this,
									$entity,
									$relatedEntity,
									array(
										'plainLeftJoin' => array(
											Transformer::smurf(Transformer::camelCaseToUnderscore($cardinality == '<<MANY_TO_MANY' ? $entityName : $relatedEntity).'_mtm_'.Transformer::camelCaseToUnderscore($cardinality == '<<MANY_TO_MANY' ? $relatedEntity : $entityName)) => array(
												'name' => $relatedEntity,
												'entityKey' => $this->getPrimaryKeyForEntity($entityName),
												'relatedEntityKey' => Transformer::camelCaseToUnderscore($entityName).'_id'
											)
										),
										'where' => array(
											$relatedEntity.'.'.Transformer::camelCaseToUnderscore($entityName).'_id' => array(
												'operator' => 'eq',
												'type' => 'i',
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										)
									)
								)
							);
							break;
					}
				}
			}
		}
		else{
			$entity = null;
		}

		return $entity;
	}

	protected function getArrayAsReferences($array){
		$references = array();
		
		foreach($array as $key => $value) {
			$references[$key] = &$array[$key];
		}

		return $references;
	}

}

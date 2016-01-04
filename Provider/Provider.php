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

	public function fetchOne($entityName,$primaryKey = null,$options = array()){
		$types = array();
		$params = array();

		/// select
		$select = $this->getSelect($entityName,$options);
		/// joins (join, left join)
		$join = $this->getJoins($options,$entityName);
		/// where
		$where = $this->getWhere($entityName,$options,$types,$params);
		/// add PK to the where clause
		if(!is_null($primaryKey)){
			if(strlen($where) <= 0){
				$where .= " WHERE";
			}
			else{
				$where .= " AND";
			}
			$where .= " this.`".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))."` = ?";
			$types[] = $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName));
			$params[] = $this->resolveValue($entityName,$this->getPrimaryKeyForEntity($entityName),$primaryKey);
		}
		/// if entity is soft-deletable add condition
		if($this->isSoftDeletable($entityName) === true){
			if(strlen($where) <= 0){
				$where .= " WHERE";
			}
			else{
				$where .= " AND";
			}
			$where .= " this.`deleted` = 0";
		}
		/// order
		$order = $this->getOrderBy($options);
		/// limit
		$limit = " LIMIT 0,1";
		/// fetch result
		$result = $this->execute($select.$join.$where.$order.$limit,$types,$params);

		if(is_array($result) && count($result)){
			return $this->hydrateEntity($entityName,$result[0]);
		}

		return null;
	}

	public function fetch($entityName,$options = array()){
		$types = array();
		$params = array();

		/// select
		$select = $this->getSelect($entityName,$options);
		/// joins (join, left join)
		$join = $this->getJoins($options,$entityName);
		/// where
		$where = $this->getWhere($entityName,$options,$types,$params);
		/// if entity is soft-deletable add condition
		if($this->isSoftDeletable($entityName) === true){
			if(strlen($where) <= 0){
				$where .= " WHERE";
			}
			else{
				$where .= " AND";
			}
			$where .= " this.`deleted` = 0";
		}
		/// order
		$order = $this->getOrderBy($options);
		/// limit
		$limit = $this->getLimit($options);
		/// fetch result
		$result = $this->execute($select.$join.$where.$order.$limit,$types,$params);

		$results = array();
		if(is_array($result) && count($result)){
			foreach ($result as $key => $rowData) {
				$results[$rowData[Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))]] = $this->hydrateEntity($entityName,$rowData);
			}
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
		if(!is_null($entity)){
			if(method_exists($entity,'delete')){
				$entity->delete();
				$this->update($entity);
			}
			else{
				$this->doDelete($entity);
			}
		}
	}

	protected function doUpdate($entity){
		$types = array();
		$params = array();

		$entityName = $this->getEntityName($entity);

		$query = "UPDATE `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))."` SET ";
		$query .= $this->getSaveQueryBody($entity,$entityName,$types,$params);
		$query .= " WHERE `".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))."` = ?";
		$types[] = $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName));
		$params[] = $this->resolveValue($entityName,$this->getPrimaryKeyForEntity($entityName),$entity->getPrimaryKey());

		/// execute update
		$this->execute($query,$types,$params);
	}

	protected function doInsert($entity){
		$types = array();
		$params = array();

		$entityName = $this->getEntityName($entity);

		$query = "INSERT INTO `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))."` SET ";
		$query .= $this->getSaveQueryBody($entity,$entityName,$types,$params);

		/// execute update
		$this->execute($query,$types,$params);
		$entity->setPrimaryKey($this->connection->insert_id);
	}

	protected function doDelete($entity){
		$types = array();
		$params = array();

		$entityName = $this->getEntityName($entity);

		$query = "DELETE FROM `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))."`";
		$query .= " WHERE `".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entityName))."` = ?";
		$types[] = $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName));
		$params[] = $this->resolveValue($entityName,$this->getPrimaryKeyForEntity($entityName),$entity->getPrimaryKey());

		/// execute update
		$this->execute($query,$types,$params);
	}

	protected function getSaveQueryBody($entity,$entityName,&$types,&$params){
		$query = "";

		$properties = $this->getEntityProperties($entityName);
		if(count($properties)){
			foreach ($properties as $property) {
				if($this->getPrimaryKeyForEntity($entityName) == $property) continue;
				if(!is_null($entity->{'get'.ucfirst($property)}())){
					$query .= "`".Transformer::camelCaseToUnderscore($property)."` = ?, ";
					$types[] = $this->resolvePropertyStatementType($entityName,$property);
					$params[] = $this->resolveValue($entityName,$property,$entity->{'get'.ucfirst($property)}());
				}
			}
		}

		$relations = $this->getEntityRelations($entityName);
		if(count($relations)){
			foreach ($relations as $relatedEntity => $cardinality) {
				if(in_array($cardinality,array('SELF::MANY_TO_ONE','SELF::ONE_TO_MANY'))){
					if(!is_null($entity->{'getParent'.ucfirst($relatedEntity)}())){
						$query .= "`".Transformer::camelCaseToUnderscore($relatedEntity)."_id` = ?, ";
						$types[] = $this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity));
						$params[] = $this->resolveValue($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity),$entity->{'getParent'.ucfirst($relatedEntity)}()->getPrimaryKey());
					}
				}
				else if(in_array($cardinality,array('<<ONE_TO_ONE','MANY_TO_ONE'))){
					if(!is_null($entity->{'get'.ucfirst($relatedEntity)}())){
						$query .= "`".Transformer::camelCaseToUnderscore($relatedEntity)."_id` = ?, ";
						$types[] = $this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity));
						$params[] = $this->resolveValue($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity),$entity->{'get'.ucfirst($relatedEntity)}()->getPrimaryKey());
					}
				}
				else{	/// many-to-many owning side
					/// fetch current relations from database
					$currentRelationsResult = $this->execute(
						"SELECT * FROM `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` WHERE ".Transformer::camelCaseToUnderscore($entityName)."_id = ?",
						array(
							$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName))
						),
						array(
							$entity->getPrimaryKey()
						)
					);
					$currentRelations = array();
					if(is_array($currentRelationsResult) && count($currentRelationsResult)){
						foreach ($currentRelationsResult as $rowData) {
							$currentRelations[$rowData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']] = $rowData[Transformer::camelCaseToUnderscore($relatedEntity).'_id'];
						}
					}
					/// get relations as set during business logic operation
					$notPersistedRelations = array();
					if(count($entity->{'get'.ucfirst(Transformer::pluralize($relatedEntity))}())){
						foreach ($entity->{'get'.ucfirst(Transformer::pluralize($relatedEntity))}() as $key => $relatedItem) {
							$notPersistedRelations[$relatedItem->getPrimaryKey()] = $relatedItem->getPrimaryKey();
						}
					}
					/// check for removed relations (and remove them from database)
					if(count($currentRelations)){
						foreach ($currentRelations as $key => $id) {
							if(!array_key_exists($id,$notPersistedRelations)){
								$this->execute(
									"DELETE FROM `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` WHERE ".Transformer::camelCaseToUnderscore($entityName)."_id = ? AND ".Transformer::camelCaseToUnderscore($relatedEntity)."_id = ?",
									array(
										$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
										$this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity))
									),
									array(
										$entity->getPrimaryKey(),
										$key
									)
								);
							}
						}
					}
					/// check for new relations (and add them to database)
					if(count($notPersistedRelations)){
						foreach ($notPersistedRelations as $key => $id) {
							if(!array_key_exists($id,$currentRelations)){
								$this->execute(
									"INSERT INTO `".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntity))."` SET ".Transformer::camelCaseToUnderscore($entityName)."_id = ?, ".Transformer::camelCaseToUnderscore($relatedEntity)."_id = ?",
									array(
										$this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
										$this->resolvePropertyStatementType($relatedEntity,$this->getPrimaryKeyForEntity($relatedEntity))
									),
									array(
										$entity->getPrimaryKey(),
										$key
									)
								);
							}
						}
					}
				}
			}
		}

		if(strlen($query) > 0){
			$query = substr($query,0,-2);
		}

		return $query;
	}

	protected function resolveOperation($operator){
		switch (strtolower($operator)) {
			case '=': case 'eq': return '= ?';
			case '!=': case 'neq': return '!= ?';
			case '>': case 'gt': return '> ?';
			case '>=': case 'gte': return '>= ?';
			case '<': case 'lt': return '< ?';
			case '<=': case 'lte': return '<= ?';
			case '%%': case 'like': return 'LIKE ?';
			case '~': case 'in': return 'IN (?)';
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
				case 'string': return $value;
				case 'text': return $value;
				case 'datetime': return $value;
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
				case 's': return $value;
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
		$result = $this->getResult($statement);
		/// close statement
		$statement->close();
		
		return $result;
	}

	protected function getResult(\mysqli_stmt $statement){
		
		$metadata = $statement->result_metadata();
		if($metadata === false){
			if($statement->errno === 0){	/// DELETE / INSERT / DROP / ...
				return null;
			}
			else throw new DatabaseException("Query failed: (".$statement->errno.") ".$statement->error);
		}

		$row = array();
		while($field = $metadata->fetch_field()){
			$params[] = &$row[$field->name];
		}

		call_user_func_array(array($statement,'bind_result'),$params);

		$result = array();

		while($statement->fetch()){
			$columns = array();
			foreach($row as $key => $val){
				$columns[$key] = $val;
			}
			$result[] = $columns;
		}

		return $result;
	}

	protected function resolveOrderDirection($direction){
		switch (strtolower($direction)) {
			case 'desc': return 'DESC';
			default: return 'ASC';
		}
	}

	protected function getSelect($entityName,$options = []){
		$selectionProperty = 'this.*';
		if(isset($options['select'])){
			$selectionProperty = $options['select'];
		}
		return "SELECT ".$selectionProperty." FROM ".Transformer::smurf(Transformer::camelCaseToUnderscore($entityName))." this";
	}

	protected function getOrderBy($options){
		$order = "";

		if(isset($options['order']) && is_array($options['order'])){
			$orderClauses = array();
			foreach ($options['order'] as $property => $direction) {
				$orderClauses[] = Transformer::camelCaseToUnderscore($property)." ".$this->resolveOrderDirection($direction);
			}
			$order .= " ORDER BY ".implode(", ",$orderClauses);
		}

		return $order;
	}

	protected function getJoins($options,$entityName){
		$join = "";

		if(isset($options['join']) && is_array($options['join'])){
			foreach ($options['join'] as $relatedEntity => $name) {
				$relatedEntityStripped = Transformer::strip($relatedEntity);
				$relatedEntityOwner = Transformer::strip($relatedEntity,false);
				$cardinality = $this->model[$relatedEntityOwner === 'this' ? $entityName : $relatedEntityOwner]['relations'][$relatedEntityStripped];
				$owning = (false !== strpos($cardinality,'<<') ? true : ($cardinality === 'MANY_TO_ONE' ? true : (false !== strpos($relatedEntity,'<<') ? true : false)));
				if(false !== strpos($cardinality,'MANY_TO_MANY')){
					/// M:N join
					$mtmTableName = ($owning ? Transformer::smurf(Transformer::camelCaseToUnderscore($entityName)).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntityStripped) : Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped)).'_mtm_'.Transformer::camelCaseToUnderscore($entityName));
					$join .= " JOIN ".$mtmTableName." ".$mtmTableName." ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityName)." = ".$mtmTableName.".".Transformer::camelCaseToUnderscore($entityName)."_id JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." ".Transformer::camelCaseToUnderscore($name)." ON ".$mtmTableName.".".Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else if($owning === true){
					/// many-to-one or one-to-one join
					$join .= " JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." ".Transformer::camelCaseToUnderscore($name)." ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else{
					/// one-to-many left join
					$join .= " JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." ".Transformer::camelCaseToUnderscore($name)." ON ".Transformer::camelCaseToUnderscore($name).".".Transformer::camelCaseToUnderscore($entityName)."_id = ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityName);
				}
			}
		}
		if(isset($options['leftJoin']) && is_array($options['leftJoin'])){
			foreach ($options['leftJoin'] as $relatedEntity => $name) {
				$relatedEntityStripped = Transformer::strip($relatedEntity);
				$relatedEntityOwner = Transformer::strip($relatedEntity,false);
				$cardinality = $this->model[$relatedEntityOwner === 'this' ? $entityName : $relatedEntityOwner]['relations'][$relatedEntityStripped];
				$owning = (false !== strpos($cardinality,'<<') ? true : ($cardinality === 'MANY_TO_ONE' ? true : (false !== strpos($relatedEntity,'<<') ? true : false)));
				if(false !== strpos($cardinality,'MANY_TO_MANY')){
					/// M:N left join
					$mtmTableName = ($owning ? Transformer::smurf(Transformer::camelCaseToUnderscore($entityName)).'_mtm_'.Transformer::camelCaseToUnderscore($relatedEntityStripped) : Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped)).'_mtm_'.Transformer::camelCaseToUnderscore($entityName));
					$join .= " LEFT JOIN ".$mtmTableName." ".$mtmTableName." ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityName)." = ".$mtmTableName.".".Transformer::camelCaseToUnderscore($entityName)."_id LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." ".Transformer::camelCaseToUnderscore($name)." ON ".$mtmTableName.".".Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else if($owning === true){
					/// many-to-one or one-to-one left join
					$join .= " LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." ".Transformer::camelCaseToUnderscore($name)." ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".Transformer::camelCaseToUnderscore($relatedEntityStripped)."_id = ".Transformer::camelCaseToUnderscore($name).".".$this->getPrimaryKeyForEntity($relatedEntityStripped);
				}
				else{
					/// one-to-many left join
					$join .= " LEFT JOIN ".Transformer::smurf(Transformer::camelCaseToUnderscore($relatedEntityStripped))." ".Transformer::camelCaseToUnderscore($name)." ON ".Transformer::camelCaseToUnderscore($name).".".Transformer::camelCaseToUnderscore($entityName)."_id = ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$this->getPrimaryKeyForEntity($entityName);
				}
			}
		}
		if(isset($options['plainJoin']) && is_array($options['plainJoin'])){
			foreach ($options['plainJoin'] as $relatedEntity => $settings) {
				$relatedEntityOwner = Transformer::strip($relatedEntity,false);
				$join .= " JOIN ".$relatedEntity." ".Transformer::camelCaseToUnderscore($settings['name'])." ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$settings['entityKey']." = ".Transformer::camelCaseToUnderscore($settings['name']).".".$settings['relatedEntityKey'];
			}
		}
		if(isset($options['plainLeftJoin']) && is_array($options['plainLeftJoin'])){
			foreach ($options['plainLeftJoin'] as $relatedEntity => $settings) {
				$relatedEntityOwner = Transformer::strip($relatedEntity,false);
				$join .= " LEFT JOIN ".$relatedEntity." ".Transformer::camelCaseToUnderscore($settings['name'])." ON ".Transformer::camelCaseToUnderscore($relatedEntityOwner).".".$settings['entityKey']." = ".Transformer::camelCaseToUnderscore($settings['name']).".".$settings['relatedEntityKey'];
			}
		}

		return $join;
	}

	protected function getWhere($entityName,$options,&$types,&$params){
		$where = "";

		if(isset($options['where']) && is_array($options['where']) && count($options['where'])){
			$whereClauses = array();
			foreach ($options['where'] as $propertyGroup => $settings) {
				/// property group can be e.g. 'this.id' or 'this.id|this.active|this.title' where '|'' stands for logical OR
				$properties = explode('|',$propertyGroup);
				$operators = explode('|',$settings['operator']);
				$values = explode('|',$settings['value']);
				if(isset($settings['type'])){
					$typesets = explode('|',$settings['type']);
				}
				else{
					$typesets = null;
				}
				
				$clause = "(";
				foreach ($properties as $key => $property) {
					if($clause !== "("){
						$clause .= ' OR ';
					}

					$operator = count($operators) > 1 ? $operators[$key] : $operators[0];
					$value = count($values) > 1 ? $values[$key] : $values[0];
					if(null !== $typesets){
						$type = count($typesets) > 1 ? $typesets[$key] : $typesets[0];
					}
					else{
						$type = null;
					}

					$clause .= Transformer::camelCaseToUnderscore($property)." ".$this->resolveOperation($operator);
					if(strpos($clause,'?') !== false){
						$propertyStripped = Transformer::strip($property);
						$types[] = null !== $type ? $type : $this->resolvePropertyStatementType($entityName,$propertyStripped);
						$params[] = $this->resolveValue($entityName,$propertyStripped,$value,null !== $type ? $type : null);
					}
				}
				$clause .= ")";

				$whereClauses[] = $clause;
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
			}
			/// hydrate relations
			if(isset($this->model[$entityName]['relations'])){
				foreach ($this->model[$entityName]['relations'] as $relatedEntity => $cardinality) {
					switch ($cardinality) {
						case 'SELF::MANY_TO_ONE':
						case 'SELF::ONE_TO_MANY':
							/// foreign key must be set and not null
							if(isset($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']) && !is_null($entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id'])){
								/// set lazy-loader for single item
								$entity->{'setParent'.ucfirst($relatedEntity)}(
									new SingleLazyLoader(
										$this,
										$relatedEntity,
										$entityData[Transformer::camelCaseToUnderscore($relatedEntity).'_id']
									)
								);
							}
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
												'type' => $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										),
										'order' => array(
											'this.'.Transformer::camelCaseToUnderscore($this->getDefaultOrderForEntity($relatedEntity)) => $this->getDefaultOrderForEntity($relatedEntity,false)
										)
									)
								)
							);
							break;
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
												'type' => $this->resolvePropertyStatementType($entityName,$this->getPrimaryKeyForEntity($entityName)),
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										),
										'order' => array(
											'this.'.Transformer::camelCaseToUnderscore($this->getDefaultOrderForEntity($relatedEntity)) => $this->getDefaultOrderForEntity($relatedEntity,false)
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
										'leftJoin' => array(
											'this.'.Transformer::camelCaseToUnderscore($entityName) => Transformer::camelCaseToUnderscore($entityName)
										),
										'where' => array(
											$entityName.'.'.$this->getPrimaryKeyForEntity($entityName) => array(
												'operator' => 'eq',
												'value' => $entity->{'get'.ucfirst($this->getPrimaryKeyForEntity($entityName))}()
											)
										),
										'order' => array(
											'this.'.Transformer::camelCaseToUnderscore($this->getDefaultOrderForEntity($relatedEntity)) => $this->getDefaultOrderForEntity($relatedEntity,false)
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

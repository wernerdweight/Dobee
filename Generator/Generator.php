<?php

namespace WernerDweight\Dobee\Generator;

use WernerDweight\Dobee\Exception\DatabaseException;
use WernerDweight\Dobee\Generator\Comparator;
use WernerDweight\Dobee\Transformer\Transformer;
use WernerDweight\Dobee\Traits\ModelHelper;

class Generator {

	use ModelHelper;

	protected $connection;
	protected $database;
	protected $comparator;
	protected $entityPath;
	protected $entityNamespace;
	protected $model;
	protected $tableSql;
	protected $primarySql;
	protected $relationSql;

	public function __construct($connection,$entityPath,$entityNamespace,$model,$database){
		$this->connection = $connection;
		$this->entityPath = $entityPath;
		$this->entityNamespace = $entityNamespace;
		$this->model = $model;
		$this->database = $database;
		$this->comparator = new Comparator($this->connection,$this->database);
		$this->refresh();
	}

	protected function refresh(){
		$this->tableSql = "";
		$this->primarySql = "";
		$this->relationSql = "";
	}

	public function generate($options){
		/// create sql queries based on yml model
		$this->getSqlFromModel();
		/// temporarily create new schema to compare it to the current one
		$this->force(true);
		/// compare schemas and get changes
		$diff = $this->comparator->compareSchemas();
		/// remove temporary tables
		$this->removeTemporary();
		/// refresh sql strings
		$this->refresh();
		/// create sql queries based on schema diff
		$this->getSqlFromDiff($diff);
		/// resolve parameters
		if(in_array('--dump',$options)){
			if(strlen($this->tableSql) || strlen($this->primarySql) || strlen($this->relationSql)){
				echo $this->tableSql;
				echo $this->primarySql;
				echo $this->relationSql;
			}
			else{
				echo "Nothing to update! Database is already in sync!\n";
			}
		}
		if(in_array('--force',$options)){
			if(strlen($this->tableSql) || strlen($this->primarySql) || strlen($this->relationSql)){
				$this->force();
				$this->generateEntities();
			}
			else{
				echo "Nothing to update! Database is already in sync!\n";
			}
		}
	}

	protected function getSqlFromModel(){
		foreach ($this->model as $entity => $attributes) {
			if(!array_key_exists('abstract',$attributes)){
				$this->getSqlForEntity($entity);
			}
		}
	}

	protected function getSqlFromDiff($diff){

		if(count($diff['tables']['create'])){
			foreach ($diff['tables']['create'] as $table => $columns) {

				$this->tableSql .= "CREATE TABLE `".$table."` (\n";
				
				if(count($columns)){
					foreach ($columns as $column => $properties) {
						/// get column sql
						$this->tableSql .= "`".$properties['Field']."` ".$properties['Type'].(isset($properties['Null']) && $properties['Null'] == 'NO' ? " NOT NULL" : "").(isset($properties['Default']) ? " DEFAULT ".$properties['Default'] : (isset($properties['Null']) && $properties['Null'] == 'NO' ? "" : " DEFAULT NULL")).",\n";
						/// check if current column is a key
						if(isset($properties['Key'])){
							/// primary key
							if($properties['Key'] == 'PRI'){
								/// if this tambe represents many-to-many relation, both columns must be set as PK
								if(strpos($table,'_mtm_')){
									$colNames = array_keys($columns);
									$this->relationSql .= "ALTER TABLE `".$table."` ADD PRIMARY KEY (`".$colNames[0]."`,`".$colNames[1]."`), ADD KEY `IDX_".preg_replace('/_id$/','',$colNames[0])."_MTM_".preg_replace('/_id$/','',$colNames[1])."` (`".$colNames[0]."`), ADD KEY `IDX_".preg_replace('/_id$/','',$colNames[1])."_MTM_".preg_replace('/_id$/','',$colNames[0])."` (`".$colNames[1]."`);\n";
									$this->relationSql .= "ALTER TABLE `".$table."`\n";
									$this->relationSql .= "ADD CONSTRAINT `FK_".preg_replace('/_id$/','',$colNames[0])."_MTM_".preg_replace('/_id$/','',$colNames[1])."` FOREIGN KEY (`".$colNames[0]."`) REFERENCES `".Transformer::smurf(preg_replace('/_id$/','',$colNames[0]))."` (`id`),\n";
									$this->relationSql .= "ADD CONSTRAINT `FK_".preg_replace('/_id$/','',$colNames[1])."_MTM_".preg_replace('/_id$/','',$colNames[0])."` FOREIGN KEY (`".$colNames[1]."`) REFERENCES `".Transformer::smurf(preg_replace('/_id$/','',$colNames[1]))."` (`id`);\n";
									$this->relationSql .= "\n";
									/// add sql for second column
									$this->tableSql .= "`".$columns[$colNames[1]]['Field']."` ".$columns[$colNames[1]]['Type'].(isset($columns[$colNames[1]]['Null']) && $columns[$colNames[1]]['Null'] == 'NO' ? " NOT NULL" : "").(isset($columns[$colNames[1]]['Default']) ? " DEFAULT ".$columns[$colNames[1]]['Default'] : (isset($columns[$colNames[1]]['Null']) && $columns[$colNames[1]]['Null'] == 'NO' ? "" : " DEFAULT NULL")).",\n";
									break;	/// many-to-many table only has two columns
								}
								/// otherwise, use standard approach
								else{
									$this->primarySql .= "ALTER TABLE `".$table."` ADD PRIMARY KEY (`".$properties['Field']."`);\n";
									$this->primarySql .= "ALTER TABLE `".$table."` MODIFY `".$properties['Field']."` ".$properties['Type']." NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=1;\n";
									$this->primarySql .= "\n";
								}
							}
							/// foreign key
							else if($properties['Key'] == 'MUL'){
								$this->relationSql .= "ALTER TABLE `".$table."` ADD CONSTRAINT `FK_".$table."_".preg_replace('/_id$/','',$properties['Field'])."` FOREIGN KEY (`".$properties['Field']."`) REFERENCES `".Transformer::smurf(preg_replace('/_id$/','',$properties['Field']))."` (`id`);\n";
								$this->relationSql .= "\n";
							}
						}
					}
				}
				/// remove last comma
				$this->tableSql = rtrim($this->tableSql,",\n")."\n";

				$this->tableSql .= ") ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;\n\n";
			}
		}
		
		if(count($diff['tables']['drop'])){
			foreach ($diff['tables']['drop'] as $key => $table) {
				$this->tableSql .= "DROP TABLE `".$table."`;\n\n";
			}
		}

		if(count($diff['columns']['add'])){
			foreach ($diff['columns']['add'] as $table => $columns) {
				foreach ($columns as $column => $properties) {
					$this->tableSql .= "ALTER TABLE `".$table."` ADD COLUMN `".$properties['Field']."` ".$properties['Type'].(isset($properties['Null']) && $properties['Null'] == 'NO' ? " NOT NULL" : "").(isset($properties['Default']) ? " DEFAULT ".$properties['Default'] : (isset($properties['Null']) && $properties['Null'] == 'NO' ? "" : " DEFAULT NULL")).";\n\n";
				}
			}
		}

		if(count($diff['columns']['change'])){
			foreach ($diff['columns']['change'] as $table => $columns) {
				foreach ($columns as $column => $properties) {
					$this->tableSql .= "ALTER TABLE `".$table."` MODIFY `".$properties['Field']."` ".$properties['Type'].(isset($properties['Null']) && $properties['Null'] == 'NO' ? " NOT NULL" : "").(isset($properties['Default']) ? " DEFAULT ".$properties['Default'] : (isset($properties['Null']) && $properties['Null'] == 'NO' ? "" : " DEFAULT NULL")).";\n\n";
				}
			}
		}

		if(count($diff['columns']['drop'])){
			foreach ($diff['columns']['drop'] as $table => $columns) {
				foreach ($columns as $column) {
					$this->tableSql .= "ALTER TABLE `".$table."` DROP COLUMN `".$column."`;\n\n";
				}
			}
		}

	}

	protected function force($temporary = false){
		$query = str_replace("\n",'',"SET FOREIGN_KEY_CHECKS=0;".$this->tableSql.$this->primarySql.$this->relationSql."SET FOREIGN_KEY_CHECKS=1;");
		if($temporary === true){
			$query = str_replace("dobee_","tmp_dobee_",$query);
		}
		if(!$this->connection->multi_query($query)){
			throw new DatabaseException("Database construction failed: (".$this->connection->errno.") ".$this->connection->error);
		}
		$this->clearResult();
	}

	protected function removeTemporary(){
		/// get query to drop all tmp-prefixed tables
		if(!($dropQuery = $this->connection->query("SELECT CONCAT('DROP TABLE ',GROUP_CONCAT(table_name),';') AS statement FROM information_schema.tables WHERE table_schema = '".$this->database."' AND table_name LIKE 'tmp_%';"))){
			throw new DatabaseException("Database construction failed: (".$this->connection->errno.") ".$this->connection->error);
		}
		
		$dropQuery = $dropQuery->fetch_assoc();
		/// temporarily disable FK checks
		$dropStatement = "SET FOREIGN_KEY_CHECKS=0;";
		$dropStatement .= $dropQuery['statement'];
		$dropStatement .= "SET FOREIGN_KEY_CHECKS=1;";
		/// execute drop statement
		if(!$this->connection->multi_query($dropStatement)){
			throw new DatabaseException("Database construction failed: (".$this->connection->errno.") ".$this->connection->error);
		}

		$this->clearResult();
	}

	protected function getSqlForEntity($entity){
		/// open table
		$this->tableSql .= "CREATE TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($entity))."` (\n";
		/// if entity extends another entity, load its properties first
		$this->getExtendedPropertiesSqlForEntity($entity);
		/// load properties of current entity if some exist
		$this->getPropertiesSqlForEntity($entity);
		/// if entity extends another entity, load its relations if some exist
		$this->getExtendedRelationshipsSqlForEntity($entity);
		/// load relations of current entity if some exist
		$this->getRelationshipsSqlForEntity($entity);
		/// remove last comma
		$this->tableSql = rtrim($this->tableSql,",\n")."\n";
		/// close table
		$this->tableSql .= ") ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;\n";
		/// set primary key
		$this->getPrimaryKeySqlForEntity($entity);

		$this->tableSql .= "\n";
	}

	protected function getExtendedPropertiesSqlForEntity($entity){
		if(isset($this->model[$entity]['extends'])){
			$this->tableSql .= $this->getExtendedPropertiesSqlForEntity($this->model[$entity]['extends']);
			$this->tableSql .= $this->getPropertiesSqlForEntity($this->model[$entity]['extends']);
		}
	}

	protected function getPropertiesSqlForEntity($entity){
		if(isset($this->model[$entity]['properties']) && is_array($this->model[$entity]['properties'])){
			foreach ($this->model[$entity]['properties'] as $property => $settings) {
				$this->tableSql .= "`".Transformer::camelCaseToUnderscore($property)."` ".self::getSqlType($settings).(isset($settings['notNull']) && $settings['notNull'] === true ? " NOT NULL" : "").(isset($settings['default']) ? " DEFAULT ".self::getDefaultByType($settings['default'],$settings['type']) : (isset($settings['notNull']) && $settings['notNull'] ? "" : " DEFAULT NULL")).",\n";
			}
		}
	}

	protected function getExtendedRelationshipsSqlForEntity($entity){
		if(isset($this->model[$entity]['extends'])){
			$this->tableSql .= $this->getExtendedRelationshipsSqlForEntity($this->model[$entity]['extends']);
			$this->tableSql .= $this->getRelationshipsSqlForEntity($this->model[$entity]['extends']);
		}
	}

	protected function getRelationshipsSqlForEntity($currentEntity){
		if(isset($this->model[$currentEntity]['relations']) && is_array($this->model[$currentEntity]['relations'])){
			foreach ($this->model[$currentEntity]['relations'] as $entity => $cardinality) {
				switch ($cardinality) {
					case 'ONE_TO_MANY': break;		/// will be handled by MANY_TO_ONE
					case 'ONE_TO_ONE': break;		/// will be handled by <<ONE_TO_ONE
					case 'MANY_TO_MANY': break;		/// will be handled by <<MANY_TO_MANY
					case '<<ONE_TO_ONE': case 'MANY_TO_ONE':
						$this->tableSql .= "`".Transformer::camelCaseToUnderscore($entity)."_id` int(11) DEFAULT NULL,\n";
						$this->relationSql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity))."` ADD CONSTRAINT `FK_".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity))."_".Transformer::camelCaseToUnderscore($entity)."` FOREIGN KEY (`".Transformer::camelCaseToUnderscore($entity)."_id`) REFERENCES `".Transformer::smurf(Transformer::camelCaseToUnderscore($entity))."` (`".$this->getPrimaryKeyForEntity($entity)."`);\n";
						$this->relationSql .= "\n";
						break;
					case '<<MANY_TO_MANY':
						$this->relationSql .= "CREATE TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."` (\n";
  						$this->relationSql .= "`".Transformer::camelCaseToUnderscore($currentEntity)."_id` int(11) NOT NULL,\n";
  						$this->relationSql .= "`".Transformer::camelCaseToUnderscore($entity)."_id` int(11) NOT NULL\n";
						$this->relationSql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;\n\n";
						$this->relationSql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."` ADD PRIMARY KEY (`".Transformer::camelCaseToUnderscore($currentEntity)."_id`,`".Transformer::camelCaseToUnderscore($entity)."_id`), ADD KEY `IDX_".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."` (`".Transformer::camelCaseToUnderscore($currentEntity)."_id`), ADD KEY `IDX_".Transformer::smurf(Transformer::camelCaseToUnderscore($entity)."_MTM_".Transformer::camelCaseToUnderscore($currentEntity))."` (`".Transformer::camelCaseToUnderscore($entity)."_id`);\n";
						$this->relationSql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."`\n";
						$this->relationSql .= "ADD CONSTRAINT `FK_".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."` FOREIGN KEY (`".Transformer::camelCaseToUnderscore($currentEntity)."_id`) REFERENCES `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity))."` (`".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($currentEntity))."`),\n";
						$this->relationSql .= "ADD CONSTRAINT `FK_".Transformer::smurf(Transformer::camelCaseToUnderscore($entity)."_MTM_".Transformer::camelCaseToUnderscore($currentEntity))."` FOREIGN KEY (`".Transformer::camelCaseToUnderscore($entity)."_id`) REFERENCES `".Transformer::smurf(Transformer::camelCaseToUnderscore($entity))."` (`".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entity))."`);\n";
						$this->relationSql .= "\n";
						break;
				}
			}
		}
	}

	protected function getPrimaryKeySqlForEntity($entity){
		$primaryKey = $this->getPrimaryKeyForEntity($entity);
		$this->primarySql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($entity))."` ADD PRIMARY KEY (`".Transformer::camelCaseToUnderscore($primaryKey)."`);\n";
		$this->primarySql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($entity))."` MODIFY `".Transformer::camelCaseToUnderscore($primaryKey)."` ".$this->getPrimaryKeyTypeForEntity($entity)." NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=1;\n";
		$this->primarySql .= "\n";
	}

	protected function getPrimaryKeyTypeForEntity($entity){
		if(isset($this->model[$entity]['extends'])){
			return $this->getPrimaryKeyTypeForEntity($this->model[$entity]['extends']);
		}
		return $this->getSqlType($this->model[$entity]['properties'][$this->model[$entity]['primary']]);
	}

	protected static function getSqlType($settings){
		switch ($settings['type']) {
			case 'bool': return 'tinyint(1)';
			case 'int': return 'int(11)';
			case 'float': return 'double';
			case 'string': return 'varchar('.intval($settings['length']).') COLLATE utf8_unicode_ci';
			case 'text': return 'longtext';
			case 'datetime': return 'datetime';
			default: throw new InvalidPropertyTypeException('"'.$settings['type'].'" is not a valid property type!');
		}
	}

	protected static function getDefaultByType($value,$type){
		switch ($type) {
			case 'bool': return "'".intval($value)."'";
			case 'int': return "'".intval($value)."'";
			case 'float': return "'".doubleval($value)."'";
			case 'string': return "'".$value."'";
			case 'text': return "'".$value."'";
			case 'datetime': return ($value == 'CURRENT_TIMESTAMP' ? "CURRENT_TIMESTAMP" : "'".$value."'");
			default: throw new InvalidPropertyTypeException('"'.$settings['type'].'" is not a valid property type!');
		}
	}

	protected function clearResult(){
		while($this->connection->more_results()) $this->connection->next_result();
	}

	protected function generateEntities(){
		foreach ($this->model as $entity => $attributes) {
			$this->generateEntity($entity);
		}
	}

	protected function generateEntity($entityName){
		/// update existing file
		if(is_file($this->entityPath.DIRECTORY_SEPARATOR.ucfirst($entityName).'.php')){
			$contents = file_get_contents($this->entityPath.DIRECTORY_SEPARATOR.ucfirst($entityName).'.php');
			$contents = $this->updateEntitySource($entityName,$contents);
			file_put_contents($this->entityPath.DIRECTORY_SEPARATOR.ucfirst($entityName).'.php',$contents);
		}
		/// create whole new file
		else{
			$contents = $this->createEntitySource($entityName);
			file_put_contents($this->entityPath.DIRECTORY_SEPARATOR.ucfirst($entityName).'.php',$contents);
		}
	}

	protected function updateEntitySource($entityName,$contents){
		return substr($this->createEntitySource($entityName),0,-6).substr($contents,strpos($contents,'/// write your own logic below this point')+43);
	}

	protected function createEntitySource($entityName){
		$header = $use = $class = $body = $footer = "";
		
		$header = "<?php\n\n";
		$header .= "namespace ".$this->entityNamespace.";\n\n";
		
		if(array_key_exists('abstract',$this->model[$entityName])){
			$class .= "abstract ";
		}
		$class .= "class ".ucfirst($entityName)." ";
		if(isset($this->model[$entityName]['extends'])){
			$class .= "extends ".ucfirst($this->model[$entityName]['extends'])." ";
			$use .= "use ".$this->entityNamespace."\\".ucfirst($this->model[$entityName]['extends']).";\n";
		}
		$class .= "{\n\n";

		/// primary key helper
		/// setter
		$body .= "\tpublic function setPrimaryKey(\$primaryKey){\n";
		$body .= "\t\t\$this->".$this->getPrimaryKeyForEntity($entityName)." = \$primaryKey;\n";
		$body .= "\t\treturn \$this;\n";
		$body .= "\t}\n\n";
		/// getter
		$body .= "\tpublic function getPrimaryKey(){\n";
		$body .= "\t\treturn \$this->".$this->getPrimaryKeyForEntity($entityName).";\n";
		$body .= "\t}\n\n";

		if(isset($this->model[$entityName]['properties'])){
			foreach ($this->model[$entityName]['properties'] as $property => $options) {
				/// declaration
				$class .= "\tprotected \$".$property.";\n";
				/// setter
				$body .= "\tpublic function set".ucfirst($property)."(\$".$property."){\n";
				$body .= "\t\t\$this->".$property." = \$".$property.";\n";
				$body .= "\t\treturn \$this;\n";
				$body .= "\t}\n\n";
				/// getter
				$body .= "\tpublic function get".ucfirst($property)."(){\n";
				$body .= "\t\treturn \$this->".$property.";\n";
				$body .= "\t}\n\n";
			}
		}

		if(isset($this->model[$entityName]['relations'])){
			foreach ($this->model[$entityName]['relations'] as $relatedEntity => $cardinality) {
				/// use
				$use .= "use ".$this->entityNamespace."\\".ucfirst($relatedEntity).";\n";
					switch ($cardinality) {
						case 'ONE_TO_ONE':
						case '<<ONE_TO_ONE':
						case 'MANY_TO_ONE':
							/// declaration
							$class .= "\tprotected \$".$relatedEntity.";\n";
							/// setter
							$body .= "\tpublic function set".ucfirst($relatedEntity)."(\$".$relatedEntity."){\n";
							$body .= "\t\t\$this->".$relatedEntity." = \$".$relatedEntity.";\n";
							$body .= "\t\treturn \$this;\n";
							$body .= "\t}\n\n";
							/// getter
							$body .= "\tpublic function get".ucfirst($relatedEntity)."(){\n";
							$body .= "\t\treturn \$this->".$relatedEntity.";\n";
							$body .= "\t}\n\n";
							break;
						case 'ONE_TO_MANY':
						case 'MANY_TO_MANY':
						case '<<MANY_TO_MANY':
							/// use
							$use .= "use WernerDweight\\Dobee\\LazyLoader\\MultipleLazyLoader;\n";
							/// declaration
							$class .= "\tprotected \$".Transformer::pluralize($relatedEntity).";\n";
							/// setters
							$body .= "\tpublic function add".ucfirst($relatedEntity)."(".ucfirst($relatedEntity)." \$".$relatedEntity."){\n";
							$body .= "\t\t/// check that items are loaded (if not load them)\n";
							$body .= "\t\t\$this->load".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
							$body .= "\t\t\$this->".Transformer::pluralize($relatedEntity)."[\$".$relatedEntity."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()] = \$".$relatedEntity.";\n";
							$body .= "\t\treturn \$this;\n";
							$body .= "\t}\n\n";
							$body .= "\tpublic function remove".ucfirst($relatedEntity)."(".ucfirst($relatedEntity)." \$".$relatedEntity."){\n";
							$body .= "\t\t/// check that items are loaded (if not load them)\n";
							$body .= "\t\t\$this->load".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
							$body .= "\t\tif(isset(\$this->".Transformer::pluralize($relatedEntity)."[\$".$relatedEntity."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()])){\n";
							$body .= "\t\t\tunset(\$this->".Transformer::pluralize($relatedEntity)."[\$".$relatedEntity."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()]);\n";
							$body .= "\t\t}\n";
							$body .= "\t\treturn \$this;\n";
							$body .= "\t}\n\n";
							$body .= "\tpublic function set".ucfirst(Transformer::pluralize($relatedEntity))."(\$".Transformer::pluralize($relatedEntity)."){\n";
							$body .= "\t\t\$this->".Transformer::pluralize($relatedEntity)." = \$".Transformer::pluralize($relatedEntity).";\n";
							$body .= "\t\treturn \$this;\n";
							$body .= "\t}\n\n";
							/// getters
							$body .= "\tpublic function get".ucfirst(Transformer::pluralize($relatedEntity))."(){\n";
							$body .= "\t\t/// check that items are loaded (if not load them)\n";
							$body .= "\t\t\$this->load".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
							$body .= "\t\treturn \$this->".Transformer::pluralize($relatedEntity).";\n";
							$body .= "\t}\n\n";
							$body .= "\tpublic function get".ucfirst($relatedEntity)."(\$key){\n";
							$body .= "\t\t/// check that items are loaded (if not load them)\n";
							$body .= "\t\t\$this->load".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
							$body .= "\t\tif(isset(\$this->".Transformer::pluralize($relatedEntity)."[\$key])){\n";
							$body .= "\t\t\treturn \$this->".Transformer::pluralize($relatedEntity)."[\$key];\n";
							$body .= "\t\t}\n";
							$body .= "\t\telse{\n";
							$body .= "\t\t\treturn null;\n";
							$body .= "\t\t}\n";
							$body .= "\t}\n\n";
							/// loader
							$body .= "\tpublic function load".ucfirst(Transformer::pluralize($relatedEntity))."(){\n";
							$body .= "\t\tif(\$this->".Transformer::pluralize($relatedEntity)." instanceof MultipleLazyLoader){\n";
							$body .= "\t\t\t\$this->".Transformer::pluralize($relatedEntity)."->loadData();\n";
							$body .= "\t\t}\n";
							$body .= "\t}\n\n";
							break;
					}
			}
		}

		$footer .= "\t/// write your own logic below this point\n\n";
		$footer .= "}\n\n";
		$footer .= "?>\n";

		return $header.$use."\n".$class."\n".$body.$footer;
	}

}

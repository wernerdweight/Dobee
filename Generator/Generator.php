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
		echo "\033[1;31\033[41m\n\n DO NOT ABORT THIS ACTION! Your database could become inconsistent!\n\033[0m\n\n";
		/// create sql queries based on yml model
		$this->getSqlFromModel();
		/// temporarily create new schema to compare it to the current one
		echo "Determining changes...";
		$this->force(true);
		/// compare schemas and get changes
		$diff = $this->comparator->compareSchemas();
		echo " \033[32mOK\033[0m\n";
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
				echo "\033[1;34mNothing to update! Database is already in sync!\033[0m\n";
			}
		}
		if(in_array('--force',$options)){
			if(strlen($this->tableSql) || strlen($this->primarySql) || strlen($this->relationSql)){
				echo "Applying changes...";
				$this->force();
				$this->generateEntities();
				echo " \033[32mOK\033[0m\n";
			}
			else if(in_array('--generate-entities',$options)){
				$this->generateEntities();
				echo "\033[1;34mNothing to update!\033[0m \033[32mEntities were re-generated!\033[0m\n";
			}
			else{
				echo "\033[1;34mNothing to update! Database is already in sync!\033[0m\n";
			}
		}
	}

	protected function checkLogStorageExists(){
		$storage = $this->connection->query("SHOW TABLES WHERE tables_in_".$this->database." = 'log_storage'");
		if($storage->num_rows > 0){
			return true;
		}
		else{
			return false;
		}
	}

	protected function createLogStorage(){
		echo "Checking log storage...";
		if(false === $this->checkLogStorageExists()){
			echo " \033[33mWill be generated!\033[0m\n";

			$this->tableSql .= "CREATE TABLE `log_storage` (\n";
			$this->tableSql .= "`id` int(11) NOT NULL,\n";
			$this->tableSql .= "`action_type` varchar(16) COLLATE utf8_unicode_ci NOT NULL,\n";
			$this->tableSql .= "`blame` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,\n";
			$this->tableSql .= "`entity_class` varchar(255) COLLATE utf8_unicode_ci NOT NULL,\n";
			$this->tableSql .= "`entity_id` varchar(255) COLLATE utf8_unicode_ci NOT NULL,\n";
			$this->tableSql .= "`version` int(11) NOT NULL,\n";
			$this->tableSql .= "`logged_at` datetime NOT NULL,\n";
			$this->tableSql .= "`data` longtext COLLATE utf8_unicode_ci\n";
			$this->tableSql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;\n\n";

			$this->primarySql .= "ALTER TABLE `log_storage` ADD PRIMARY KEY (`id`);\n";
	  		$this->primarySql .= "ALTER TABLE `log_storage` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;\n";
	  		$this->primarySql .= "ALTER TABLE `log_storage` ADD KEY `log_storage_blame_lookup_idx` (`blame`);\n";
	  		$this->primarySql .= "ALTER TABLE `log_storage` ADD KEY `log_storage_lookup_idx` (`entity_class`);\n";
	  		$this->primarySql .= "ALTER TABLE `log_storage` ADD KEY `log_storage_date_lookup_idx` (`logged_at`);\n\n";
	  	}
	  	else{
	  		echo " \033[32mOK\033[0m\n";
	  	}
	}

	protected function getSqlFromModel(){
		/// create table for storing changelogs for configured entities
		$this->createLogStorage();
		/// create entity and relation tables
		foreach ($this->model as $entity => $attributes) {
			if(!array_key_exists('abstract',$attributes)){
				$this->getSqlForEntity($entity);
			}
		}
	}

	protected function generateColumnKeySql($table,$properties,$columns,&$relationSql,&$primarySql,&$tableSql){
		/// primary key
		if($properties['Key'] == 'PRI'){
			/// if this table represents many-to-many relation, both columns must be set as PK
			if(strpos($table,'_mtm_')){
				$colNames = array_keys($columns);
				$relationSql .= "ALTER TABLE `".$table."` ADD PRIMARY KEY (`".$colNames[0]."`,`".$colNames[1]."`), ADD KEY `IDX_".hash('md5',preg_replace('/_id$/','',$colNames[0])."_MTM_".preg_replace('/_id$/','',$colNames[1]))."` (`".$colNames[0]."`), ADD KEY `IDX_".hash('md5',preg_replace('/_id$/','',$colNames[1])."_MTM_".preg_replace('/_id$/','',$colNames[0]))."` (`".$colNames[1]."`);\n";
				$relationSql .= "ALTER TABLE `".$table."`\n";
				$relationSql .= "ADD CONSTRAINT `FK_".hash('md5',preg_replace('/_id$/','',$colNames[0])."_MTM_".preg_replace('/_id$/','',$colNames[1]))."` FOREIGN KEY (`".$colNames[0]."`) REFERENCES `".Transformer::smurf(preg_replace('/^(master_|slave_)/','',preg_replace('/_id$/','',$colNames[0])))."` (`id`),\n";
				$relationSql .= "ADD CONSTRAINT `FK_".hash('md5',preg_replace('/_id$/','',$colNames[1])."_MTM_".preg_replace('/_id$/','',$colNames[0]))."` FOREIGN KEY (`".$colNames[1]."`) REFERENCES `".Transformer::smurf(preg_replace('/^(master_|slave_)/','',preg_replace('/_id$/','',$colNames[1])))."` (`id`);\n";
				$relationSql .= "\n";
				/// add sql for second column
				$tableSql .= "`".$columns[$colNames[1]]['Field']."` ".$columns[$colNames[1]]['Type'].(isset($columns[$colNames[1]]['Null']) && $columns[$colNames[1]]['Null'] == 'NO' ? " NOT NULL" : "").(isset($columns[$colNames[1]]['Default']) ? " DEFAULT ".$columns[$colNames[1]]['Default'] : (isset($columns[$colNames[1]]['Null']) && $columns[$colNames[1]]['Null'] == 'NO' ? "" : " DEFAULT NULL")).",\n";
				return 'break';	/// many-to-many table only has two columns
			}
			/// otherwise, use standard approach
			else{
				$primarySql .= "ALTER TABLE `".$table."` ADD PRIMARY KEY (`".$properties['Field']."`);\n";
				$primarySql .= "ALTER TABLE `".$table."` MODIFY `".$properties['Field']."` ".$properties['Type']." NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=1;\n";
				$primarySql .= "\n";
			}
		}
		/// foreign key
		else if($properties['Key'] == 'MUL'){
			$relationSql .= "ALTER TABLE `".$table."` ADD CONSTRAINT `FK_".$table."_".preg_replace('/_id$/','',$properties['Field'])."` FOREIGN KEY (`".$properties['Field']."`) REFERENCES `".Transformer::smurf(preg_replace('/_id$/','',$properties['Field']))."` (`id`);\n";
			$relationSql .= "\n";
		}
	}

	protected function generateDropColumnKeySql($table,$properties,$columns,&$tableSql){
		/// primary key
		if($properties['Key'] == 'PRI'){
			/// if this table represents many-to-many relation, both keys will be deleted on drop
			/// otherwise, use standard approach
			if(false === strpos($table,'_mtm_')){
				$tableSql .= "ALTER TABLE `".$table."` DROP PRIMARY KEY (`".$properties['Field']."`);\n";
				$tableSql .= "\n";
			}
		}
		/// foreign key
		else if($properties['Key'] == 'MUL'){
			$tableSql .= "ALTER TABLE `".$table."` DROP FOREIGN KEY `FK_".$table."_".preg_replace('/_id$/','',$properties['Field'])."`;\n";
			$tableSql .= "\n";
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
							if('break' === $this->generateColumnKeySql($table,$properties,$columns,$this->relationSql,$this->primarySql,$this->tableSql)){
								break;
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
				if($table !== 'log_storage'){
					$this->tableSql .= "DROP TABLE `".$table."`;\n\n";
				}
			}
		}

		if(count($diff['columns']['add'])){
			foreach ($diff['columns']['add'] as $table => $columns) {
				foreach ($columns as $column => $properties) {
					$this->tableSql .= "ALTER TABLE `".$table."` ADD COLUMN `".$properties['Field']."` ".$properties['Type'].(isset($properties['Null']) && $properties['Null'] == 'NO' ? " NOT NULL" : "").(isset($properties['Default']) ? " DEFAULT ".$properties['Default'] : (isset($properties['Null']) && $properties['Null'] == 'NO' ? "" : " DEFAULT NULL")).";\n\n";
					/// check if current column is a key
					if(isset($properties['Key'])){
						$this->generateColumnKeySql($table,$properties,$columns,$this->relationSql,$this->primarySql,$this->tableSql);
					}
				}
			}
		}

		if(count($diff['columns']['change'])){
			foreach ($diff['columns']['change'] as $table => $columns) {
				foreach ($columns as $column => $properties) {
					$this->tableSql .= "ALTER TABLE `".$table."` MODIFY `".$properties['Field']."` ".$properties['Type'].(isset($properties['Null']) && $properties['Null'] == 'NO' ? " NOT NULL" : "").(isset($properties['Default']) ? " DEFAULT ".$properties['Default'] : (isset($properties['Null']) && $properties['Null'] == 'NO' ? "" : " DEFAULT NULL")).";\n\n";
					/// check if current column is a key
					if(isset($properties['Key'])){
						if('break' === $this->generateColumnKeySql($table,$properties,$columns,$this->relationSql,$this->primarySql,$this->tableSql)){
							break;
						}
					}
				}
			}
		}

		if(count($diff['columns']['drop'])){
			foreach ($diff['columns']['drop'] as $table => $columns) {
				foreach ($columns as $column => $properties) {
					/// check if current column is a key
					if(isset($properties['Key'])){
						$this->generateDropColumnKeySql($table,$properties,$columns,$this->tableSql);
					}
					$this->tableSql .= "ALTER TABLE `".$table."` DROP COLUMN `".$properties['Field']."`;\n\n";
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
		/// set group concat length for big databases (many tables)
		$this->connection->query("SET SESSION group_concat_max_len=32768;");
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
					case '<<ONE_TO_ONE': case 'MANY_TO_ONE': case 'SELF::MANY_TO_ONE': case 'SELF::ONE_TO_MANY':
						$this->tableSql .= "`".Transformer::camelCaseToUnderscore($entity)."_id` int(11) DEFAULT NULL,\n";
						/// inherited relations can't be constrained at database level
						if(false === array_key_exists('abstract',$this->model[$entity])){
							$this->relationSql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity))."` ADD CONSTRAINT `FK_".hash('md5',Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity))."_".Transformer::camelCaseToUnderscore($entity))."` FOREIGN KEY (`".Transformer::camelCaseToUnderscore($entity)."_id`) REFERENCES `".Transformer::smurf(Transformer::camelCaseToUnderscore($entity))."` (`".$this->getPrimaryKeyForEntity($entity)."`);\n";
							$this->relationSql .= "\n";
						}
						/// if an abstract entity is the relation add discriminator column
						else{
							$this->tableSql .= "`".Transformer::camelCaseToUnderscore($entity)."_class` varchar(255) DEFAULT NULL,\n";
						}
						break;
					case '<<MANY_TO_MANY':
						$this->relationSql .= "CREATE TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."` (\n";
						$this->relationSql .= "`".Transformer::camelCaseToUnderscore($currentEntity)."_id` int(11) NOT NULL,\n";
						$this->relationSql .= "`".Transformer::camelCaseToUnderscore($entity)."_id` int(11) NOT NULL\n";
						$this->relationSql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;\n\n";
						$this->relationSql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."` ADD PRIMARY KEY (`".Transformer::camelCaseToUnderscore($currentEntity)."_id`,`".Transformer::camelCaseToUnderscore($entity)."_id`), ADD KEY `IDX_".hash('md5',Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity)))."` (`".Transformer::camelCaseToUnderscore($currentEntity)."_id`), ADD KEY `IDX_".hash('md5',Transformer::smurf(Transformer::camelCaseToUnderscore($entity)."_MTM_".Transformer::camelCaseToUnderscore($currentEntity)))."` (`".Transformer::camelCaseToUnderscore($entity)."_id`);\n";
						$this->relationSql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."`\n";
						$this->relationSql .= "ADD CONSTRAINT `FK_".hash('md5',Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity)))."` FOREIGN KEY (`".Transformer::camelCaseToUnderscore($currentEntity)."_id`) REFERENCES `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity))."` (`".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($currentEntity))."`),\n";
						$this->relationSql .= "ADD CONSTRAINT `FK_".hash('md5',Transformer::smurf(Transformer::camelCaseToUnderscore($entity)."_MTM_".Transformer::camelCaseToUnderscore($currentEntity)))."` FOREIGN KEY (`".Transformer::camelCaseToUnderscore($entity)."_id`) REFERENCES `".Transformer::smurf(Transformer::camelCaseToUnderscore($entity))."` (`".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entity))."`);\n";
						$this->relationSql .= "\n";
						break;
					 case 'SELF::MANY_TO_MANY':
					 	$this->relationSql .= "CREATE TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."` (\n";
						$this->relationSql .= "`master_".Transformer::camelCaseToUnderscore($currentEntity)."_id` int(11) NOT NULL,\n";
						$this->relationSql .= "`slave_".Transformer::camelCaseToUnderscore($entity)."_id` int(11) NOT NULL\n";
						$this->relationSql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;\n\n";
						$this->relationSql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."` ADD PRIMARY KEY (`master_".Transformer::camelCaseToUnderscore($currentEntity)."_id`,`slave_".Transformer::camelCaseToUnderscore($entity)."_id`), ADD KEY `IDX_m_".hash('md5',Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity)))."` (`master_".Transformer::camelCaseToUnderscore($currentEntity)."_id`), ADD KEY `IDX_s_".hash('md5',Transformer::smurf(Transformer::camelCaseToUnderscore($entity)."_MTM_".Transformer::camelCaseToUnderscore($currentEntity)))."` (`slave_".Transformer::camelCaseToUnderscore($entity)."_id`);\n";
						$this->relationSql .= "ALTER TABLE `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity))."`\n";
						$this->relationSql .= "ADD CONSTRAINT `FK_".hash('md5','master_'.Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity)."_MTM_".Transformer::camelCaseToUnderscore($entity)))."` FOREIGN KEY (`master_".Transformer::camelCaseToUnderscore($currentEntity)."_id`) REFERENCES `".Transformer::smurf(Transformer::camelCaseToUnderscore($currentEntity))."` (`".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($currentEntity))."`),\n";
						$this->relationSql .= "ADD CONSTRAINT `FK_".hash('md5','slave_'.Transformer::smurf(Transformer::camelCaseToUnderscore($entity)."_MTM_".Transformer::camelCaseToUnderscore($currentEntity)))."` FOREIGN KEY (`slave_".Transformer::camelCaseToUnderscore($entity)."_id`) REFERENCES `".Transformer::smurf(Transformer::camelCaseToUnderscore($entity))."` (`".Transformer::camelCaseToUnderscore($this->getPrimaryKeyForEntity($entity))."`);\n";
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
			$useStringToBeAdded = "use ".$this->entityNamespace."\\".ucfirst($this->model[$entityName]['extends']).";";
			/// check for duplicity
			if(false === strpos($use,$useStringToBeAdded)){
				$use .= $useStringToBeAdded."\n";
			}
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

		/// loggable
		if(true === isset($this->model[$entityName]['blameable'])){
			if(true === isset($this->model[$entityName]['blameable']['targetEntity'])){
				/// use
				$useStringToBeAdded = "use WernerDweight\\Dobee\\Provider\\Changelog;";
				/// check for duplicity
				if(false === strpos($use,$useStringToBeAdded)){
					$use .= $useStringToBeAdded."\n";
				}
				/// declaration
				$class .= "\tprotected \$changelog;\n";
				/// setter
				$body .= "\tpublic function setChangelog(Changelog \$changelog){\n";
				$body .= "\t\t\$this->changelog = \$changelog;\n";
				$body .= "\t\treturn \$this;\n";
				$body .= "\t}\n\n";
				/// getter
				$body .= "\tpublic function getChangelog(){\n";
				$body .= "\t\treturn \$this->changelog;\n";
				$body .= "\t}\n\n";
			}
		}

		/// blameable
		if(true === isset($this->model[$entityName]['blameable'])){
			if(true === isset($this->model[$entityName]['blameable']['targetEntity'])){
				/// use
				$useStringToBeAdded = "use WernerDweight\\Dobee\\LazyLoader\\SingleLazyLoader;";
				/// check for duplicity
				if(false === strpos($use,$useStringToBeAdded)){
					$use .= $useStringToBeAdded."\n";
				}
				/// declaration
				$class .= "\tprotected \$blame;\n";
				/// setter
				$body .= "\tpublic function setBlame(\$blame){\n";
				$body .= "\t\t\$this->blame = \$blame;\n";
				$body .= "\t\treturn \$this;\n";
				$body .= "\t}\n\n";
				/// getter
				$body .= "\tpublic function getBlame(){\n";
				$body .= "\t\tif(\$this->blame instanceof SingleLazyLoader){\n";
				$body .= "\t\t\t\$this->blame = \$this->blame->getData();\n";
				$body .= "\t\t}\n";
				$body .= "\t\treturn \$this->blame;\n";
				$body .= "\t}\n\n";
			}
		}

		/// relations
		if(isset($this->model[$entityName]['relations'])){
			foreach ($this->model[$entityName]['relations'] as $relatedEntity => $cardinality) {
				/// use
				if($relatedEntity !== $entityName){
					$useStringToBeAdded = "use ".$this->entityNamespace."\\".ucfirst($relatedEntity).";";
				}
				/// check for duplicity
				if(false === strpos($use,$useStringToBeAdded)){
					$use .= $useStringToBeAdded."\n";
				}
				switch ($cardinality) {
					case 'ONE_TO_ONE':
					case '<<ONE_TO_ONE':
					case 'MANY_TO_ONE':
						/// use
						$useStringToBeAdded = "use WernerDweight\\Dobee\\LazyLoader\\SingleLazyLoader;";
						/// check for duplicity
						if(false === strpos($use,$useStringToBeAdded)){
							$use .= $useStringToBeAdded."\n";
						}
						/// declaration
						$class .= "\tprotected \$".$relatedEntity.";\n";
						/// setter
						$body .= "\tpublic function set".ucfirst($relatedEntity)."(\$".$relatedEntity."){\n";
						$body .= "\t\t\$this->".$relatedEntity." = \$".$relatedEntity.";\n";
						/// if an abstract entity is the relation set discriminator value
						if(true === array_key_exists('abstract',$this->model[$relatedEntity])){
							$body .= "\t\tif(\$this->".$relatedEntity." instanceof SingleLazyLoader){\n";
							$body .= "\t\t\t\$this->".$relatedEntity." = \$this->".$relatedEntity."->getData();\n";
							$body .= "\t\t}\n";
							$body .= "\t\t\$this->set".ucfirst($relatedEntity)."Class(get_class(\$this->".$relatedEntity."));\n";
						}
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						/// getter
						$body .= "\tpublic function get".ucfirst($relatedEntity)."(){\n";
						$body .= "\t\tif(\$this->".$relatedEntity." instanceof SingleLazyLoader){\n";
						$body .= "\t\t\t\$this->".$relatedEntity." = \$this->".$relatedEntity."->getData();\n";
						$body .= "\t\t}\n";
						$body .= "\t\treturn \$this->".$relatedEntity.";\n";
						$body .= "\t}\n\n";
						/// if an abstract entity is the relation add discriminator
						if(true === array_key_exists('abstract',$this->model[$relatedEntity])){
							$class .= "\tprotected \$".$relatedEntity."Class;\n";
							/// setter
							$body .= "\tpublic function set".ucfirst($relatedEntity)."Class(\$".$relatedEntity."Class){\n";
							$body .= "\t\t\$this->".$relatedEntity."Class = \$".$relatedEntity."Class;\n";
							$body .= "\t\treturn \$this;\n";
							$body .= "\t}\n\n";
							/// getter
							$body .= "\tpublic function get".ucfirst($relatedEntity)."Class(){\n";
							$body .= "\t\treturn \$this->".$relatedEntity."Class;\n";
							$body .= "\t}\n\n";
						}
						break;
					case 'SELF::ONE_TO_MANY':
					case 'SELF::MANY_TO_ONE':
						/// use
						$useStringToBeAdded = "use WernerDweight\\Dobee\\LazyLoader\\SingleLazyLoader;";
						/// check for duplicity
						if(false === strpos($use,$useStringToBeAdded)){
							$use .= $useStringToBeAdded."\n";
						}
						/// declaration
						$class .= "\tprotected \$parent".ucfirst($relatedEntity).";\n";
						/// setter
						$body .= "\tpublic function setParent".ucfirst($relatedEntity)."(\$parent".ucfirst($relatedEntity)."){\n";
						$body .= "\t\t\$this->parent".ucfirst($relatedEntity)." = \$parent".ucfirst($relatedEntity).";\n";
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						/// getter
						$body .= "\tpublic function getParent".ucfirst($relatedEntity)."(){\n";
						$body .= "\t\tif(\$this->parent".ucfirst($relatedEntity)." instanceof SingleLazyLoader){\n";
						$body .= "\t\t\t\$this->parent".ucfirst($relatedEntity)." = \$this->parent".ucfirst($relatedEntity)."->getData();\n";
						$body .= "\t\t}\n";
						$body .= "\t\treturn \$this->parent".ucfirst($relatedEntity).";\n";
						$body .= "\t}\n\n";
						/// no break here as we also need the 'to-many' methods
					case 'ONE_TO_MANY':
					case 'MANY_TO_MANY':
					case '<<MANY_TO_MANY':
						/// use
						$useStringToBeAdded = "use WernerDweight\\Dobee\\LazyLoader\\MultipleLazyLoader;";
						/// check for duplicity
						if(false === strpos($use,$useStringToBeAdded)){
							$use .= $useStringToBeAdded."\n";
						}
						/// declaration
						$class .= "\tprotected \$".Transformer::pluralize($relatedEntity).";\n";
						/// setters
						$body .= "\tpublic function add".ucfirst($relatedEntity)."(".ucfirst($relatedEntity)." \$".$relatedEntity."){\n";
						$body .= "\t\tif(true === method_exists(\$this,'add".ucfirst($relatedEntity)."BeforeListener')){\n";
						$body .= "\t\t\t\$this->add".ucfirst($relatedEntity)."BeforeListener(\$".$relatedEntity.");\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->load".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\tif(null !== \$".$relatedEntity."->getId()){\n";
						$body .= "\t\t\t\$this->".Transformer::pluralize($relatedEntity)."[\$".$relatedEntity."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()] = \$".$relatedEntity.";\n";
						$body .= "\t\t}\n";
						$body .= "\t\telse{\n";
						$body .= "\t\t\t\$this->".Transformer::pluralize($relatedEntity)."[] = \$".$relatedEntity.";\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\tif(true === method_exists(\$this,'add".ucfirst($relatedEntity)."AfterListener')){\n";
						$body .= "\t\t\t\$this->add".ucfirst($relatedEntity)."AfterListener(\$".$relatedEntity.");\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						$body .= "\tpublic function remove".ucfirst($relatedEntity)."(".ucfirst($relatedEntity)." \$".$relatedEntity."){\n";
						$body .= "\t\tif(true === method_exists(\$this,'remove".ucfirst($relatedEntity)."BeforeListener')){\n";
						$body .= "\t\t\t\$this->remove".ucfirst($relatedEntity)."BeforeListener();\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->load".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\tif(isset(\$this->".Transformer::pluralize($relatedEntity)."[\$".$relatedEntity."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()])){\n";
						$body .= "\t\t\tunset(\$this->".Transformer::pluralize($relatedEntity)."[\$".$relatedEntity."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()]);\n";
						$body .= "\t\t}\n";
						$body .= "\t\tif(true === method_exists(\$this,'remove".ucfirst($relatedEntity)."AfterListener')){\n";
						$body .= "\t\t\t\$this->remove".ucfirst($relatedEntity)."AfterListener();\n";
						$body .= "\t\t}\n\n";
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
				case 'SELF::MANY_TO_MANY':
						/// use
						$useStringToBeAdded = "use WernerDweight\\Dobee\\LazyLoader\\MultipleLazyLoader;";
						/// check for duplicity
						if(false === strpos($use,$useStringToBeAdded)){
							$use .= $useStringToBeAdded."\n";
						}
						/// MASTER
						/// declaration
						$class .= "\tprotected \$master".ucfirst(Transformer::pluralize($relatedEntity)).";\n";
						/// setters
						$body .= "\tpublic function addMaster".ucfirst($relatedEntity)."(".ucfirst($relatedEntity)." \$master".ucfirst($relatedEntity)."){\n";
						$body .= "\t\tif(true === method_exists(\$this,'addMaster".ucfirst($relatedEntity)."BeforeListener')){\n";
						$body .= "\t\t\t\$this->addMaster".ucfirst($relatedEntity)."BeforeListener(\$".$relatedEntity.");\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->loadMaster".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\tif(null !== \$master".ucfirst($relatedEntity)."->getId()){\n";
						$body .= "\t\t\t\$this->master".ucfirst(Transformer::pluralize($relatedEntity))."[\$master".ucfirst($relatedEntity)."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()] = \$master".ucfirst($relatedEntity).";\n";
						$body .= "\t\t}\n";
						$body .= "\t\telse{\n";
						$body .= "\t\t\t\$this->master".ucfirst(Transformer::pluralize($relatedEntity))."[] = \$master".ucfirst($relatedEntity).";\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\tif(true === method_exists(\$this,'addMaster".ucfirst($relatedEntity)."AfterListener')){\n";
						$body .= "\t\t\t\$this->addMaster".ucfirst($relatedEntity)."AfterListener(\$master".ucfirst($relatedEntity).");\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						$body .= "\tpublic function removeMaster".ucfirst($relatedEntity)."(".ucfirst($relatedEntity)." \$master".ucfirst($relatedEntity)."){\n";
						$body .= "\t\tif(true === method_exists(\$this,'removeMaster".ucfirst($relatedEntity)."BeforeListener')){\n";
						$body .= "\t\t\t\$this->removeMaster".ucfirst($relatedEntity)."BeforeListener();\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->loadMaster".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\tif(isset(\$this->master".ucfirst(Transformer::pluralize($relatedEntity))."[\$master".ucfirst($relatedEntity)."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()])){\n";
						$body .= "\t\t\tunset(\$this->master".ucfirst(Transformer::pluralize($relatedEntity))."[\$salve".ucfirst($relatedEntity)."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()]);\n";
						$body .= "\t\t}\n";
						$body .= "\t\tif(true === method_exists(\$this,'removeMaster".ucfirst($relatedEntity)."AfterListener')){\n";
						$body .= "\t\t\t\$this->removeMaster".ucfirst($relatedEntity)."AfterListener();\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						$body .= "\tpublic function setMaster".ucfirst(Transformer::pluralize($relatedEntity))."(\$master".ucfirst(Transformer::pluralize($relatedEntity))."){\n";
						$body .= "\t\t\$this->master".ucfirst(Transformer::pluralize($relatedEntity))." = \$master".ucfirst(Transformer::pluralize($relatedEntity)).";\n";
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						/// getters
						$body .= "\tpublic function getMaster".ucfirst(Transformer::pluralize($relatedEntity))."(){\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->loadMaster".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\treturn \$this->master".ucfirst(Transformer::pluralize($relatedEntity)).";\n";
						$body .= "\t}\n\n";
						$body .= "\tpublic function getMaster".ucfirst($relatedEntity)."(\$key){\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->loadMaster".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\tif(isset(\$this->master".ucfirst(Transformer::pluralize($relatedEntity))."[\$key])){\n";
						$body .= "\t\t\treturn \$this->master".ucfirst(Transformer::pluralize($relatedEntity))."[\$key];\n";
						$body .= "\t\t}\n";
						$body .= "\t\telse{\n";
						$body .= "\t\t\treturn null;\n";
						$body .= "\t\t}\n";
						$body .= "\t}\n\n";
						/// loader
						$body .= "\tpublic function loadMaster".ucfirst(Transformer::pluralize($relatedEntity))."(){\n";
						$body .= "\t\tif(\$this->master".ucfirst(Transformer::pluralize($relatedEntity))." instanceof MultipleLazyLoader){\n";
						$body .= "\t\t\t\$this->master".ucfirst(Transformer::pluralize($relatedEntity))."->loadData();\n";
						$body .= "\t\t}\n";
						$body .= "\t}\n\n";
						/// SLAVE
						/// declaration
						$class .= "\tprotected \$slave".ucfirst(Transformer::pluralize($relatedEntity)).";\n";
						/// setters
						$body .= "\tpublic function addSlave".ucfirst($relatedEntity)."(".ucfirst($relatedEntity)." \$slave".ucfirst($relatedEntity)."){\n";
						$body .= "\t\tif(true === method_exists(\$this,'addSlave".ucfirst($relatedEntity)."BeforeListener')){\n";
						$body .= "\t\t\t\$this->addSlave".ucfirst($relatedEntity)."BeforeListener(\$".$relatedEntity.");\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->loadSlave".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\tif(null !== \$slave".ucfirst($relatedEntity)."->getId()){\n";
						$body .= "\t\t\t\$this->slave".ucfirst(Transformer::pluralize($relatedEntity))."[\$slave".ucfirst($relatedEntity)."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()] = \$slave".ucfirst($relatedEntity).";\n";
						$body .= "\t\t}\n";
						$body .= "\t\telse{\n";
						$body .= "\t\t\t\$this->slave".ucfirst(Transformer::pluralize($relatedEntity))."[] = \$slave".ucfirst($relatedEntity).";\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\tif(true === method_exists(\$this,'addSlave".ucfirst($relatedEntity)."AfterListener')){\n";
						$body .= "\t\t\t\$this->addSlave".ucfirst($relatedEntity)."AfterListener(\$slave".ucfirst($relatedEntity).");\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						$body .= "\tpublic function removeSlave".ucfirst($relatedEntity)."(".ucfirst($relatedEntity)." \$slave".ucfirst($relatedEntity)."){\n";
						$body .= "\t\tif(true === method_exists(\$this,'removeSlave".ucfirst($relatedEntity)."BeforeListener')){\n";
						$body .= "\t\t\t\$this->removeSlave".ucfirst($relatedEntity)."BeforeListener();\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->loadSlave".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\tif(isset(\$this->slave".ucfirst(Transformer::pluralize($relatedEntity))."[\$slave".ucfirst($relatedEntity)."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()])){\n";
						$body .= "\t\t\tunset(\$this->slave".ucfirst(Transformer::pluralize($relatedEntity))."[\$salve".ucfirst($relatedEntity)."->get".ucfirst($this->getPrimaryKeyForEntity($relatedEntity))."()]);\n";
						$body .= "\t\t}\n";
						$body .= "\t\tif(true === method_exists(\$this,'removeSlave".ucfirst($relatedEntity)."AfterListener')){\n";
						$body .= "\t\t\t\$this->removeSlave".ucfirst($relatedEntity)."AfterListener();\n";
						$body .= "\t\t}\n\n";
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						$body .= "\tpublic function setSlave".ucfirst(Transformer::pluralize($relatedEntity))."(\$slave".ucfirst(Transformer::pluralize($relatedEntity))."){\n";
						$body .= "\t\t\$this->slave".ucfirst(Transformer::pluralize($relatedEntity))." = \$slave".ucfirst(Transformer::pluralize($relatedEntity)).";\n";
						$body .= "\t\treturn \$this;\n";
						$body .= "\t}\n\n";
						/// getters
						$body .= "\tpublic function getSlave".ucfirst(Transformer::pluralize($relatedEntity))."(){\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->loadSlave".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\treturn \$this->slave".ucfirst(Transformer::pluralize($relatedEntity)).";\n";
						$body .= "\t}\n\n";
						$body .= "\tpublic function getSlave".ucfirst($relatedEntity)."(\$key){\n";
						$body .= "\t\t/// check that items are loaded (if not load them)\n";
						$body .= "\t\t\$this->loadSlave".ucfirst(Transformer::pluralize($relatedEntity))."();\n\n";
						$body .= "\t\tif(isset(\$this->slave".ucfirst(Transformer::pluralize($relatedEntity))."[\$key])){\n";
						$body .= "\t\t\treturn \$this->slave".ucfirst(Transformer::pluralize($relatedEntity))."[\$key];\n";
						$body .= "\t\t}\n";
						$body .= "\t\telse{\n";
						$body .= "\t\t\treturn null;\n";
						$body .= "\t\t}\n";
						$body .= "\t}\n\n";
						/// loader
						$body .= "\tpublic function loadSlave".ucfirst(Transformer::pluralize($relatedEntity))."(){\n";
						$body .= "\t\tif(\$this->slave".ucfirst(Transformer::pluralize($relatedEntity))." instanceof MultipleLazyLoader){\n";
						$body .= "\t\t\t\$this->slave".ucfirst(Transformer::pluralize($relatedEntity))."->loadData();\n";
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

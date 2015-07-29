<?php

namespace WernerDweight\Dobee\Generator;

use WernerDweight\Dobee\Exception\DatabaseException;

class Comparator {

	protected $connection;
	protected $database;
	protected $currentSchema;
	protected $newSchema;
	
	public function __construct($connection,$database){
		$this->connection = $connection;
		$this->database = $database;
	}

	protected function fetchSchema($current = true) {
		$schema = array();

		if(!($tmpTables = $this->connection->query("SHOW TABLES WHERE tables_in_".$this->database." ".($current === true ? "NOT " : "")."LIKE 'tmp_%'"))){
			throw new DatabaseException("Schema comparsion failed: (".$this->connection->errno.") ".$this->connection->error);
		}
		
		while($table = $tmpTables->fetch_row()){
			$tableName = $table[0];
			if($current === false){
				$tableName = str_replace('tmp_','',$tableName);
			}
			$schema[$tableName] = array();
		}

		if(count($schema)){
			foreach ($schema as $table => $columns) {
				if(!($tmpColumns = $this->connection->query("SHOW COLUMNS FROM ".($current === false ? "tmp_" : "").$table))){
					throw new DatabaseException("Schema comparsion failed: (".$this->connection->errno.") ".$this->connection->error);
				}
				
				while($column = $tmpColumns->fetch_assoc()){
					$schema[$table][$column['Field']] = $column;
				}
			}
		}
		return $schema;
	}
	
	public function compareSchemas() {
		$this->currentSchema = $this->fetchSchema(true);
		$this->newSchema = $this->fetchSchema(false);

		$mergedSchema = array_unique(
			array_merge(
				array_keys($this->currentSchema),
				array_keys($this->newSchema)
			)
		);

		$diff = array(
			'tables' => array(
				'create' => array(),
				'drop' => array(),
			),
			'columns' => array(
				'add' => array(),
				'change' => array(),
				'drop' => array(),
			),
		);

		foreach ($mergedSchema as $table) {
			/// check if table exists in current schema
			if(!isset($this->currentSchema[$table])){
				$diff['tables']['create'][$table] = $this->newSchema[$table];
			}
			/// check if table exists in new schema
			else if(!isset($this->newSchema[$table])){
				$diff['tables']['drop'][] = $table;
			}
			else{
				/// check differencies in columns
				$columns = array_merge($this->currentSchema[$table],$this->newSchema[$table]);
				foreach ($columns as $column => $properties) {
					/// check if column exists in current schema
					if(!isset($this->currentSchema[$table][$column])){
						$diff['columns']['add'][$table][$column] = $properties;
					}
					/// check if column exists in new schema
					else if(!isset($this->newSchema[$table][$column])){
						$diff['columns']['drop'][$table][] = $column;
					}
					else{
						/// check differencies in columns' properties
						$currentProperties = $this->currentSchema[$table][$column];
						$newProperties = $this->newSchema[$table][$column];
						foreach ($currentProperties as $property => $settings) {
							if($currentProperties[$property] != $newProperties[$property]){
								$diff['columns']['change'][$table][$column] = $newProperties;
							}
						}
					}
				}
			}
		}

		return $diff;
	}
}

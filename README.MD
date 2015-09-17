Werner Dweight's Dobee
====================================

**Warning:**

> This utility IS STILL UNDER DEVELOPMENT and though it may work in a way
> quite a lot of code is still missing! Usage of this utility is discouraged
> until the version 1.0 is released!

This utility provides lightweight solution for handling your app model - all you create is a YAML configuration file of your model and Dobee will do the rest (create database, generate php classes for your entites and let you query for them, it can also handle updates to your model and basic inheritance).

Installation
------------

1) Download using composer

```js
	{
	    "require": {
	        "wernerdweight/dobee": "0.1.*@dev"
	    }
	}
```

2) Initialize Dobee in your project

```php
	<?php

	$dobee = new \WernerDweight\Dobee\Dobee('path/to/configuration/file.yml','path/to/strore/generated/entities','Your\\Namespace\\To\\Generated\\Entities');

```

3) Setup config

This is just a demo configuration, you'll probably want to write your own.

```yml
# path/to/configuration/file.yml
db:
    host: 127.0.0.1
    database: your_database_name
    user: root
    password: null
    port: null
model:
    base:
        abstract: ~
        primary: id
        properties:
            id:
                type: int
                primary: ~
                notNull: true
            dateCreated:
                type: datetime
                notNull: true
                default: CURRENT_TIMESTAMP
            deleted:
                type: bool
                notNull: true
                default: 0
        defaultOrderBy:
            dateCreated: desc
    content:
        abstract: ~
        extends: base
        properties:
            title:
                type: string
                length: 255
                notNull: true
    article:
        extends: content
        properties:
            perex:
                type: text
                length: 510
            text:
                type: text
        relations:
            category: <<MANY_TO_MANY
            author: MANY_TO_ONE
    category:
        extends: content
        relations:
            article: MANY_TO_MANY
    author:
        extends: base
        properties:
            firstName:
                type: string
                length: 80
                notNull: true
            lastName:
                type: string
                length: 80
                notNull: true
        relations:
            article: ONE_TO_MANY
            address: <<ONE_TO_ONE
        defaultOrderBy:
            lastName: asc
    address:
        extends: base
        properties:
            street2:
                type: string
                length: 80
                notNull: true
            street:
                type: string
                length: 255
                notNull: true
            city:
                type: string
                length: 80
                notNull: true
        relations:
            author: ONE_TO_ONE
    image:
        extends: content
        properties:
            path:
                type: string
                length: 255
                notNull: true
            filesize:
                type: int
                notNull: true
        relations:
            imageFolder: MANY_TO_ONE
    imageFolder:
        extends: content
        relations:
            image: ONE_TO_MANY
            imageFolder: SELF::ONE_TO_MANY
```

Usage
-----

Generate database from YAML config:
```php
		$options = array(
			'--dump',	/// outputs SQL for changes to be made to the database
			'--force'	/// forces changes to the database and regenerates php classes
			/// no more options available at the moment, you can use both options at once
		);
		
		$dobee->generate($options);

```

Query for entities:
```php	
		$db = $dobee->getProvider();

		/// FETCH SINGLE RESULT
		/// 'article' stands for entity name as configured in YAML configuration file
		/// 1 stands for ID of requested article
		$article = $dp->fetchOne('article',1);		/// will return either object of class Article or null

		/// sample of how to access properties of an Article (confront configuration above)
		echo $article->getTitle();
		echo $article->getAuthor()->getFirstName();	/// author is lazy-loaded from database when needed
		if(!is_null($article->getCategories())){	/// categories are lazy-loaded from database when needed
			foreach($article->getCategories() as $category){
				echo $category->getTitle();
			}
		}

		/// FETCH MULTIPLE RESULTS
		/// options are not mandatory - if omitted all items will be loaded form the database
		$options = array(
			'leftJoin' => array(
				'this.author' => 'author',
				'author.address' => 'adress'
			),
			'where' => array(
				'this.title' => array(
					'operator' => 'like',
					'value' => '%microbe%'
				)
				'this.id' => array(
					'operator' => 'gte',
					'value' => 4
				)
			),
			'order' => array(
				'this.title' => 'asc',
				'this.id' => 'desc'
			),
			'limit' => array(
				'firstResult' => 0,
				'maxResults' => 10
			)
		);

		$articles = $db->fetch('article',$options);

```

License
-------
This utility is under the MIT license. See the complete license in the root directiory.

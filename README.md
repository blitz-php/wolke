Blitz PHP Database
==============

[![Downloads this Month](https://img.shields.io/packagist/dm/blitz-php/database.svg)](https://packagist.org/packages/blitz-php/database)
[![Tests](https://github.com/blitz-php/database/workflows/Tests/badge.svg?branch=master)](https://github.com/blitz-php/database/actions)
[![Build Status Windows](https://ci.appveyor.com/api/projects/status/github/blitz-php/database?branch=master&svg=true)](https://ci.appveyor.com/project/dg/database/branch/master)
[![Latest Stable Version](https://poser.pugx.org/blitz-php/database/v/stable)](https://github.com/blitz-php/database/releases)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/blitz-php/database/blob/master/LICENSE)


Introduction
------------

Le composant Blitz Database est une boîte à outils complète de base de données pour PHP, fournissant un générateur de requête expressif, un ORM de style ActiveRecord et un générateur de schéma. Il prend actuellement en charge MySQL, Postgres, SQL Server et SQLite. Il sert également de couche de base de données du framework Blitz PHP.

Blitz PHP fournit une couche puissante pour accéder facilement à votre base de données.

- Compose facilement des requêtes SQL
- Récupère facilement les données
- utilise des requêtes efficaces et ne transmet pas de données inutiles

Le noyau de base de données Blitz est un wrapper autour du PDO et fournit des fonctionnalités de base.

Le Query Builder de Blitz vous aide à récupérer les données de la base de données plus facilement et de manière plus optimisée.


Installation
------------

La méthode d'installation recommandée est via Composer :

```
composer require blitz-php/database
```

It requires PHP version 8.0 and supports PHP up to 8.2.


Utilisation
-----

Ceci n'est qu'un document. [Veuillez consulter notre site Web](https://doc.blitz-php.org/database) .


Tout d'abord, vous devez utiliser la méthode `BlitzPHP\Database\Database::connection()` pour avoir la connexion à la base de données correspondant à votre pilote:

```php
$database = \BlitzPHP\Database\Database::connection([
	'driver'    => 'mysql',
	'host'      => 'localhost',
	'database'  => 'database',
	'username'  => 'root',
	'password'  => 'password',
	'charset'   => 'utf8',
	'collation' => 'utf8_unicode_ci',
	'prefix'    => '',
]);
```

Une fois l'instance Connection créée, vous pouvez interroger facilement votre base de données en appelant la méthode `query`:

```php
$database->query('SELECT * FROM categories');
```

**Utilisation du générateur de requêtes**
Dans la plus part des cas, vous n'ecriverez pas des requêtes SQL en dur. BlitzPHP/Database possède un générateur de requête simple, leger et performant pour toutes vos manipulations de données.

```php 
$builder = 
```
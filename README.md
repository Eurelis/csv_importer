# csv_importer
Drupal 8 - CSV Import

## Usage

### How to map

#### structure.yml

```YAML
# Main structure
commandes:
  friendly_user_title: Commandes
  table_name: commandes
  structure_schema_version: 1
  csv_file_name: 'commandes'
  fields:
    -
      name: cid
      unique: yes
    -
      name: prix
    - 
      name: date
    - 
      name: nom
    - 
      name: prenom

# Legacy structure
commandes:
  - cid
  - prix
  - date
  - nom
  - prenom
```

Don't forget to prepare the table before importing. The previous structure.yml sample should work with this schema:

```PHP
<?php

function csv_importer_schema() {

  $schema['commandes'] = array(
    'description' => 'Les commandes',
    'fields' => array(
      'cid' => array(
        'description' => 'Primary Key: Commandes unique ID.',
        'type' => 'serial',
        'unsigned' => TRUE,
        'not null' => TRUE,
      ),
      'prix' => array(
        'description' => 'montant.',
        'type' => 'float',
        'unsigned' => TRUE,
        'not null' => TRUE,
      ),
      'date' => array(
        'description' => 'Date textuelle',
        'type' => 'varchar',
        'length' => 255,
        'not null' => TRUE,
        'default' => '',
      ),
      'nom' => array(
        'description' => 'nom',
        'type' => 'varchar',
        'length' => 255,
        'not null' => TRUE,
        'default' => '',
      ),
      'prenom' => array(
        'description' => 'prenom',
        'type' => 'varchar',
        'length' => 255,
        'not null' => TRUE,
        'default' => '',
      ),
    ),
    'primary key' => array('cid'),
  );

  return $schema;
}
```

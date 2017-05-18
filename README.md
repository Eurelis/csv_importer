# csv_importer
Drupal 8 - CSV Import

## Usage

### Schema definition in structure.yml

```YAML
# Main schema
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

# Legacy schema
commandes:
  - prix
  - date
  - nom
  - prenom
```

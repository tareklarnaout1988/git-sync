# COUNTRIES INFO

This module provides a taxonomy of countries (Countries information), providing
details such as the ISO2 code, ISO3 code, country name, official name, and
numeric code.

## FEATURES

1. This module includes the standard country name, official name, ISO 3166-1
   alpha-2 code, ISO 3166-1 alpha-3 code, UN numeric code (ISO 3166-1 numeric-3)
   and continent (Africa, Antarctica, Asia, Europe, North America, Oceania,
   South America).

   <br>For example, Taiwan has the following values:
   - Name           - Taiwan
   - Official name  - Taiwan, Republic of China
   - ISO alpha-2    - TW
   - ISO alpha-3    - TWN
   - ISO numeric-3  - 158
   - Continent      - Asia
   - Published      - Yes

   <br>The official names were initially sourced from Wikipedia, and most of the
   continent's information was imported from the Country Codes API project. This
   data has since been standardized according to the ISO 3166-1 standard.

2. Since this is taxonomy-based, users can reference it as an entity in any
   content type, which can then be utilized in searches, facet filters,
   and more.

3. User can enable or disable any country from the 'Countries information'
   taxonomy term list.

## REQUIREMENTS

This module requires no modules outside of Drupal core.

## INSTALLATION

### Using the Drupal User Interface (easy):

1. Navigate to the 'Extend' page (admin/modules) via the manage administrative
   menu.
2. Locate the Countries Info module and select the checkbox next to it.
3. Click on 'Install' to enable the Countries Info module.

### Or use the command line (advanced, but very efficient).

- To enable Countries Info module with Drush, execute the command
  below: <br> `drush en countries_info`

## CONFIGURATION

The module has no menu or modifiable settings. There is no configuration.

## SIMILAR MODULES

[Countries Taxonomy](https://www.drupal.org/project/countries_taxonomy) - This
module does not include information such as the ISO3 code, country name,
official name, numeric code, or continent.

## MAINTAINERS

- Dhaval Panara (dhaval_panara) - https://www.drupal.org/u/dhaval_panara

## REFERENCES

[Countries](https://www.drupal.org/project/countries)

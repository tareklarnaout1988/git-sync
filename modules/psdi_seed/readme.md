Seeds Create taxonomies and import Dimensions/SubDimensions/Indicators (inline, no file).

Command lines
drush en -y psdi_seed
drush cr
drush psdi:setup-taxonomy
drush psdi:seed-inline


to make sure that Drupal can see the service
drush list | grep psdi
tu dois voir psdi:setup-taxonomy et psdi:seed-inline
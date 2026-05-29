<?php

namespace Drupal\psdi_seed\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\psdi_seed\Setup\TaxonomySetup;
use Drupal\taxonomy\Entity\Term;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

final class PsdiSeedCommands extends DrushCommands
{

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly TaxonomySetup $setup,
  ) {}

  /**
   * Create vocabularies + fields for PSDI.
   *
   * @command psdi:setup-taxonomy
   * @aliases psdi-setup
   */
  #[CLI\Command(name: 'psdi:setup-taxonomy', description: 'Create vocabularies + fields for PSDI')]
  public function setup(): void
  {
    $this->setup->ensure();
    $this->io()->success('Vocabularies and fields ensured.');
  }

  /**
   * Seed inline (no file): Dimensions, SubDimensions, Indicators.
   *
   * @command psdi:seed-inline
   * @aliases psdi-seed
   */
  #[CLI\Command(name: 'psdi:seed-inline', description: 'Seed inline (no file): Dimensions, SubDimensions, Indicators')]
  public function seedInline(): void
  {
    $this->setup->ensure();

    // 1) Dimensions (weight 0.2 everywhere).
    foreach ($this->dimensionWeights() as $dimName => $w) {
      $this->getOrCreateTerm('dimension', $dimName, ['field_weight' => $this->normalizeDecimal($w)]);
    }

    // 2) Subdimensions with weights.
    foreach ($this->subdimensionWeights() as [$dimension, $subdimension, $weight]) {
      $dim_tid = $this->getOrCreateTerm('dimension', $dimension);
      $this->getOrCreateTerm('subdimension', $subdimension, [
        'field_weight' => $this->normalizeDecimal($weight),
        'field_dimension' => $dim_tid,
      ]);
    }

    // 3) Indicators with weight + subdimension + machine_name.
    $rows = $this->indicatorData();
    $count = 0;
    foreach ($rows as $r) {
      $dim  = trim($r['category']);
      $sub  = trim($r['subcategory']);
      $code = trim($r['indic_name']);       // machine_name to write
      $name = trim($r['indicator_name']);   // label
      $w    = $this->normalizeDecimal($r['weight']);

      $dim_tid = $this->getOrCreateTerm('dimension', $dim);
      $sub_tid = $this->getOrCreateTerm('subdimension', $sub, ['field_dimension' => $dim_tid]);

      $this->upsertIndicatorByMachineName($code, $name, [
        'field_weight'       => $w,
        'field_subdimension' => $sub_tid,
      ]);
      $count++;
    }

    $this->io()->success("Seeded/updated $count indicators (inline) + dimensions/subdimensions weights.");
  }

  private function normalizeDecimal($v): ?string
  {
    if ($v === null || $v === '') return null;
    $v = str_replace(',', '.', (string) $v);
    return is_numeric($v) ? (string) $v : null;
  }

  private function getOrCreateTerm(string $vid, string $name, array $fields = []): int
  {
    $storage = $this->etm->getStorage('taxonomy_term');
    $existing = $storage->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = $existing ? reset($existing) : Term::create(['vid' => $vid, 'name' => $name]);

    foreach ($fields as $field => $value) {
      if ($value === null || $value === '') {
        continue;
      }
      if (in_array($field, ['field_dimension', 'field_subdimension'], TRUE)) {
        $term->set($field, ['target_id' => (int) $value]);
      } else {
        $term->set($field, $value);
      }
    }
    $term->save();
    return (int) $term->id();
  }

  /**
   * Upsert an Indicator by the base field "machine_name" (Taxonomy Machine Name).
   *
   * - First lookup by vid+machine_name,
   * - Fallback to name if not found,
   * - Create if missing; update label, weight, parent; machine_name set to $machine.
   *   If you don't want to override machine_name on existing terms, comment that line.
   */
  private function upsertIndicatorByMachineName(string $machine, string $label, array $fields = []): int
  {
    $storage = $this->etm->getStorage('taxonomy_term');

    // on ne cherche QUE par machine_name
    $existing = $storage->loadByProperties([
      'vid' => 'indicator',
      'machine_name' => $machine,
    ]);
    $term = $existing ? reset($existing) : null;

    if (!$term) {
      $term = Term::create([
        'vid' => 'indicator',
        'name' => $label,
        'machine_name' => $machine,
      ]);
    } else {
      $term->setName($label);
      // si tu ne veux jamais changer le machine_name après coup, commente cette ligne :
      $term->set('machine_name', $machine);
    }

    foreach ($fields as $field => $value) {
      if ($value === null || $value === '') {
        continue;
      }
      if ($field === 'field_subdimension') {
        $term->set($field, ['target_id' => (int) $value]);
      } else {
        $term->set($field, $value);
      }
    }

    $term->save();
    return (int) $term->id();
  }

  /** Dimension weights (all 0.2). */
  private function dimensionWeights(): array
  {
    return [
      'Power and Electricity' => '0,2',
      'Food Sovereignty'               => '0,2',
      'Regional Integration'          => '0,2',
      'Industrialization'      => '0,2',
      'Socioeconomic Inclusion'           => '0,2',
    ];
  }

  /** Subdimension weights (from your first screenshot). */
  private function subdimensionWeights(): array
  {
    return [
      // Power and Electricity
      ['Power and Electricity', 'Electricity access /use', '0,2790'],
      ['Power and Electricity', 'Climate change and green  growth', '0,2232'],
      ['Power and Electricity', 'Electricity regulatory framework', '0,2806'],
      ['Power and Electricity', 'Electricity generation', '0,2171'],

      // Food Sovereignty
      ['Food Sovereignty', 'Agricultural value chain', '0,2531'],
      ['Food Sovereignty', 'Eliminate hunger-Food Security', '0,2001'],
      ['Food Sovereignty', 'End extreme poverty', '0,2692'],
      ['Food Sovereignty', 'Net products (exports diversity)', '0,2776'],

      // Regional Integration
      ['Regional Integration', 'Ratification of regional agreements', '0,2754'],
      ['Regional Integration', 'Freedom of movement', '0,2570'],
      ['Regional Integration', 'Intra-Africa trade', '0,2175'],
      ['Regional Integration', 'Infrastructure (Roads Networks)', '0,2501'],

      // Industrialization
      ['Industrialization', 'Infrastructure (industrial parks)', '0,5000'],
      ['Industrialization', 'Enabling business environment', '0,5000'],

      // Socioeconomic Inclusion
      ['Socioeconomic Inclusion', 'Youth and employment and training', '0,2131'],
      ['Socioeconomic Inclusion', 'Eliminate Poverty and Inequality', '0,1826'],
      ['Socioeconomic Inclusion', 'Gender & Women Empowerment', '0,2089'],
      ['Socioeconomic Inclusion', 'Health', '0,1910'],
      ['Socioeconomic Inclusion', 'Water and Sanitation', '0,2044'],
    ];
  }

  /** Full indicator list (second screenshot + paste). */
  private function indicatorData(): array
  {
    return [
      // Power and Electricity
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity access /use', 'indic_name' => 'light_access_clean_fuel', 'indicator_name' => 'Access to clean fuels and technologies for cooking (% of population)', 'weight' => '0,1428'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity access /use', 'indic_name' => 'light_access_elec', 'indicator_name' => 'Population access to electricity-National (% of population)', 'weight' => '0,1543'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity access /use', 'indic_name' => 'light_access_elec_rur', 'indicator_name' => 'Population access to electricity-Rural (% of population)', 'weight' => '0,1235'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity access /use', 'indic_name' => 'light_access_elec_ur', 'indicator_name' => 'Population access to electricity-Urban (% of population)', 'weight' => '0,1434'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity access /use', 'indic_name' => 'light_cons_elec_cap', 'indicator_name' => 'Electricity final consumption per capita (KWh)', 'weight' => '0,1401'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity access /use', 'indic_name' => 'light_nrj_intensity', 'indicator_name' => 'Energy intensity level of primary energy (MJ/$2017 PPP GDP)', 'weight' => '0,1539'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity access /use', 'indic_name' => 'light_nrj_renew_cons', 'indicator_name' => 'Renewable energy consumption (% of total final energy consumption)', 'weight' => '0,1419'],

      ['category' => 'Power and Electricity', 'subcategory' => 'Climate change and green  growth', 'indic_name' => 'light_co2_em', 'indicator_name' => 'CO2 emissions (Metric tons per capita)', 'weight' => '0,1473'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Climate change and green  growth', 'indic_name' => 'light_economic_loss', 'indicator_name' => 'Direct economic loss attributed to disasters relative to GDP (%)', 'weight' => '0,1554'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Climate change and green  growth', 'indic_name' => 'light_elec_prod_hydro', 'indicator_name' => 'Production of Hydroelectricity  (kWh per capita)', 'weight' => '0,1185'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Climate change and green  growth', 'indic_name' => 'light_elec_prod_solw', 'indicator_name' => 'Production of electricity from solar, wind, tide, wave and other sources   (KWh per capita)', 'weight' => '0,1149'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Climate change and green  growth', 'indic_name' => 'light_env_export', 'indicator_name' => 'Environmental goods exports as share of total exports', 'weight' => '0,1356'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Climate change and green  growth', 'indic_name' => 'light_forest_land', 'indicator_name' => 'Forest land (% of land area)', 'weight' => '0,1234'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Climate change and green  growth', 'indic_name' => 'light_ghg', 'indicator_name' => 'Total greenhouse gas emissions (Metric tons of CO2 equivalent per capita)', 'weight' => '0,1363'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Climate change and green  growth', 'indic_name' => 'light_pm25_pollution', 'indicator_name' => 'PM2.5 air pollution, Mean annual exposure (micrograms per cubic meter)', 'weight' => '0,0686'],

      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity generation', 'indic_name' => 'light_elec_gen_tot', 'indicator_name' => 'Electricity generation (KWh per capita)', 'weight' => '0,2130'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity generation', 'indic_name' => 'light_elec_renew_gen', 'indicator_name' => 'Share of renewable electricity generation to total electricty generation', 'weight' => '0,2507'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity generation', 'indic_name' => 'light_level_off_grid', 'indicator_name' => 'Increase of off-grid renewable electricity generation', 'weight' => '0,2919'],
      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity generation', 'indic_name' => 'light_level_on_grid', 'indicator_name' => 'Increase of on-grid renewable electricity generation', 'weight' => '0,2444'],

      ['category' => 'Power and Electricity', 'subcategory' => 'Electricity regulatory framework', 'indic_name' => 'light_elec_eri', 'indicator_name' => 'Electricity regulatory composite', 'weight' => '1'],

      // Food Sovereignty
      ['category' => 'Food Sovereignty', 'subcategory' => 'Agricultural value chain', 'indic_name' => 'feed_agr_prod', 'indicator_name' => 'Agriculture Gross per Capita Production Index Number', 'weight' => '0,5000'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'Agricultural value chain', 'indic_name' => 'feed_agr_add', 'indicator_name' => 'Agriculture, value added (% of GDP)', 'weight' => '0,5000'],

      ['category' => 'Food Sovereignty', 'subcategory' => 'Eliminate hunger-Food Security', 'indic_name' => 'feed_daily_cal', 'indicator_name' => 'Daily calorie supply (per capita )', 'weight' => '0,3244'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'Eliminate hunger-Food Security', 'indic_name' => 'feed_prev_stunt', 'indicator_name' => 'Prevalence of stunting, height for age (modeled estimate, % of children under 5)', 'weight' => '0,3469'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'Eliminate hunger-Food Security', 'indic_name' => 'feed_prev_undernourr', 'indicator_name' => 'Prevalence of undernourishment (%)', 'weight' => '0,3286'],

      ['category' => 'Food Sovereignty', 'subcategory' => 'End extreme poverty', 'indic_name' => 'feed_arable_land', 'indicator_name' => 'Arable land (% of land area)', 'weight' => '0,2230'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'End extreme poverty', 'indic_name' => 'feed_fert_cons', 'indicator_name' => 'Fertilizers by Nutrient Use per area of cropland (kg/ha)', 'weight' => '0,2065'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'End extreme poverty', 'indic_name' => 'feed_irrig_land', 'indicator_name' => 'Irrigated Land (as % of land area)', 'weight' => '0,2022'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'End extreme poverty', 'indic_name' => 'feed_pasture_land', 'indicator_name' => 'Permanent pastures (as % of land area)', 'weight' => '0,1711'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'End extreme poverty', 'indic_name' => 'feed_water_eff', 'indicator_name' => 'Water Use Efficiency (United States dollars per cubic meter)', 'weight' => '0,1972'],

      ['category' => 'Food Sovereignty', 'subcategory' => 'Net products (exports diversity)', 'indic_name' => 'feed_food_aid', 'indicator_name' => 'Food Aid in Cereals (MT per capita)', 'weight' => '0,2726'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'Net products (exports diversity)', 'indic_name' => 'feed_food_exp_tot', 'indicator_name' => 'Exports of Food and Live Animals (% of Total Exports)', 'weight' => '0,2483'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'Net products (exports diversity)', 'indic_name' => 'feed_food_imp_tot', 'indicator_name' => 'Imports of Food and Live Animals (% of Total Imports)', 'weight' => '0,2455'],
      ['category' => 'Food Sovereignty', 'subcategory' => 'Net products (exports diversity)', 'indic_name' => 'feed_food_net_exp_value', 'indicator_name' => 'Value of net Food and Live Animals exports', 'weight' => '0,2337'],

      // Regional Integration
      ['category' => 'Regional Integration', 'subcategory' => 'Ratification of regional agreements', 'indic_name' => 'integ_afcfta', 'indicator_name' => 'Ratification of African Continental Free Trade Area', 'weight' => '0,4036'],
      ['category' => 'Regional Integration', 'subcategory' => 'Ratification of regional agreements', 'indic_name' => 'integ_intra_exp', 'indicator_name' => 'Intra-Exports (as % of Total export )', 'weight' => '0,2740'],
      ['category' => 'Regional Integration', 'subcategory' => 'Ratification of regional agreements', 'indic_name' => 'integ_level_intra_trade', 'indicator_name' => 'Level of Country participation in intra-Africa trade flows', 'weight' => '0,3223'],

      ['category' => 'Regional Integration', 'subcategory' => 'Freedom of movement', 'indic_name' => 'integ_air_conn', 'indicator_name' => 'Air connectivity scores(Country connectedness to the world) per 1000 persons', 'weight' => '0,2939'],
      ['category' => 'Regional Integration', 'subcategory' => 'Freedom of movement', 'indic_name' => 'integ_air_trans_pass', 'indicator_name' => 'Air transport, passengers carried per 1000 persons', 'weight' => '0,3206'],
      ['category' => 'Regional Integration', 'subcategory' => 'Freedom of movement', 'indic_name' => 'integ_visa_arriv_free', 'indicator_name' => 'Liberal Access Rate(no visa or visa on arrival)', 'weight' => '0,3855'],

      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_air_trans_frei', 'indicator_name' => 'Air transport, freight   (million ton-km)', 'weight' => '0,1095'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_debt_ser', 'indicator_name' => 'Total debt service: interest and amortization paid (as % of Exports of Goods & services)', 'weight' => '0,0857'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_exchange_rate', 'indicator_name' => 'Exchange Rate Developments, (per US$, unless otherwise indicated)', 'weight' => '0,0971'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_fdi_cap', 'indicator_name' => 'Foreign direct investment inflows per Capita (USD)', 'weight' => '0,1021'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_gross_capit_priv', 'indicator_name' => 'Gross capital formation, Private sector  (% of GDP)', 'weight' => '0,0885'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_ntof', 'indicator_name' => 'Net Total Official Flows from All Donors (NOF) (Cur, US$ per capita)', 'weight' => '0,0944'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_time_bus_start', 'indicator_name' => 'Time required to start a business (days)', 'weight' => '0,0876'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_time_exp_cross_bord', 'indicator_name' => 'Time to import, border compliance (hours)', 'weight' => '0,1196'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_time_imp_cross_bord', 'indicator_name' => 'Time to export, border compliance (hours)', 'weight' => '0,1168'],
      ['category' => 'Regional Integration', 'subcategory' => 'Intra-Africa trade', 'indic_name' => 'integ_trade_out_afr', 'indicator_name' => 'Total trade outside Africa( as % Total Trade)', 'weight' => '0,0987'],

      ['category' => 'Regional Integration', 'subcategory' => 'Infrastructure (Roads Networks)', 'indic_name' => 'integ_death_road', 'indicator_name' => 'Mortality caused by road traffic injury (per 100,000 population)', 'weight' => '0,3292'],
      ['category' => 'Regional Integration', 'subcategory' => 'Infrastructure (Roads Networks)', 'indic_name' => 'integ_paved_road', 'indicator_name' => 'Roads paved (% of total roads)', 'weight' => '0,3181'],
      ['category' => 'Regional Integration', 'subcategory' => 'Infrastructure (Roads Networks)', 'indic_name' => 'integ_road_network', 'indicator_name' => 'Roads total network (km)', 'weight' => '0,3527'],

      // Industrialization
      ['category' => 'Industrialization', 'subcategory' => 'Infrastructure (industrial parks)', 'indic_name' => 'indus_aidi', 'indicator_name' => 'African Infrastructure Development Index(AIDI)', 'weight' => '0,2820'],
      ['category' => 'Industrialization', 'subcategory' => 'Infrastructure (industrial parks)', 'indic_name' => 'indus_indus_value', 'indicator_name' => 'Industry, value added   (% of GDP)', 'weight' => '0,2823'],
      ['category' => 'Industrialization', 'subcategory' => 'Infrastructure (industrial parks)', 'indic_name' => 'indus_manuf_empl', 'indicator_name' => 'Manufacturing employment as a proportion of total employment (%)  (ILO estimates)', 'weight' => '0,2162'],
      ['category' => 'Industrialization', 'subcategory' => 'Infrastructure (industrial parks)', 'indic_name' => 'indus_manuf_value', 'indicator_name' => 'Manufacturing, value added (% of GDP)', 'weight' => '0,2195'],

      ['category' => 'Industrialization', 'subcategory' => 'Enabling business environment', 'indic_name' => 'indus_bus_reg_env', 'indicator_name' => 'CPIA Business Regulatory Environment', 'weight' => '0,1755'],
      ['category' => 'Industrialization', 'subcategory' => 'Enabling business environment', 'indic_name' => 'indus_fdi_gdp', 'indicator_name' => 'Foreign Direct Investment (% of Gross capital formation)', 'weight' => '0,2311'],
      ['category' => 'Industrialization', 'subcategory' => 'Enabling business environment', 'indic_name' => 'indus_time_bus_close', 'indicator_name' => 'Time required to enforce a contract (days)', 'weight' => '0,2332'],
      ['category' => 'Industrialization', 'subcategory' => 'Enabling business environment', 'indic_name' => 'indus_time_bus_start', 'indicator_name' => 'Time required to start a business (days)', 'weight' => '0,1848'],
      ['category' => 'Industrialization', 'subcategory' => 'Enabling business environment', 'indic_name' => 'indus_time_pro_register', 'indicator_name' => 'Time required to register property (days)', 'weight' => '0,1753'],

      // Socioeconomic Inclusion
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Youth and employment and training', 'indic_name' => 'ql_ad_literacy', 'indicator_name' => 'Adult literacy rate, total (% of people ages 15 and above)', 'weight' => '0,1276'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Youth and employment and training', 'indic_name' => 'ql_empl_skills', 'indicator_name' => 'Share of Employment of High Skill level Occupation to Total Employment for all Occupations', 'weight' => '0,1248'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Youth and employment and training', 'indic_name' => 'ql_pupil_teach', 'indicator_name' => 'Pupil-teacher ratio, primary school', 'weight' => '0,1057'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Youth and employment and training', 'indic_name' => 'ql_school_compl_rate', 'indicator_name' => 'Primary completion rate, total (% of relevant age group)', 'weight' => '0,1361'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Youth and employment and training', 'indic_name' => 'ql_school_enroll', 'indicator_name' => 'School enrollment, primary, total (% net)', 'weight' => '0,1341'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Youth and employment and training', 'indic_name' => 'ql_school_mean_years', 'indicator_name' => 'Mean Years of Schooling Ranges (0 to 18)', 'weight' => '0,1228'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Youth and employment and training', 'indic_name' => 'ql_share_neet', 'indicator_name' => 'Share of youth not in employment, education or training (NEET): Total (%)', 'weight' => '0,1325'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Youth and employment and training', 'indic_name' => 'ql_unempl_y', 'indicator_name' => 'Unemployment rate (%) (Youth, adults: 15-24 years)', 'weight' => '0,1163'],

      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Eliminate Poverty and Inequality', 'indic_name' => 'ql_broadband_sub', 'indicator_name' => 'Fixed broadband subscriptions (per 100 people)', 'weight' => '0,1497'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Eliminate Poverty and Inequality', 'indic_name' => 'ql_cov_3g', 'indicator_name' => 'Proportion of population covered by at least a 3G mobile network (%)', 'weight' => '0,1320'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Eliminate Poverty and Inequality', 'indic_name' => 'ql_ed_expenditure', 'indicator_name' => 'Total Government expenditure on education (% of GDP)', 'weight' => '0,1375'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Eliminate Poverty and Inequality', 'indic_name' => 'ql_internet_users', 'indicator_name' => 'Internet users (% of population)', 'weight' => '0,1200'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Eliminate Poverty and Inequality', 'indic_name' => 'ql_mob_sub', 'indicator_name' => 'Mobile cellular subscriptions (per 100 people)', 'weight' => '0,1288'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Eliminate Poverty and Inequality', 'indic_name' => 'ql_pop_slum', 'indicator_name' => 'Proportion of urban population living in slums (%)', 'weight' => '0,1414'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Eliminate Poverty and Inequality', 'indic_name' => 'ql_unempl_rate', 'indicator_name' => 'Unemployment rate (%) for Youth, adults: 15+ years', 'weight' => '0,1907'],

      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Gender & Women Empowerment', 'indic_name' => 'ql_bus_score_f', 'indicator_name' => 'Women Business and the Law Index Score (scale 1-100)', 'weight' => '0,1485'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Gender & Women Empowerment', 'indic_name' => 'ql_gender_eq', 'indicator_name' => 'CPIA Gender equality', 'weight' => '0,1938'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Gender & Women Empowerment', 'indic_name' => 'ql_parliament_f', 'indicator_name' => 'Proportion of seats held by women in national parliaments (%)', 'weight' => '0,1735'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Gender & Women Empowerment', 'indic_name' => 'ql_share_neet_f', 'indicator_name' => 'Share of youth not in employment, education or training (NEET) Female (%)', 'weight' => '0,1521'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Gender & Women Empowerment', 'indic_name' => 'ql_social_prot', 'indicator_name' => 'CPIA Social protection labor', 'weight' => '0,1681'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Gender & Women Empowerment', 'indic_name' => 'ql_unempl_yf', 'indicator_name' => 'Unemployment rate (%) Age (Youth, female): 15-24', 'weight' => '0,1639'],

      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_dalys_cooking', 'indicator_name' => "DALYs Household air pollution from solid fuels", 'weight' => '0,0888'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_dalys_ozone', 'indicator_name' => "DALYs Ambient ozone pollution", 'weight' => '0,0959'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_dalys_particules', 'indicator_name' => "DALYs Ambient particulate matter pollution", 'weight' => '0,0851'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_death_five', 'indicator_name' => 'Under-Five Mortality Rate (per 1,000 live births)', 'weight' => '0,0943'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_death_maternal', 'indicator_name' => 'Maternal Mortality Ratios (per 100 000 live births)', 'weight' => '0,0884'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_death_neonat', 'indicator_name' => 'Mortality rate, neonatal (per 1,000 live births)', 'weight' => '0,0900'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_haq_index', 'indicator_name' => 'Healthcare access quality (HAQ) Index', 'weight' => '0,0908'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_health_exp_cap', 'indicator_name' => 'Current health expenditure per capita (current US$)', 'weight' => '0,0917'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_hh_health_out', 'indicator_name' => 'Out-of-pocket health expenditure (% of current health expenditure)', 'weight' => '0,0949'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_life_exp', 'indicator_name' => 'Life expectancy at birth, total (years)', 'weight' => '0,0925'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Health', 'indic_name' => 'ql_prop_doctors', 'indicator_name' => 'Medical Doctors (per 10,000 people)', 'weight' => '0,0875'],

      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Water and Sanitation', 'indic_name' => 'ql_dalys_sani', 'indicator_name' => 'DALYs Unsafe sanitation', 'weight' => '0,1703'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Water and Sanitation', 'indic_name' => 'ql_dalys_water', 'indicator_name' => 'DALYs Unsafe water source', 'weight' => '0,1702'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Water and Sanitation', 'indic_name' => 'ql_sani_basic_nat', 'indicator_name' => 'People using at least basic sanitation services (% of population)', 'weight' => '0,1663'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Water and Sanitation', 'indic_name' => 'ql_sani_basic_rur', 'indicator_name' => 'People using at least basic sanitation services, rural', 'weight' => '0,1665'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Water and Sanitation', 'indic_name' => 'ql_water_basic_nat', 'indicator_name' => 'People using at least basic drinking water services (% of population)', 'weight' => '0,1613'],
      ['category' => 'Socioeconomic Inclusion', 'subcategory' => 'Water and Sanitation', 'indic_name' => 'ql_water_basic_rur', 'indicator_name' => 'People using at least basic drinking water services, rural', 'weight' => '0,1654'],
    ];
  }
}

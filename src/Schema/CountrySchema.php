<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\CountryModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class CountrySchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'countries';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on()->createIfNotExists('countries', function (Blueprint $t) {
            $t->id('country_id');
            $t->char('iso2', 2);
            $t->char('iso3', 3);
            $t->string('country_name', 100);
            $t->string('phone_code', 5);

            $t->unique('iso2');
            $t->unique('iso3');
        });
    }

    /**
     * Default Values to Insert
     * @return void
     */
    public function seed(): void
    {
        $model = new CountryModel();
        $model->transaction(function (CountryModel $m) {
            try {
                $m->insert($this->countries());
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        return;
    }

    /**
     * Get Default Countries List
     * @return array
     */
    public function countries(): array
    {
        return [
            ['iso2' => 'AF', 'iso3' => 'AFG', 'country_name' => 'Afghanistan', 'phone_code' => '+93'],
            ['iso2' => 'AL', 'iso3' => 'ALB', 'country_name' => 'Albania', 'phone_code' => '+355'],
            ['iso2' => 'DZ', 'iso3' => 'DZA', 'country_name' => 'Algeria', 'phone_code' => '+213'],
            ['iso2' => 'AS', 'iso3' => 'ASM', 'country_name' => 'American Samoa', 'phone_code' => '+1-684'],
            ['iso2' => 'AD', 'iso3' => 'AND', 'country_name' => 'Andorra', 'phone_code' => '+376'],
            ['iso2' => 'AO', 'iso3' => 'AGO', 'country_name' => 'Angola', 'phone_code' => '+244'],
            ['iso2' => 'AI', 'iso3' => 'AIA', 'country_name' => 'Anguilla', 'phone_code' => '+1-264'],
            ['iso2' => 'AG', 'iso3' => 'ATG', 'country_name' => 'Antigua and Barbuda', 'phone_code' => '+1-268'],
            ['iso2' => 'AR', 'iso3' => 'ARG', 'country_name' => 'Argentina', 'phone_code' => '+54'],
            ['iso2' => 'AM', 'iso3' => 'ARM', 'country_name' => 'Armenia', 'phone_code' => '+374'],
            ['iso2' => 'AW', 'iso3' => 'ABW', 'country_name' => 'Aruba', 'phone_code' => '+297'],
            ['iso2' => 'AU', 'iso3' => 'AUS', 'country_name' => 'Australia', 'phone_code' => '+61'],
            ['iso2' => 'AT', 'iso3' => 'AUT', 'country_name' => 'Austria', 'phone_code' => '+43'],
            ['iso2' => 'AZ', 'iso3' => 'AZE', 'country_name' => 'Azerbaijan', 'phone_code' => '+994'],
            ['iso2' => 'BS', 'iso3' => 'BHS', 'country_name' => 'Bahamas', 'phone_code' => '+1-242'],
            ['iso2' => 'BH', 'iso3' => 'BHR', 'country_name' => 'Bahrain', 'phone_code' => '+973'],
            ['iso2' => 'BD', 'iso3' => 'BGD', 'country_name' => 'Bangladesh', 'phone_code' => '+880'],
            ['iso2' => 'BB', 'iso3' => 'BRB', 'country_name' => 'Barbados', 'phone_code' => '+1-246'],
            ['iso2' => 'BY', 'iso3' => 'BLR', 'country_name' => 'Belarus', 'phone_code' => '+375'],
            ['iso2' => 'BE', 'iso3' => 'BEL', 'country_name' => 'Belgium', 'phone_code' => '+32'],
            ['iso2' => 'BZ', 'iso3' => 'BLZ', 'country_name' => 'Belize', 'phone_code' => '+501'],
            ['iso2' => 'BJ', 'iso3' => 'BEN', 'country_name' => 'Benin', 'phone_code' => '+229'],
            ['iso2' => 'BM', 'iso3' => 'BMU', 'country_name' => 'Bermuda', 'phone_code' => '+1-441'],
            ['iso2' => 'BT', 'iso3' => 'BTN', 'country_name' => 'Bhutan', 'phone_code' => '+975'],
            ['iso2' => 'BO', 'iso3' => 'BOL', 'country_name' => 'Bolivia', 'phone_code' => '+591'],
            ['iso2' => 'BA', 'iso3' => 'BIH', 'country_name' => 'Bosnia and Herzegovina', 'phone_code' => '+387'],
            ['iso2' => 'BW', 'iso3' => 'BWA', 'country_name' => 'Botswana', 'phone_code' => '+267'],
            ['iso2' => 'BR', 'iso3' => 'BRA', 'country_name' => 'Brazil', 'phone_code' => '+55'],
            ['iso2' => 'BN', 'iso3' => 'BRN', 'country_name' => 'Brunei Darussalam', 'phone_code' => '+673'],
            ['iso2' => 'BG', 'iso3' => 'BGR', 'country_name' => 'Bulgaria', 'phone_code' => '+359'],
            ['iso2' => 'BF', 'iso3' => 'BFA', 'country_name' => 'Burkina Faso', 'phone_code' => '+226'],
            ['iso2' => 'BI', 'iso3' => 'BDI', 'country_name' => 'Burundi', 'phone_code' => '+257'],
            ['iso2' => 'CV', 'iso3' => 'CPV', 'country_name' => 'Cape Verde', 'phone_code' => '+238'],
            ['iso2' => 'KH', 'iso3' => 'KHM', 'country_name' => 'Cambodia', 'phone_code' => '+855'],
            ['iso2' => 'CM', 'iso3' => 'CMR', 'country_name' => 'Cameroon', 'phone_code' => '+237'],
            ['iso2' => 'CA', 'iso3' => 'CAN', 'country_name' => 'Canada', 'phone_code' => '+1'],
            ['iso2' => 'KY', 'iso3' => 'CYM', 'country_name' => 'Cayman Islands', 'phone_code' => '+1-345'],
            ['iso2' => 'CF', 'iso3' => 'CAF', 'country_name' => 'Central African Republic', 'phone_code' => '+236'],
            ['iso2' => 'TD', 'iso3' => 'TCD', 'country_name' => 'Chad', 'phone_code' => '+235'],
            ['iso2' => 'CL', 'iso3' => 'CHL', 'country_name' => 'Chile', 'phone_code' => '+56'],
            ['iso2' => 'CN', 'iso3' => 'CHN', 'country_name' => 'China', 'phone_code' => '+86'],
            ['iso2' => 'CO', 'iso3' => 'COL', 'country_name' => 'Colombia', 'phone_code' => '+57'],
            ['iso2' => 'KM', 'iso3' => 'COM', 'country_name' => 'Comoros', 'phone_code' => '+269'],
            ['iso2' => 'CD', 'iso3' => 'COD', 'country_name' => 'Congo (Democratic Republic)', 'phone_code' => '+243'],
            ['iso2' => 'CG', 'iso3' => 'COG', 'country_name' => 'Congo (Republic)', 'phone_code' => '+242'],
            ['iso2' => 'CK', 'iso3' => 'COK', 'country_name' => 'Cook Islands', 'phone_code' => '+682'],
            ['iso2' => 'CR', 'iso3' => 'CRI', 'country_name' => 'Costa Rica', 'phone_code' => '+506'],
            ['iso2' => 'CI', 'iso3' => 'CIV', 'country_name' => 'Côte d\'Ivoire', 'phone_code' => '+225'],
            ['iso2' => 'HR', 'iso3' => 'HRV', 'country_name' => 'Croatia', 'phone_code' => '+385'],
            ['iso2' => 'CU', 'iso3' => 'CUB', 'country_name' => 'Cuba', 'phone_code' => '+53'],
            ['iso2' => 'CW', 'iso3' => 'CUW', 'country_name' => 'Curaçao', 'phone_code' => '+599'],
            ['iso2' => 'CY', 'iso3' => 'CYP', 'country_name' => 'Cyprus', 'phone_code' => '+357'],
            ['iso2' => 'CZ', 'iso3' => 'CZE', 'country_name' => 'Czechia', 'phone_code' => '+420'],
            ['iso2' => 'DK', 'iso3' => 'DNK', 'country_name' => 'Denmark', 'phone_code' => '+45'],
            ['iso2' => 'DJ', 'iso3' => 'DJI', 'country_name' => 'Djibouti', 'phone_code' => '+253'],
            ['iso2' => 'DM', 'iso3' => 'DMA', 'country_name' => 'Dominica', 'phone_code' => '+1-767'],
            ['iso2' => 'DO', 'iso3' => 'DOM', 'country_name' => 'Dominican Republic', 'phone_code' => '+1-809'],
            ['iso2' => 'EC', 'iso3' => 'ECU', 'country_name' => 'Ecuador', 'phone_code' => '+593'],
            ['iso2' => 'EG', 'iso3' => 'EGY', 'country_name' => 'Egypt', 'phone_code' => '+20'],
            ['iso2' => 'SV', 'iso3' => 'SLV', 'country_name' => 'El Salvador', 'phone_code' => '+503'],
            ['iso2' => 'GQ', 'iso3' => 'GNQ', 'country_name' => 'Equatorial Guinea', 'phone_code' => '+240'],
            ['iso2' => 'ER', 'iso3' => 'ERI', 'country_name' => 'Eritrea', 'phone_code' => '+291'],
            ['iso2' => 'EE', 'iso3' => 'EST', 'country_name' => 'Estonia', 'phone_code' => '+372'],
            ['iso2' => 'SZ', 'iso3' => 'SWZ', 'country_name' => 'Eswatini', 'phone_code' => '+268'],
            ['iso2' => 'ET', 'iso3' => 'ETH', 'country_name' => 'Ethiopia', 'phone_code' => '+251'],
            ['iso2' => 'FK', 'iso3' => 'FLK', 'country_name' => 'Falkland Islands', 'phone_code' => '+500'],
            ['iso2' => 'FO', 'iso3' => 'FRO', 'country_name' => 'Faroe Islands', 'phone_code' => '+298'],
            ['iso2' => 'FJ', 'iso3' => 'FJI', 'country_name' => 'Fiji', 'phone_code' => '+679'],
            ['iso2' => 'FI', 'iso3' => 'FIN', 'country_name' => 'Finland', 'phone_code' => '+358'],
            ['iso2' => 'FR', 'iso3' => 'FRA', 'country_name' => 'France', 'phone_code' => '+33'],
            ['iso2' => 'GF', 'iso3' => 'GUF', 'country_name' => 'French Guiana', 'phone_code' => '+594'],
            ['iso2' => 'PF', 'iso3' => 'PYF', 'country_name' => 'French Polynesia', 'phone_code' => '+689'],
            ['iso2' => 'GA', 'iso3' => 'GAB', 'country_name' => 'Gabon', 'phone_code' => '+241'],
            ['iso2' => 'GM', 'iso3' => 'GMB', 'country_name' => 'Gambia', 'phone_code' => '+220'],
            ['iso2' => 'GE', 'iso3' => 'GEO', 'country_name' => 'Georgia', 'phone_code' => '+995'],
            ['iso2' => 'DE', 'iso3' => 'DEU', 'country_name' => 'Germany', 'phone_code' => '+49'],
            ['iso2' => 'GH', 'iso3' => 'GHA', 'country_name' => 'Ghana', 'phone_code' => '+233'],
            ['iso2' => 'GI', 'iso3' => 'GIB', 'country_name' => 'Gibraltar', 'phone_code' => '+350'],
            ['iso2' => 'GR', 'iso3' => 'GRC', 'country_name' => 'Greece', 'phone_code' => '+30'],
            ['iso2' => 'GL', 'iso3' => 'GRL', 'country_name' => 'Greenland', 'phone_code' => '+299'],
            ['iso2' => 'GD', 'iso3' => 'GRD', 'country_name' => 'Grenada', 'phone_code' => '+1-473'],
            ['iso2' => 'GP', 'iso3' => 'GLP', 'country_name' => 'Guadeloupe', 'phone_code' => '+590'],
            ['iso2' => 'GU', 'iso3' => 'GUM', 'country_name' => 'Guam', 'phone_code' => '+1-671'],
            ['iso2' => 'GT', 'iso3' => 'GTM', 'country_name' => 'Guatemala', 'phone_code' => '+502'],
            ['iso2' => 'GN', 'iso3' => 'GIN', 'country_name' => 'Guinea', 'phone_code' => '+224'],
            ['iso2' => 'GW', 'iso3' => 'GNB', 'country_name' => 'Guinea-Bissau', 'phone_code' => '+245'],
            ['iso2' => 'GY', 'iso3' => 'GUY', 'country_name' => 'Guyana', 'phone_code' => '+592'],
            ['iso2' => 'HT', 'iso3' => 'HTI', 'country_name' => 'Haiti', 'phone_code' => '+509'],
            ['iso2' => 'HN', 'iso3' => 'HND', 'country_name' => 'Honduras', 'phone_code' => '+504'],
            ['iso2' => 'HK', 'iso3' => 'HKG', 'country_name' => 'Hong Kong', 'phone_code' => '+852'],
            ['iso2' => 'HU', 'iso3' => 'HUN', 'country_name' => 'Hungary', 'phone_code' => '+36'],
            ['iso2' => 'IS', 'iso3' => 'ISL', 'country_name' => 'Iceland', 'phone_code' => '+354'],
            ['iso2' => 'IN', 'iso3' => 'IND', 'country_name' => 'India', 'phone_code' => '+91'],
            ['iso2' => 'ID', 'iso3' => 'IDN', 'country_name' => 'Indonesia', 'phone_code' => '+62'],
            ['iso2' => 'IR', 'iso3' => 'IRN', 'country_name' => 'Iran', 'phone_code' => '+98'],
            ['iso2' => 'IQ', 'iso3' => 'IRQ', 'country_name' => 'Iraq', 'phone_code' => '+964'],
            ['iso2' => 'IE', 'iso3' => 'IRL', 'country_name' => 'Ireland', 'phone_code' => '+353'],
            ['iso2' => 'IL', 'iso3' => 'ISR', 'country_name' => 'Israel', 'phone_code' => '+972'],
            ['iso2' => 'IT', 'iso3' => 'ITA', 'country_name' => 'Italy', 'phone_code' => '+39'],
            ['iso2' => 'JM', 'iso3' => 'JAM', 'country_name' => 'Jamaica', 'phone_code' => '+1-876'],
            ['iso2' => 'JP', 'iso3' => 'JPN', 'country_name' => 'Japan', 'phone_code' => '+81'],
            ['iso2' => 'JO', 'iso3' => 'JOR', 'country_name' => 'Jordan', 'phone_code' => '+962'],
            ['iso2' => 'KZ', 'iso3' => 'KAZ', 'country_name' => 'Kazakhstan', 'phone_code' => '+7'],
            ['iso2' => 'KE', 'iso3' => 'KEN', 'country_name' => 'Kenya', 'phone_code' => '+254'],
            ['iso2' => 'KI', 'iso3' => 'KIR', 'country_name' => 'Kiribati', 'phone_code' => '+686'],
            ['iso2' => 'KP', 'iso3' => 'PRK', 'country_name' => 'Korea (North)', 'phone_code' => '+850'],
            ['iso2' => 'KR', 'iso3' => 'KOR', 'country_name' => 'Korea (South)', 'phone_code' => '+82'],
            ['iso2' => 'XK', 'iso3' => 'XKX', 'country_name' => 'Kosovo', 'phone_code' => '+383'],
            ['iso2' => 'KW', 'iso3' => 'KWT', 'country_name' => 'Kuwait', 'phone_code' => '+965'],
            ['iso2' => 'KG', 'iso3' => 'KGZ', 'country_name' => 'Kyrgyzstan', 'phone_code' => '+996'],
            ['iso2' => 'LA', 'iso3' => 'LAO', 'country_name' => 'Laos', 'phone_code' => '+856'],
            ['iso2' => 'LV', 'iso3' => 'LVA', 'country_name' => 'Latvia', 'phone_code' => '+371'],
            ['iso2' => 'LB', 'iso3' => 'LBN', 'country_name' => 'Lebanon', 'phone_code' => '+961'],
            ['iso2' => 'LS', 'iso3' => 'LSO', 'country_name' => 'Lesotho', 'phone_code' => '+266'],
            ['iso2' => 'LR', 'iso3' => 'LBR', 'country_name' => 'Liberia', 'phone_code' => '+231'],
            ['iso2' => 'LY', 'iso3' => 'LBY', 'country_name' => 'Libya', 'phone_code' => '+218'],
            ['iso2' => 'LI', 'iso3' => 'LIE', 'country_name' => 'Liechtenstein', 'phone_code' => '+423'],
            ['iso2' => 'LT', 'iso3' => 'LTU', 'country_name' => 'Lithuania', 'phone_code' => '+370'],
            ['iso2' => 'LU', 'iso3' => 'LUX', 'country_name' => 'Luxembourg', 'phone_code' => '+352'],
            ['iso2' => 'MO', 'iso3' => 'MAC', 'country_name' => 'Macao', 'phone_code' => '+853'],
            ['iso2' => 'MG', 'iso3' => 'MDG', 'country_name' => 'Madagascar', 'phone_code' => '+261'],
            ['iso2' => 'MW', 'iso3' => 'MWI', 'country_name' => 'Malawi', 'phone_code' => '+265'],
            ['iso2' => 'MY', 'iso3' => 'MYS', 'country_name' => 'Malaysia', 'phone_code' => '+60'],
            ['iso2' => 'MV', 'iso3' => 'MDV', 'country_name' => 'Maldives', 'phone_code' => '+960'],
            ['iso2' => 'ML', 'iso3' => 'MLI', 'country_name' => 'Mali', 'phone_code' => '+223'],
            ['iso2' => 'MT', 'iso3' => 'MLT', 'country_name' => 'Malta', 'phone_code' => '+356'],
            ['iso2' => 'MH', 'iso3' => 'MHL', 'country_name' => 'Marshall Islands', 'phone_code' => '+692'],
            ['iso2' => 'MQ', 'iso3' => 'MTQ', 'country_name' => 'Martinique', 'phone_code' => '+596'],
            ['iso2' => 'MR', 'iso3' => 'MRT', 'country_name' => 'Mauritania', 'phone_code' => '+222'],
            ['iso2' => 'MU', 'iso3' => 'MUS', 'country_name' => 'Mauritius', 'phone_code' => '+230'],
            ['iso2' => 'MX', 'iso3' => 'MEX', 'country_name' => 'Mexico', 'phone_code' => '+52'],
            ['iso2' => 'FM', 'iso3' => 'FSM', 'country_name' => 'Micronesia', 'phone_code' => '+691'],
            ['iso2' => 'MD', 'iso3' => 'MDA', 'country_name' => 'Moldova', 'phone_code' => '+373'],
            ['iso2' => 'MC', 'iso3' => 'MCO', 'country_name' => 'Monaco', 'phone_code' => '+377'],
            ['iso2' => 'MN', 'iso3' => 'MNG', 'country_name' => 'Mongolia', 'phone_code' => '+976'],
            ['iso2' => 'ME', 'iso3' => 'MNE', 'country_name' => 'Montenegro', 'phone_code' => '+382'],
            ['iso2' => 'MS', 'iso3' => 'MSR', 'country_name' => 'Montserrat', 'phone_code' => '+1-664'],
            ['iso2' => 'MA', 'iso3' => 'MAR', 'country_name' => 'Morocco', 'phone_code' => '+212'],
            ['iso2' => 'MZ', 'iso3' => 'MOZ', 'country_name' => 'Mozambique', 'phone_code' => '+258'],
            ['iso2' => 'MM', 'iso3' => 'MMR', 'country_name' => 'Myanmar', 'phone_code' => '+95'],
            ['iso2' => 'NA', 'iso3' => 'NAM', 'country_name' => 'Namibia', 'phone_code' => '+264'],
            ['iso2' => 'NR', 'iso3' => 'NRU', 'country_name' => 'Nauru', 'phone_code' => '+674'],
            ['iso2' => 'NP', 'iso3' => 'NPL', 'country_name' => 'Nepal', 'phone_code' => '+977'],
            ['iso2' => 'NL', 'iso3' => 'NLD', 'country_name' => 'Netherlands', 'phone_code' => '+31'],
            ['iso2' => 'NC', 'iso3' => 'NCL', 'country_name' => 'New Caledonia', 'phone_code' => '+687'],
            ['iso2' => 'NZ', 'iso3' => 'NZL', 'country_name' => 'New Zealand', 'phone_code' => '+64'],
            ['iso2' => 'NI', 'iso3' => 'NIC', 'country_name' => 'Nicaragua', 'phone_code' => '+505'],
            ['iso2' => 'NE', 'iso3' => 'NER', 'country_name' => 'Niger', 'phone_code' => '+227'],
            ['iso2' => 'NG', 'iso3' => 'NGA', 'country_name' => 'Nigeria', 'phone_code' => '+234'],
            ['iso2' => 'NU', 'iso3' => 'NIU', 'country_name' => 'Niue', 'phone_code' => '+683'],
            ['iso2' => 'MK', 'iso3' => 'MKD', 'country_name' => 'North Macedonia', 'phone_code' => '+389'],
            ['iso2' => 'MP', 'iso3' => 'MNP', 'country_name' => 'Northern Mariana Islands', 'phone_code' => '+1-670'],
            ['iso2' => 'NO', 'iso3' => 'NOR', 'country_name' => 'Norway', 'phone_code' => '+47'],
            ['iso2' => 'OM', 'iso3' => 'OMN', 'country_name' => 'Oman', 'phone_code' => '+968'],
            ['iso2' => 'PK', 'iso3' => 'PAK', 'country_name' => 'Pakistan', 'phone_code' => '+92'],
            ['iso2' => 'PW', 'iso3' => 'PLW', 'country_name' => 'Palau', 'phone_code' => '+680'],
            ['iso2' => 'PS', 'iso3' => 'PSE', 'country_name' => 'Palestine', 'phone_code' => '+970'],
            ['iso2' => 'PA', 'iso3' => 'PAN', 'country_name' => 'Panama', 'phone_code' => '+507'],
            ['iso2' => 'PG', 'iso3' => 'PNG', 'country_name' => 'Papua New Guinea', 'phone_code' => '+675'],
            ['iso2' => 'PY', 'iso3' => 'PRY', 'country_name' => 'Paraguay', 'phone_code' => '+595'],
            ['iso2' => 'PE', 'iso3' => 'PER', 'country_name' => 'Peru', 'phone_code' => '+51'],
            ['iso2' => 'PH', 'iso3' => 'PHL', 'country_name' => 'Philippines', 'phone_code' => '+63'],
            ['iso2' => 'PL', 'iso3' => 'POL', 'country_name' => 'Poland', 'phone_code' => '+48'],
            ['iso2' => 'PT', 'iso3' => 'PRT', 'country_name' => 'Portugal', 'phone_code' => '+351'],
            ['iso2' => 'PR', 'iso3' => 'PRI', 'country_name' => 'Puerto Rico', 'phone_code' => '+1-787'],
            ['iso2' => 'QA', 'iso3' => 'QAT', 'country_name' => 'Qatar', 'phone_code' => '+974'],
            ['iso2' => 'RE', 'iso3' => 'REU', 'country_name' => 'Réunion', 'phone_code' => '+262'],
            ['iso2' => 'RO', 'iso3' => 'ROU', 'country_name' => 'Romania', 'phone_code' => '+40'],
            ['iso2' => 'RU', 'iso3' => 'RUS', 'country_name' => 'Russian Federation', 'phone_code' => '+7'],
            ['iso2' => 'RW', 'iso3' => 'RWA', 'country_name' => 'Rwanda', 'phone_code' => '+250'],
            ['iso2' => 'KN', 'iso3' => 'KNA', 'country_name' => 'Saint Kitts and Nevis', 'phone_code' => '+1-869'],
            ['iso2' => 'LC', 'iso3' => 'LCA', 'country_name' => 'Saint Lucia', 'phone_code' => '+1-758'],
            ['iso2' => 'PM', 'iso3' => 'SPM', 'country_name' => 'Saint Pierre and Miquelon', 'phone_code' => '+508'],
            ['iso2' => 'VC', 'iso3' => 'VCT', 'country_name' => 'Saint Vincent and the Grenadines', 'phone_code' => '+1-784'],
            ['iso2' => 'WS', 'iso3' => 'WSM', 'country_name' => 'Samoa', 'phone_code' => '+685'],
            ['iso2' => 'SM', 'iso3' => 'SMR', 'country_name' => 'San Marino', 'phone_code' => '+378'],
            ['iso2' => 'ST', 'iso3' => 'STP', 'country_name' => 'São Tomé and Príncipe', 'phone_code' => '+239'],
            ['iso2' => 'SA', 'iso3' => 'SAU', 'country_name' => 'Saudi Arabia', 'phone_code' => '+966'],
            ['iso2' => 'SN', 'iso3' => 'SEN', 'country_name' => 'Senegal', 'phone_code' => '+221'],
            ['iso2' => 'RS', 'iso3' => 'SRB', 'country_name' => 'Serbia', 'phone_code' => '+381'],
            ['iso2' => 'SC', 'iso3' => 'SYC', 'country_name' => 'Seychelles', 'phone_code' => '+248'],
            ['iso2' => 'SL', 'iso3' => 'SLE', 'country_name' => 'Sierra Leone', 'phone_code' => '+232'],
            ['iso2' => 'SG', 'iso3' => 'SGP', 'country_name' => 'Singapore', 'phone_code' => '+65'],
            ['iso2' => 'SX', 'iso3' => 'SXM', 'country_name' => 'Sint Maarten', 'phone_code' => '+1-721'],
            ['iso2' => 'SK', 'iso3' => 'SVK', 'country_name' => 'Slovakia', 'phone_code' => '+421'],
            ['iso2' => 'SI', 'iso3' => 'SVN', 'country_name' => 'Slovenia', 'phone_code' => '+386'],
            ['iso2' => 'SB', 'iso3' => 'SLB', 'country_name' => 'Solomon Islands', 'phone_code' => '+677'],
            ['iso2' => 'SO', 'iso3' => 'SOM', 'country_name' => 'Somalia', 'phone_code' => '+252'],
            ['iso2' => 'ZA', 'iso3' => 'ZAF', 'country_name' => 'South Africa', 'phone_code' => '+27'],
            ['iso2' => 'SS', 'iso3' => 'SSD', 'country_name' => 'South Sudan', 'phone_code' => '+211'],
            ['iso2' => 'ES', 'iso3' => 'ESP', 'country_name' => 'Spain', 'phone_code' => '+34'],
            ['iso2' => 'LK', 'iso3' => 'LKA', 'country_name' => 'Sri Lanka', 'phone_code' => '+94'],
            ['iso2' => 'SD', 'iso3' => 'SDN', 'country_name' => 'Sudan', 'phone_code' => '+249'],
            ['iso2' => 'SR', 'iso3' => 'SUR', 'country_name' => 'Suriname', 'phone_code' => '+597'],
            ['iso2' => 'SE', 'iso3' => 'SWE', 'country_name' => 'Sweden', 'phone_code' => '+46'],
            ['iso2' => 'CH', 'iso3' => 'CHE', 'country_name' => 'Switzerland', 'phone_code' => '+41'],
            ['iso2' => 'SY', 'iso3' => 'SYR', 'country_name' => 'Syria', 'phone_code' => '+963'],
            ['iso2' => 'TW', 'iso3' => 'TWN', 'country_name' => 'Taiwan', 'phone_code' => '+886'],
            ['iso2' => 'TJ', 'iso3' => 'TJK', 'country_name' => 'Tajikistan', 'phone_code' => '+992'],
            ['iso2' => 'TZ', 'iso3' => 'TZA', 'country_name' => 'Tanzania', 'phone_code' => '+255'],
            ['iso2' => 'TH', 'iso3' => 'THA', 'country_name' => 'Thailand', 'phone_code' => '+66'],
            ['iso2' => 'TL', 'iso3' => 'TLS', 'country_name' => 'Timor-Leste', 'phone_code' => '+670'],
            ['iso2' => 'TG', 'iso3' => 'TGO', 'country_name' => 'Togo', 'phone_code' => '+228'],
            ['iso2' => 'TO', 'iso3' => 'TON', 'country_name' => 'Tonga', 'phone_code' => '+676'],
            ['iso2' => 'TT', 'iso3' => 'TTO', 'country_name' => 'Trinidad and Tobago', 'phone_code' => '+1-868'],
            ['iso2' => 'TN', 'iso3' => 'TUN', 'country_name' => 'Tunisia', 'phone_code' => '+216'],
            ['iso2' => 'TR', 'iso3' => 'TUR', 'country_name' => 'Türkiye', 'phone_code' => '+90'],
            ['iso2' => 'TM', 'iso3' => 'TKM', 'country_name' => 'Turkmenistan', 'phone_code' => '+993'],
            ['iso2' => 'TC', 'iso3' => 'TCA', 'country_name' => 'Turks and Caicos Islands', 'phone_code' => '+1-649'],
            ['iso2' => 'TV', 'iso3' => 'TUV', 'country_name' => 'Tuvalu', 'phone_code' => '+688'],
            ['iso2' => 'UG', 'iso3' => 'UGA', 'country_name' => 'Uganda', 'phone_code' => '+256'],
            ['iso2' => 'UA', 'iso3' => 'UKR', 'country_name' => 'Ukraine', 'phone_code' => '+380'],
            ['iso2' => 'AE', 'iso3' => 'ARE', 'country_name' => 'United Arab Emirates', 'phone_code' => '+971'],
            ['iso2' => 'GB', 'iso3' => 'GBR', 'country_name' => 'United Kingdom', 'phone_code' => '+44'],
            ['iso2' => 'US', 'iso3' => 'USA', 'country_name' => 'United States of America', 'phone_code' => '+1'],
            ['iso2' => 'UY', 'iso3' => 'URY', 'country_name' => 'Uruguay', 'phone_code' => '+598'],
            ['iso2' => 'UZ', 'iso3' => 'UZB', 'country_name' => 'Uzbekistan', 'phone_code' => '+998'],
            ['iso2' => 'VU', 'iso3' => 'VUT', 'country_name' => 'Vanuatu', 'phone_code' => '+678'],
            ['iso2' => 'VA', 'iso3' => 'VAT', 'country_name' => 'Vatican City', 'phone_code' => '+39-06'],
            ['iso2' => 'VE', 'iso3' => 'VEN', 'country_name' => 'Venezuela', 'phone_code' => '+58'],
            ['iso2' => 'VN', 'iso3' => 'VNM', 'country_name' => 'Vietnam', 'phone_code' => '+84'],
            ['iso2' => 'VG', 'iso3' => 'VGB', 'country_name' => 'Virgin Islands (British)', 'phone_code' => '+1-284'],
            ['iso2' => 'VI', 'iso3' => 'VIR', 'country_name' => 'Virgin Islands (US)', 'phone_code' => '+1-340'],
            ['iso2' => 'WF', 'iso3' => 'WLF', 'country_name' => 'Wallis and Futuna', 'phone_code' => '+681'],
            ['iso2' => 'EH', 'iso3' => 'ESH', 'country_name' => 'Western Sahara', 'phone_code' => '+212'],
            ['iso2' => 'YE', 'iso3' => 'YEM', 'country_name' => 'Yemen', 'phone_code' => '+967'],
            ['iso2' => 'ZM', 'iso3' => 'ZMB', 'country_name' => 'Zambia', 'phone_code' => '+260'],
            ['iso2' => 'ZW', 'iso3' => 'ZWE', 'country_name' => 'Zimbabwe', 'phone_code' => '+263']
        ];
    }
}

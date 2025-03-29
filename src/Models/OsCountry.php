<?php

namespace Joomla\Plugin\System\Altoimporter\Models;

use Illuminate\Database\Eloquent\Model;
use Joomla\CMS\Factory;

class OsCountry extends Model
{
    public $timestamps = false;

    protected $table;

    protected $fillable = [
        'id',
        'country_name',
        'country_code',
        'country_name_cy',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Factory::getDbo()->getPrefix() . 'osrs_countries';
    }
}

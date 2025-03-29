<?php

namespace Joomla\Plugin\System\Altoimporter\Models;

use Illuminate\Database\Eloquent\Model;
use Joomla\CMS\Factory;

class OsCity extends Model
{
    public $timestamps = false;

    protected $table;

    protected $fillable = [
        'id',
        'city',
        'country_id',
        'state_id',
        'published',
        'city_cy',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Factory::getDbo()->getPrefix() . 'osrs_cities';
    }
}

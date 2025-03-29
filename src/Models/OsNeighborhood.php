<?php

namespace Joomla\Plugin\System\Altoimporter\Models;

use Illuminate\Database\Eloquent\Model;
use Joomla\CMS\Factory;

class OsNeighborhood extends Model
{
    public $timestamps = false;

    protected $table;

    protected $fillable = [
        'id',
        'pid',
        'neighbor_id',
        'mins',
        'traffic_type',
        'distance'
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Factory::getDbo()->getPrefix() . 'osrs_neighborhood';
    }
}

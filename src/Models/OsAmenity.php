<?php

namespace Joomla\Plugin\System\Altoimporter\Models;

use Illuminate\Database\Eloquent\Model;
use Joomla\CMS\Factory;

class OsAmenity extends Model
{
    public $timestamps = false;

    protected $table;

    protected $fillable = [
        'id',
        'category_id',
        'amenities',
        'icon',
        'ordering',
        'published',
        'amenities_cy',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Factory::getDbo()->getPrefix() . 'osrs_amenities';
    }
}

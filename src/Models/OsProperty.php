<?php

namespace Joomla\Plugin\System\Altoimporter\Models;

use Illuminate\Database\Eloquent\Model;

class OsProperty extends Model
{
    protected $table;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Joomla-style prefix replacement
        $this->table = \Joomla\CMS\Factory::getDbo()->replacePrefix('#__osrs_properties');
    }

    public $timestamps = false;

    protected $fillable = [
        'id',
        'alto_id',
        'ref',
        'pro_name',
        'pro_desc',
        'price',
        'bed_room',
        'bath_room',
        'square',
        'address',
        'city',
        'postcode',
        'state',
        'country',
        'lat',
        'lng',
        'published'
    ];
}

<?php

namespace Joomla\Plugin\System\Altoimporter\Models;

use Illuminate\Database\Eloquent\Model;
use Joomla\CMS\Factory;

class OsPhoto extends Model
{
    public $timestamps = false;

    protected $table;

    protected $fillable = [
        'id',
        'pro_id',
        'image',
        'image_desc',
        'ordering',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Factory::getDbo()->getPrefix() . 'osrs_photos';
    }
}

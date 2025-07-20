<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasFactory;

    // Указываем, что первичный ключ - это 'key', а не 'id'
    protected $primaryKey = 'key';

    // Указываем, что первичный ключ не является автоинкрементным
    public $incrementing = false;

    // Указываем тип первичного ключа (строка)
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'request_method',
        'request_uri',
        'request_headers',
        'request_body',
        'response_status',
        'response_body',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request_headers' => 'array',
        'request_body' => 'array',
        'response_body' => 'array',
        'expires_at' => 'datetime',
    ];
}

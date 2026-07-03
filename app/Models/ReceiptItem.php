<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'item_name',
        'price',
        'qty',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'qty' => 'integer',
        ];
    }

    /**
     * Receipt item belongs to a transaction.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

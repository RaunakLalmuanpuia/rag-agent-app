<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    //
    protected $fillable = ['filename', 'title', 'version'];

    public function chunks()
    {
        return $this->hasMany(DocumentChunk::class);
    }
}

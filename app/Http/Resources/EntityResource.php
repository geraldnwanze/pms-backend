<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Generic serializer. Domain columns already use the camelCase names the
 * frontend expects, and models hide framework fields (password, timestamps),
 * so a straight array cast is the right representation.
 */
class EntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}

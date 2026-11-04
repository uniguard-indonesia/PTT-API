<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserPositionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $hasValidPosition = !empty($this->position_id) && (int) $this->position_id > 0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'photo' => $this->photo,
            'company_id' => $this->company_id,
            'company_name' => $this->company_name ?? optional($this->company)->name,
            'timezone_name' => $this->timezone_name ?? optional(optional($this->company)->timezone)->name ?? 'UTC',
            'level_id' => $this->level_id,
            'position_id' => $hasValidPosition ? (int) $this->position_id : null,
            'latitude' => $hasValidPosition ? ($this->latitude ?? optional($this->position)->latitude) : null,
            'longitude' => $hasValidPosition ? ($this->longitude ?? optional($this->position)->longitude) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'position' => ($hasValidPosition && $this->position) ? new PositionResource($this->position) : null,
        ];
    }
}

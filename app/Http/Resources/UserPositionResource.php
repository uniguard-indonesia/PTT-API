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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'photo' => $this->photo,
            'company_id' => $this->company_id,
            'company_name' => $this->company_name ?? optional($this->company)->name,
            'timezone_name' => $this->timezone_name ?? optional(optional($this->company)->timezone)->name ?? 'UTC',
            'level_id' => $this->level_id,
            'position_id' => $this->position_id,
            'latitude' => $this->latitude ?? optional($this->position)->latitude,
            'longitude' => $this->longitude ?? optional($this->position)->longitude,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'position' => $this->position ? new PositionResource($this->position) : null,
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Models\Level;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $actor */
        $actor = $this->user();

        if ($actor && $actor->isSuperAdmin()) {
            $companyRule = ['required', 'integer', 'exists:companies,id'];
            $allowedLevels = array_unique(array_merge(
                [User::LEVEL_SUPER_ADMIN],
                Level::assignableBy($actor)->pluck('id')->map(function ($id) {
                    return (int) $id;
                })->all()
            ));
        } else {
            $companyRule = ['required', 'integer', Rule::in([$actor ? (int) $actor->company_id : null])];
            $allowedLevels = Level::assignableBy($actor)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'company_id' => $companyRule,
            'level_id' => ['required', 'integer', Rule::in($allowedLevels)],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'certificate' => ['nullable', 'string'],
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  Validator  $validator
     * @return void
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        if ($this->wantsJson() || $this->ajax()) {
            throw new HttpResponseException(
                response()->json([
                    'status' => 'error',
                    'message' => 'The given data was invalid.',
                    'fields' => $validator->errors(),
                    'errors' => $validator->errors(),
                ], 422)
            );
        }

        parent::failedValidation($validator);
    }
}

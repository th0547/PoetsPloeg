<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Auth\LoginRequest;

/** Inloggen in de app: dezelfde controle en rate-limit als de website, plus een apparaatnaam voor het token. */
class AppLoginRequest extends LoginRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:wp_users,ID',
            'product_id' => 'required|exists:wp_posts,ID',
            'variation_id' => 'nullable|exists:wp_posts,ID',
            'quantity' => 'required|integer|min=1',

        ];
    }
}

<?php

namespace App\Http\Requests\V1;

use App\Support\ValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic validation for the store/update endpoints of every v1 resource.
 * The resource key comes from the route segment; rules from
 * App\Support\ValidationRules. Authorization is handled by policies in the
 * controller, so this request only validates.
 */
class EntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entity = $this->route('resource');
        $creating = $this->isMethod('post');

        return ValidationRules::rulesFor($entity, $creating);
    }
}

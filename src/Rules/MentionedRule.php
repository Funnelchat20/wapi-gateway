<?php

namespace Funnelchat\WapiGateway\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\Request;

class MentionedRule implements Rule
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function passes($attribute, $value): bool
    {
        if (str_contains($this->request->input('phone'), '-group')) {
            if (is_bool($value)) {
                return $value === true;
            }
            return is_array($value);
        }
        return false;
    }

    public function message(): string
    {
        if (str_contains($this->request->input('phone'), '-group')) {
            return 'The :attribute must be an array or true.';
        }
        return 'The :attribute is only allowed if phone contains "-group".';
    }
}

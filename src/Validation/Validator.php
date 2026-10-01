<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exceptions\ValidationException;

/**
 * Small, dependency-free validator.
 *
 * Usage:
 *   $data = Validator::make($request->all(), [
 *       'email' => 'required|email',
 *       'password' => 'required|min:8',
 *   ])->validate();
 *
 * Throws ValidationException (-> HTTP 422) on failure, otherwise returns
 * the validated subset of the input.
 */
final class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];

    private function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function validate(): array
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $isRequired = in_array('required', $rules, true);

            if ($value === null || $value === '') {
                if ($isRequired) {
                    $this->addError($field, "The {$field} field is required.");
                }
                continue;
            }

            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }

        if (!empty($this->errors)) {
            throw new ValidationException($this->errors);
        }

        return array_intersect_key($this->data, $this->rules);
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $param = null;
        if (str_contains($rule, ':')) {
            [$rule, $param] = explode(':', $rule, 2);
        }

        switch ($rule) {
            case 'required':
                // handled above
                break;

            case 'string':
                if (!is_string($value)) {
                    $this->addError($field, "The {$field} must be a string.");
                }
                break;

            case 'email':
                if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "The {$field} must be a valid email address.");
                }
                break;

            case 'min':
                if (is_string($value) && mb_strlen($value) < (int) $param) {
                    $this->addError($field, "The {$field} must be at least {$param} characters.");
                } elseif (is_numeric($value) && $value < (int) $param) {
                    $this->addError($field, "The {$field} must be at least {$param}.");
                }
                break;

            case 'max':
                if (is_string($value) && mb_strlen($value) > (int) $param) {
                    $this->addError($field, "The {$field} may not be greater than {$param} characters.");
                } elseif (is_numeric($value) && $value > (int) $param) {
                    $this->addError($field, "The {$field} may not be greater than {$param}.");
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $param);
                if (!in_array((string) $value, $allowed, true)) {
                    $this->addError($field, "The {$field} must be one of: " . implode(', ', $allowed) . '.');
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    $this->addError($field, "The {$field} must be an array.");
                }
                break;

            case 'password':
                // Defined validation policy: minimum 8 chars, at least one letter and one number.
                if (!is_string($value) || !preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $value)) {
                    $this->addError(
                        $field,
                        "The {$field} must be at least 8 characters and contain at least one letter and one number."
                    );
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}

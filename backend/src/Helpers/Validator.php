<?php
// backend/src/Helpers/Validator.php

namespace App\Helpers;

class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(array $fields): self
    {
        foreach ($fields as $field) {
            if (!isset($this->data[$field]) || trim((string)$this->data[$field]) === '') {
                $this->errors[$field][] = "Field '{$field}' is required.";
            }
        }
        return $this;
    }

    public function email(string $field): self
    {
        if (isset($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "Field '{$field}' must be a valid email address.";
        }
        return $this;
    }

    public function in(string $field, array $allowedValues): self
    {
        if (isset($this->data[$field]) && !in_array($this->data[$field], $allowedValues, true)) {
            $allowed = implode(', ', $allowedValues);
            $this->errors[$field][] = "Field '{$field}' must be one of: {$allowed}.";
        }
        return $this;
    }

    public function minLength(string $field, int $min): self
    {
        if (isset($this->data[$field]) && mb_strlen((string)$this->data[$field]) < $min) {
            $this->errors[$field][] = "Field '{$field}' must be at least {$min} characters long.";
        }
        return $this;
    }

    public function numeric(string $field): self
    {
        if (isset($this->data[$field]) && !is_numeric($this->data[$field])) {
            $this->errors[$field][] = "Field '{$field}' must be a number.";
        }
        return $this;
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

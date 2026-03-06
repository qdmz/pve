<?php
class Validator {
    private $errors = [];
    private $data = [];
    private $rules = [];
    
    public function __construct($data, $rules) {
        $this->data = $data;
        $this->rules = $rules;
    }
    
    public function validate() {
        foreach ($this->rules as $field => $rule) {
            $value = $this->data[$field] ?? null;
            
            if (is_string($rule)) {
                $this->applyRule($field, $value, $rule);
            } elseif (is_array($rule)) {
                foreach ($rule as $r) {
                    $this->applyRule($field, $value, $r);
                }
            }
        }
        
        return empty($this->errors);
    }
    
    private function applyRule($field, $value, $rule) {
        $parts = explode(':', $rule);
        $ruleName = $parts[0];
        $ruleValue = $parts[1] ?? null;
        
        switch ($ruleName) {
            case 'required':
                $this->validateRequired($field, $value);
                break;
            case 'email':
                $this->validateEmail($field, $value);
                break;
            case 'min':
                $this->validateMin($field, $value, $ruleValue);
                break;
            case 'max':
                $this->validateMax($field, $value, $ruleValue);
                break;
            case 'between':
                $this->validateBetween($field, $value, $ruleValue);
                break;
            case 'numeric':
                $this->validateNumeric($field, $value);
                break;
            case 'integer':
                $this->validateInteger($field, $value);
                break;
            case 'string':
                $this->validateString($field, $value);
                break;
            case 'array':
                $this->validateArray($field, $value);
                break;
            case 'boolean':
                $this->validateBoolean($field, $value);
                break;
            case 'in':
                $this->validateIn($field, $value, $ruleValue);
                break;
            case 'regex':
                $this->validateRegex($field, $value, $ruleValue);
                break;
            case 'url':
                $this->validateUrl($field, $value);
                break;
            case 'password':
                $this->validatePassword($field, $value);
                break;
            case 'username':
                $this->validateUsername($field, $value);
                break;
            case 'confirmed':
                $this->validateConfirmed($field, $value, $ruleValue);
                break;
        }
    }
    
    private function validateRequired($field, $value) {
        if ($value === null || $value === '') {
            $this->errors[$field][] = "$field 是必填项";
        }
    }
    
    private function validateEmail($field, $value) {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "$field 必须是有效的邮箱地址";
        }
    }
    
    private function validateMin($field, $value, $min) {
        if (is_numeric($value) && $value < $min) {
            $this->errors[$field][] = "$field 必须大于或等于 $min";
        } elseif (is_string($value) && strlen($value) < $min) {
            $this->errors[$field][] = "$field 长度必须大于或等于 $min";
        }
    }
    
    private function validateMax($field, $value, $max) {
        if (is_numeric($value) && $value > $max) {
            $this->errors[$field][] = "$field 必须小于或等于 $max";
        } elseif (is_string($value) && strlen($value) > $max) {
            $this->errors[$field][] = "$field 长度必须小于或等于 $max";
        }
    }
    
    private function validateBetween($field, $value, $range) {
        $parts = explode(',', $range);
        $min = $parts[0] ?? 0;
        $max = $parts[1] ?? PHP_INT_MAX;
        
        if (is_numeric($value)) {
            if ($value < $min || $value > $max) {
                $this->errors[$field][] = "$field 必须在 $min 和 $max 之间";
            }
        } elseif (is_string($value)) {
            if (strlen($value) < $min || strlen($value) > $max) {
                $this->errors[$field][] = "$field 长度必须在 $min 和 $max 之间";
            }
        }
    }
    
    private function validateNumeric($field, $value) {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            $this->errors[$field][] = "$field 必须是数字";
        }
    }
    
    private function validateInteger($field, $value) {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_INT)) {
            $this->errors[$field][] = "$field 必须是整数";
        }
    }
    
    private function validateString($field, $value) {
        if ($value !== null && !is_string($value)) {
            $this->errors[$field][] = "$field 必须是字符串";
        }
    }
    
    private function validateArray($field, $value) {
        if ($value !== null && !is_array($value)) {
            $this->errors[$field][] = "$field 必须是数组";
        }
    }
    
    private function validateBoolean($field, $value) {
        if ($value !== null && !is_bool($value)) {
            $this->errors[$field][] = "$field 必须是布尔值";
        }
    }
    
    private function validateIn($field, $value, $allowedValues) {
        $values = explode(',', $allowedValues);
        if ($value !== null && !in_array($value, $values)) {
            $this->errors[$field][] = "$field 必须是以下值之一: " . implode(', ', $values);
        }
    }
    
    private function validateRegex($field, $value, $pattern) {
        if ($value !== null && $value !== '' && !preg_match($pattern, $value)) {
            $this->errors[$field][] = "$field 格式不正确";
        }
    }
    
    private function validateUrl($field, $value) {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->errors[$field][] = "$field 必须是有效的URL";
        }
    }
    
    private function validatePassword($field, $value) {
        if ($value !== null && $value !== '') {
            if (strlen($value) < 8) {
                $this->errors[$field][] = "$field 长度至少8位";
            }
            if (!preg_match('/[A-Z]/', $value)) {
                $this->errors[$field][] = "$field 必须包含至少一个大写字母";
            }
            if (!preg_match('/[a-z]/', $value)) {
                $this->errors[$field][] = "$field 必须包含至少一个小写字母";
            }
            if (!preg_match('/[0-9]/', $value)) {
                $this->errors[$field][] = "$field 必须包含至少一个数字";
            }
        }
    }
    
    private function validateUsername($field, $value) {
        if ($value !== null && $value !== '') {
            if (strlen($value) < 3) {
                $this->errors[$field][] = "$field 长度至少3位";
            }
            if (strlen($value) > 50) {
                $this->errors[$field][] = "$field 长度不能超过50位";
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
                $this->errors[$field][] = "$field 只能包含字母、数字和下划线";
            }
        }
    }
    
    private function validateConfirmed($field, $value, $confirmField) {
        $confirmValue = $this->data[$confirmField] ?? null;
        if ($value !== $confirmValue) {
            $this->errors[$field][] = "$field 与确认字段不匹配";
        }
    }
    
    public function getErrors() {
        return $this->errors;
    }
    
    public function getFirstError() {
        foreach ($this->errors as $field => $errors) {
            return $errors[0];
        }
        return null;
    }
    
    public function getErrorsByField($field) {
        return $this->errors[$field] ?? [];
    }
    
    public function hasErrors() {
        return !empty($this->errors);
    }
    
    public function getValidatedData() {
        $validatedData = [];
        
        foreach ($this->data as $key => $value) {
            if (!isset($this->errors[$key])) {
                $validatedData[$key] = $this->sanitize($key, $value);
            }
        }
        
        return $validatedData;
    }
    
    private function sanitize($field, $value) {
        if (is_string($value)) {
            return $this->sanitizeString($value);
        } elseif (is_array($value)) {
            return $this->sanitizeArray($value);
        } elseif (is_numeric($value)) {
            return $this->sanitizeNumber($value);
        } elseif (is_bool($value)) {
            return $value;
        }
        
        return $value;
    }
    
    private function sanitizeString($value) {
        $value = trim($value);
        $value = stripslashes($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return $value;
    }
    
    private function sanitizeArray($array) {
        $sanitized = [];
        foreach ($array as $key => $value) {
            $sanitized[$key] = $this->sanitize($key, $value);
        }
        return $sanitized;
    }
    
    private function sanitizeNumber($value) {
        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }
    
    public static function make($data, $rules) {
        $validator = new self($data, $rules);
        
        if (!$validator->validate()) {
            return [
                'success' => false,
                'errors' => $validator->getErrors()
            ];
        }
        
        return [
            'success' => true,
            'data' => $validator->getValidatedData()
        ];
    }
}
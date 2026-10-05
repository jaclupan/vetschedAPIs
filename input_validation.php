<?php

function vetschedReadJsonRequest(): ?array
{
    $contentLength = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);
    if ($contentLength > 16384) {
        return null;
    }

    $raw = file_get_contents("php://input");
    if ($raw === false || strlen($raw) > 16384) {
        return null;
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return null;
    }
    return is_array($data) ? $data : null;
}

function vetschedNormalizeEmail($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $email = strtolower(trim($value));
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)
        ? $email
        : null;
}

function vetschedNormalizeName($value): ?string
{
    if (!is_string($value) || preg_match('/[\p{Cc}]/u', $value)) {
        return null;
    }
    $name = preg_replace('/\s+/u', ' ', trim($value));
    if ($name === null) {
        return null;
    }
    $length = preg_match_all('/./us', $name);
    return $length !== false && $length >= 1 && $length <= 80 ? $name : null;
}

function vetschedNormalizeStudentId($value): ?string
{
    if (!is_string($value) ||
        !preg_match('/^(?:[0-9]{9,12}|[0-9]{2}-[0-9]{4}-[0-9]{3,6})$/D', trim($value))) {
        return null;
    }
    return str_replace("-", "", trim($value));
}

function vetschedIsValidPassword($value): bool
{
    if (!is_string($value) || strlen($value) > 512) {
        return false;
    }
    $length = preg_match_all('/./us', $value);
    return $length !== false && $length >= 10 && $length <= 128;
}

function vetschedIsValidOtp($value): bool
{
    return is_string($value) && preg_match('/^[0-9]{6}$/D', $value) === 1;
}

function vetschedHasAcceptedTerms($value): bool
{
    return $value === true || $value === "true" || $value === 1 || $value === "1";
}
